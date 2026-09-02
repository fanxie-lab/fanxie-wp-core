<?php
/**
 * Shared unit-test harness for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use PHPUnit\Framework\TestCase;
use WP_Theme;
use wpdb;

/**
 * Stands up an in-memory WordPress for the module's aggregate classes.
 *
 * `StatusInspector` and `AjaxController` reach across every collaborator, so
 * testing them means stubbing options, transients, the plugin/theme registry,
 * the cron array, and the database handle at once. Doing that in one place
 * keeps the individual test files about behaviour rather than about scaffolding.
 *
 * The environment it builds is deliberately *healthy*: a current WordPress,
 * one active plugin, one theme, an empty cron queue. Each test perturbs the
 * one thing it is about.
 */
abstract class EnvironmentHealthTestCase extends TestCase {

	/**
	 * Fixed "now" every assertion is written against.
	 */
	protected const NOW = 1756800000;

	/**
	 * Option store.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = [];

	/**
	 * Transient store.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transients = [];

	/**
	 * Site transient store.
	 *
	 * @var array<string, mixed>
	 */
	protected array $site_transients = [];

	/**
	 * `get_plugins()` return value.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $installed_plugins = [];

	/**
	 * `wp_get_themes()` return value.
	 *
	 * @var array<string, WP_Theme>
	 */
	protected array $themes = [];

	/**
	 * Cron array returned by `_get_cron_array()`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $cron = [];

	/**
	 * Scheduled events: composite key => timestamp.
	 *
	 * @var array<string, int>
	 */
	protected array $scheduled = [];

	/**
	 * Queued `wp_remote_get()` responses.
	 *
	 * @var array<int, mixed>
	 */
	protected array $responses = [];

	/**
	 * URLs requested via `wp_remote_get()`, in order.
	 *
	 * @var array<int, string>
	 */
	protected array $requested = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( [ 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ] as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		$this->options = [
			'home'           => 'https://example.test',
			'siteurl'        => 'https://example.test',
			'date_format'    => 'Y-m-d',
			'active_plugins' => [ 'akismet/akismet.php' ],
		];
		$this->transients        = [];
		$this->site_transients   = [];
		$this->installed_plugins = [ 'akismet/akismet.php' => [ 'Name' => 'Akismet' ] ];
		$this->themes            = [ 'acme-theme' => new WP_Theme( 'acme-theme', 'Acme Theme' ) ];
		$this->cron              = [];
		$this->scheduled         = [];
		$this->responses         = [];
		$this->requested         = [];

		$GLOBALS['wpdb']              = new wpdb();
		$GLOBALS['wpdb']->server_info = '8.4.3';

		$this->stub_translation();
		$this->stub_storage();
		$this->stub_site();
		$this->stub_registry();
		$this->stub_cron();
		$this->stub_http();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Translation + sanitisation helpers.
	 */
	private function stub_translation(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn ( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ?? '' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
	}

	/**
	 * Options, transients, and site transients.
	 */
	private function stub_storage(): void {
		Functions\when( 'get_option' )->alias(
			fn ( $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$changed               = ( $this->options[ $key ] ?? null ) !== $value;
				$this->options[ $key ] = $value;
				return $changed;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( [] );
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
		Functions\when( 'get_site_transient' )->alias(
			fn ( $key ) => array_key_exists( $key, $this->site_transients ) ? $this->site_transients[ $key ] : false
		);
	}

	/**
	 * URL + date helpers.
	 */
	private function stub_site(): void {
		Functions\when( 'home_url' )->alias( static fn ( $path = '/' ) => 'https://example.test' . (string) $path );
		Functions\when( 'site_url' )->alias( static fn ( $path = '' ) => 'https://example.test/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-admin/' . (string) $path );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, (int) $component ) );
		Functions\when( 'wp_normalize_path' )->alias( static fn ( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $timestamp = null ) => gmdate( (string) $format, (int) $timestamp ) );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args, $url ) => (string) $url . '?' . http_build_query( is_array( $args ) ? $args : [] )
		);
	}

