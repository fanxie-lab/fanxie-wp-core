<?php
/**
 * Unit tests for StatusInspector.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\StatusInspector;
use FanxieLab\WPCore\Modules\Hardening\UploadsProtector;
use PHPUnit\Framework\TestCase;

/**
 * Covers snapshot shape, caching, and invalidation by running the inspector
 * against a real `UploadsProtector` that's pointed at a temp dir. The probe
 * HTTP calls are stubbed via Brain Monkey so we never reach the network.
 */
final class StatusInspectorTest extends TestCase {

	/**
	 * Transient store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Temporary directory substituted for the uploads basedir.
	 */
	private string $uploads_dir = '';

	/**
	 * Status code returned from the stubbed `wp_remote_retrieve_response_code()`.
	 */
	private int $probe_status = 404;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias( static fn ( $v ) => rtrim( (string) $v, '/' ) . '/' );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );

		$this->transients = [];
		Functions\when( 'get_transient' )->alias(
			fn ( $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		$this->uploads_dir = sys_get_temp_dir() . '/fanxie-inspector-' . uniqid( '', true );
		mkdir( $this->uploads_dir, 0777, true );

		Functions\when( 'wp_upload_dir' )->alias(
			fn () => [
				'basedir' => $this->uploads_dir,
				'baseurl' => 'https://example.test/wp-content/uploads',
			]
		);
		Functions\when( 'home_url' )->alias( static fn ( $path = '/' ) => 'https://example.test' . (string) $path );
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn () => $this->probe_status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( $r ) => is_array( $r ) && isset( $r['headers'] ) && is_array( $r['headers'] ) ? $r['headers'] : []
		);
		Functions\when( 'wp_generate_password' )->alias( static fn ( int $l = 12 ) => substr( str_repeat( 'x', $l ), 0, $l ) );

		Filters\expectApplied( 'fanxie_wp_core/hardening/uploads_dir' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
	}

	protected function tearDown(): void {
		if ( is_dir( $this->uploads_dir ) ) {
			foreach ( glob( $this->uploads_dir . '/*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
			foreach ( glob( $this->uploads_dir . '/.*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
			rmdir( $this->uploads_dir );
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_inspector(): StatusInspector {
		return new StatusInspector( new UploadsProtector( [] ) );
	}

	public function test_snapshot_returns_stable_shape(): void {
		$inspector = $this->make_inspector();

		$snapshot = $inspector->snapshot();

		foreach (
			[
				'server_type',
				'x_powered_by_present',
				'disallow_file_edit_defined',
				'disallow_file_edit_value',
				'uploads_dir_listable',
				'uploads_php_executable',
				'uploads_htaccess_exists',
				'uploads_index_exists',
				'readme_blocked',
				'license_blocked',
				'application_passwords_count',
				'application_passwords_users',
				'probed_at',
			] as $key
		) {
			$this->assertArrayHasKey( $key, $snapshot, "snapshot missing `{$key}`" );
		}

		$this->assertSame( 'apache', $snapshot['server_type'] );
		$this->assertIsInt( $snapshot['probed_at'] );
		$this->assertFalse( $snapshot['disallow_file_edit_defined'] );
		// 404 from `probe_path_blocked` → blocked.
		$this->assertTrue( $snapshot['readme_blocked'] );
		$this->assertTrue( $snapshot['license_blocked'] );
	}

	public function test_snapshot_marks_readme_unblocked_when_probe_returns_200(): void {
		$this->probe_status = 200;

		$snapshot = $this->make_inspector()->snapshot( true );

		$this->assertFalse( $snapshot['readme_blocked'] );
		$this->assertFalse( $snapshot['license_blocked'] );
	}

	public function test_snapshot_marks_readme_inconclusive_on_transport_error(): void {
		// Container can't reach its own public URL — wp_remote_get returns WP_Error.
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'timeout', 'unreachable' ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );

		$snapshot = $this->make_inspector()->snapshot( true );

		$this->assertNull( $snapshot['readme_blocked'] );
		$this->assertNull( $snapshot['license_blocked'] );
	}

	public function test_snapshot_detects_x_powered_by_header_case_insensitively(): void {
		// Give `wp_remote_get` a response with the header lurking under a mixed-case key.
		Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '',
				'headers'  => [ 'X-Powered-By' => 'PHP/8.3' ],
			]
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( $r ) => is_array( $r ) && isset( $r['headers'] ) && is_array( $r['headers'] ) ? $r['headers'] : []
		);

		$snapshot = $this->make_inspector()->snapshot( true );

		$this->assertTrue( $snapshot['x_powered_by_present'] );
	}

	public function test_snapshot_reports_x_powered_by_absent_when_header_missing(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '',
				'headers'  => [ 'Content-Type' => 'text/html' ],
			]
		);

		$snapshot = $this->make_inspector()->snapshot( true );

		$this->assertFalse( $snapshot['x_powered_by_present'] );
	}

	public function test_snapshot_reports_existing_protection_files(): void {
		touch( $this->uploads_dir . '/index.php' );
		touch( $this->uploads_dir . '/.htaccess' );

		$snapshot = $this->make_inspector()->snapshot();

		$this->assertTrue( $snapshot['uploads_index_exists'] );
		$this->assertTrue( $snapshot['uploads_htaccess_exists'] );
	}

	public function test_snapshot_is_cached_between_calls(): void {
		$inspector = $this->make_inspector();

		$first    = $inspector->snapshot();
		$this->assertArrayHasKey( StatusInspector::CACHE_KEY, $this->transients );

		$second = $inspector->snapshot();
		$this->assertSame( $first, $second );
	}

	public function test_force_bypasses_cache(): void {
		$inspector = $this->make_inspector();

		// Seed the cache with a sentinel.
		$this->transients[ StatusInspector::CACHE_KEY ] = [
			'server_type'                 => 'STALE',
			'x_powered_by_present'        => false,
			'disallow_file_edit_defined'  => false,
			'disallow_file_edit_value'    => false,
			'uploads_dir_listable'        => null,
			'uploads_php_executable'      => null,
			'uploads_htaccess_exists'     => false,
			'uploads_index_exists'        => false,
			'readme_blocked'              => true,
			'license_blocked'             => true,
			'application_passwords_count' => 0,
			'application_passwords_users' => [],
			'probed_at'                   => 1,
		];

		$cached = $inspector->snapshot();
		$this->assertSame( 'STALE', $cached['server_type'] );

		$fresh = $inspector->snapshot( true );
		$this->assertSame( 'apache', $fresh['server_type'] );
	}

	public function test_invalidate_cache_clears_stored_snapshot(): void {
		$inspector = $this->make_inspector();

		$inspector->snapshot();
		$this->assertArrayHasKey( StatusInspector::CACHE_KEY, $this->transients );

		$inspector->invalidate_cache();
		$this->assertArrayNotHasKey( StatusInspector::CACHE_KEY, $this->transients );
	}
}
