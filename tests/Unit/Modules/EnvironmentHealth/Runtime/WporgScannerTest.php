<?php
/**
 * Unit tests for the throttled wordpress.org freshness scanner.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\WporgScanner;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Covers the PRD-named failure paths — a `WP_Error` transport failure, a
 * non-200 response, and a premium plugin wp.org has never heard of — plus the
 * throttling and caching behaviour that keeps a large site from firing dozens
 * of blocking requests at once.
 */
final class WporgScannerTest extends TestCase {

	/**
	 * In-memory option store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Queued responses, consumed one per `wp_remote_get()` call.
	 *
	 * @var array<int, mixed>
	 */
	private array $responses = [];

	/**
	 * URLs requested, in order.
	 *
	 * @var array<int, string>
	 */
	private array $requested = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = [];
		$this->responses = [];
		$this->requested = [];

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'home_url' )->alias( static fn ( $path = '/' ) => 'https://example.test' . (string) $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return (string) $url . '?' . http_build_query( is_array( $args ) ? $args : [] );
			}
		);
		Functions\when( 'get_option' )->alias(
			fn ( $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				$this->requested[] = (string) $url;
				return array_shift( $this->responses ) ?? $this->ok_response( '2026-08-01 10:00am GMT' );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a successful wp.org response body.
	 *
	 * @param string $last_updated Value for the response's `last_updated` field.
	 * @return array<string, mixed>
	 */
	private function ok_response( string $last_updated ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) json_encode( [ 'last_updated' => $last_updated ] ),
		];
	}

	public function test_a_found_plugin_records_its_last_updated_timestamp(): void {
		$this->responses[] = $this->ok_response( '2026-08-01 10:00am GMT' );

		$entry = ( new WporgScanner() )->fetch( 'akismet' );

		$this->assertSame( WporgScanner::STATE_FOUND, $entry['state'] );
		$this->assertSame( strtotime( '2026-08-01 10:00am GMT' ), $entry['last_updated'] );
		$this->assertSame( '', $entry['message'] );
	}

	public function test_a_transport_failure_is_recorded_as_an_error_not_a_pass(): void {
		$this->responses[] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$entry = ( new WporgScanner() )->fetch( 'slow-plugin' );

		$this->assertSame( WporgScanner::STATE_ERROR, $entry['state'] );
		$this->assertNull( $entry['last_updated'] );
		$this->assertStringContainsString( 'timed out', $entry['message'] );
	}

	public function test_a_server_error_response_is_recorded_as_an_error(): void {
		$this->responses[] = [
			'response' => [ 'code' => 503 ],
			'body'     => 'Service Unavailable',
		];

		$entry = ( new WporgScanner() )->fetch( 'akismet' );

		$this->assertSame( WporgScanner::STATE_ERROR, $entry['state'] );
		$this->assertSame( 'http_503', $entry['message'] );
	}

	public function test_a_premium_plugin_absent_from_wporg_is_not_on_wporg(): void {
		// wp.org answers 404 for an unknown slug.
		$this->responses[] = [
			'response' => [ 'code' => 404 ],
			'body'     => '',
		];
		$this->assertSame( WporgScanner::STATE_NOT_ON_WPORG, ( new WporgScanner() )->fetch( 'acme-pro' )['state'] );

		// Some mirrors instead answer 200 with an error object carrying no date.
		$this->responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"error":"Plugin not found."}',
		];
		$this->assertSame( WporgScanner::STATE_NOT_ON_WPORG, ( new WporgScanner() )->fetch( 'acme-pro' )['state'] );
	}

	public function test_a_malformed_body_is_an_error(): void {
		$this->responses[] = [
			'response' => [ 'code' => 200 ],
			'body'     => '<html>nope</html>',
		];

		$this->assertSame( WporgScanner::STATE_ERROR, ( new WporgScanner() )->fetch( 'akismet' )['state'] );
	}

	public function test_scan_never_exceeds_its_budget(): void {
		$scanner = new WporgScanner();
		$slugs   = [ 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ];

		$result = $scanner->scan( $slugs, 3 );

		$this->assertSame( 3, $result['checked'] );
		$this->assertSame( 5, $result['pending'] );
		$this->assertFalse( $result['complete'] );
		$this->assertCount( 3, $this->requested, 'A 60-plugin site must not fire 60 requests in one pass.' );
	}

	public function test_scan_resumes_where_the_previous_batch_stopped(): void {
		$scanner = new WporgScanner();
		$slugs   = [ 'a', 'b', 'c', 'd' ];

		$scanner->scan( $slugs, 2 );
		$second = $scanner->scan( $slugs, 2 );

		$this->assertSame( 2, $second['checked'] );
		$this->assertTrue( $second['complete'] );
		$this->assertCount( 4, $this->requested );
		$this->assertSame( [ 'a', 'b', 'c', 'd' ], array_keys( $scanner->results() ) );
	}

	public function test_fresh_results_are_not_re_requested_inside_the_cache_window(): void {
		$scanner = new WporgScanner();

		$scanner->scan( [ 'akismet' ], 5 );
		$this->assertCount( 1, $this->requested );

		$again = $scanner->scan( [ 'akismet' ], 5 );

		$this->assertSame( 0, $again['checked'] );
		$this->assertTrue( $again['complete'] );
		$this->assertCount( 1, $this->requested, 'A cached lookup must not hit the network again.' );
	}

	public function test_a_stale_entry_is_re_requested(): void {
		$scanner = new WporgScanner();

		$this->options[ WporgScanner::CACHE_OPTION ] = [
			'akismet' => [
				'state'        => WporgScanner::STATE_FOUND,
				'last_updated' => time(),
				'checked_at'   => time() - WporgScanner::CACHE_TTL_SEC - 1,
				'message'      => '',
			],
		];

		$result = $scanner->scan( [ 'akismet' ], 5 );

		$this->assertSame( 1, $result['checked'] );
	}

	public function test_an_error_entry_is_retried_far_sooner_than_a_success(): void {
		$scanner = new WporgScanner();

		$this->options[ WporgScanner::CACHE_OPTION ] = [
			'akismet' => [
				'state'        => WporgScanner::STATE_ERROR,
				'last_updated' => null,
				'checked_at'   => time() - WporgScanner::ERROR_TTL_SEC - 1,
				'message'      => 'http_503',
			],
		];

		$this->assertSame( 1, $scanner->pending_count( [ 'akismet' ] ) );
	}

	public function test_scan_forgets_slugs_it_was_not_asked_about(): void {
		$scanner = new WporgScanner();

		$this->options[ WporgScanner::CACHE_OPTION ] = [
			'removed-plugin' => [
				'state'        => WporgScanner::STATE_FOUND,
				'last_updated' => time(),
				'checked_at'   => time(),
				'message'      => '',
			],
		];

		$scanner->scan( [ 'akismet' ], 5 );

		$this->assertArrayNotHasKey( 'removed-plugin', $scanner->results() );
		$this->assertArrayHasKey( 'akismet', $scanner->results() );
	}

	public function test_forget_all_discards_every_cached_row(): void {
		$scanner = new WporgScanner();
		$scanner->scan( [ 'akismet' ], 1 );
		$this->assertNotSame( [], $scanner->results() );

		$scanner->forget_all();

		$this->assertSame( [], $scanner->results() );
	}

	public function test_results_drops_rows_written_by_older_code(): void {
		$this->options[ WporgScanner::CACHE_OPTION ] = [
			'good' => [
				'state'      => WporgScanner::STATE_FOUND,
				'checked_at' => 123,
			],
			'bad'  => [ 'nonsense' => true ],
			7      => 'not-an-array',
		];

		$results = ( new WporgScanner() )->results();

		$this->assertSame( [ 'good' ], array_keys( $results ) );
		$this->assertNull( $results['good']['last_updated'] );
	}
}