	/**
	 * Plugin + theme registries.
	 */
	private function stub_registry(): void {
		Functions\when( 'get_plugins' )->alias( fn () => $this->installed_plugins );
		Functions\when( 'wp_get_themes' )->alias( fn () => $this->themes );
		Functions\when( 'wp_get_theme' )->alias(
			fn () => $this->themes['acme-theme'] ?? new WP_Theme( 'acme-theme', 'Acme Theme' )
		);
	}

	/**
	 * Cron array + scheduler.
	 */
	private function stub_cron(): void {
		Functions\when( '_get_cron_array' )->alias( fn () => $this->cron );
		Functions\when( 'wp_next_scheduled' )->alias(
			fn ( $hook, $args = [] ) => $this->scheduled[ $this->cron_key( (string) $hook, $args ) ] ?? false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook, $args = [] ) {
				$this->scheduled[ $this->cron_key( (string) $hook, $args ) ] = (int) $timestamp;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->scheduled[ $this->cron_key( (string) $hook, $args ) ] = (int) $timestamp;
				return true;
			}
		);
	}

	/**
	 * HTTP transport.
	 */
	private function stub_http(): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				$this->requested[] = (string) $url;
				return array_shift( $this->responses ) ?? [
					'response' => [ 'code' => 200 ],
					'body'     => (string) json_encode( [ 'last_updated' => '2026-08-01 10:00am GMT' ] ),
				];
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''
		);
	}

	/**
	 * Composite scheduler key for a hook + args pair.
	 *
	 * @param string            $hook Hook name.
	 * @param array<int, mixed> $args Event args.
	 */
	protected function cron_key( string $hook, array $args = [] ): string {
		return $hook . '|' . implode( ',', array_map( 'strval', $args ) );
	}

	/**
	 * Locate a serialised check by id in a report payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $id     Stable check id.
	 * @return array<string, mixed>
	 */
	protected function check_in( array $report, string $id ): array {
		foreach ( $report['checks'] as $check ) {
			if ( is_array( $check ) && ( $check['id'] ?? '' ) === $id ) {
				return $check;
			}
		}

		$this->fail( "Report has no check with id `{$id}`." );
	}

	/**
	 * Every check id present in a report payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<int, string>
	 */
	protected function check_ids( array $report ): array {
		return array_map(
			static fn ( $check ): string => is_array( $check ) ? (string) ( $check['id'] ?? '' ) : '',
			$report['checks']
		);
	}

	/**
	 * Assert every check in a payload obeys the closed enums.
	 *
	 * @param array<string, mixed> $report Report payload.
	 */
	protected function assertContractHolds( array $report ): void {
		$this->assertSame( [ 'generated_at', 'cached_until', 'counts', 'checks' ], array_keys( $report ) );

		foreach ( $report['checks'] as $check ) {
			$this->assertIsArray( $check );
			$this->assertContains( $check['status'], HealthCheck::ALL_STATUSES );
			$this->assertContains( $check['group'], HealthCheck::ALL_GROUPS );
			$this->assertIsString( $check['id'] );
			$this->assertIsArray( $check['remediation'] );
			$this->assertIsArray( $check['meta'] );

			// `meta` is a map of scalars, nulls, and flat lists of strings —
			// nothing deeper. Names are lists rather than joined strings so a
			// name containing a comma survives the trip to the client.
			foreach ( $check['meta'] as $key => $value ) {
				$this->assertIsString( $key );

				if ( is_array( $value ) ) {
					$this->assertSame( array_values( $value ), $value, "meta[{$key}] must be a list." );

					foreach ( $value as $item ) {
						$this->assertIsString( $item, "meta[{$key}] must hold strings." );
					}

					continue;
				}

				$this->assertTrue(
					null === $value || is_scalar( $value ),
					"meta[{$key}] must be a scalar, null, or a list of strings."
				);
			}
		}
	}
}
