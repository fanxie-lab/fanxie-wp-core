<?php
/**
 * Unit tests for the version + transport checks.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\VersionInspector;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * Exercises the four checks whose inputs can be controlled from a unit test —
 * WordPress version, database version, TLS certificate, and HTTPS — plus the
 * shape guarantees the PHP check shares with them.
 */
final class VersionInspectorTest extends TestCase {

	/**
	 * Fixed "now" every assertion is written against.
	 */
	private const NOW = 1756800000;

	/**
	 * Option store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Transient store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Site transient store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $site_transients = [];

	/**
	 * Installed WordPress version reported by `get_bloginfo( 'version' )`.
	 */
	private string $wp_version = '6.9';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}

		$this->options = [
			'home'        => 'https://example.test',
			'siteurl'     => 'https://example.test',
			'date_format' => 'Y-m-d',
		];
		$this->transients      = [];
		$this->site_transients = [];
		$this->wp_version      = '6.9';

		$GLOBALS['wpdb']              = new wpdb();
		$GLOBALS['wpdb']->server_info = '8.4.3';

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-admin/' . (string) $path );
		Functions\when( 'home_url' )->alias( static fn ( $path = '/' ) => 'https://example.test' . (string) $path );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, (int) $component ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $timestamp = null ) => gmdate( (string) $format, (int) $timestamp ) );
		Functions\when( 'get_bloginfo' )->alias( fn () => $this->wp_version );
		Functions\when( 'get_option' )->alias(
			fn ( $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'get_site_transient' )->alias(
			fn ( $key ) => array_key_exists( $key, $this->site_transients ) ? $this->site_transients[ $key ] : false
		);
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an inspector pinned to the fixed clock.
	 *
	 * @param array<string, mixed> $config Module config.
	 */
	private function inspector( array $config = [] ): VersionInspector {
		return new VersionInspector( $config, new SslProbe(), false, false, self::NOW );
	}

	/**
	 * Locate a check by id.
	 *
	 * @param array<int, HealthCheck> $checks Checks.
	 * @param string                  $id     Stable check id.
	 */
	private function find( array $checks, string $id ): HealthCheck {
		foreach ( $checks as $check ) {
			if ( $check->id === $id ) {
				return $check;
			}
		}

		$this->fail( "No check with id `{$id}`." );
	}

	/**
	 * Seed core's own `update_core` site transient with an offer.
	 *
	 * @param string $version Version the offer advertises.
	 */
	private function seed_core_offer( string $version ): void {
		$offer          = new \stdClass();
		$offer->current = $version;
		$offer->response = 'upgrade';

		$updates          = new \stdClass();
		$updates->updates = [ $offer ];

		$this->site_transients['update_core'] = $updates;
	}

	public function test_every_check_uses_the_versions_group_and_a_closed_status(): void {
		$checks = $this->inspector()->checks();

		$this->assertSame(
			[ 'wordpress_version', 'php_version', 'database_version', 'ssl_certificate', 'https_enforced' ],
			array_map( static fn ( HealthCheck $check ): string => $check->id, $checks )
		);

		foreach ( $checks as $check ) {
			$this->assertSame( HealthCheck::GROUP_VERSIONS, $check->group );
			$this->assertContains( $check->status, HealthCheck::ALL_STATUSES );
		}
	}

	public function test_the_php_check_reports_the_running_version_and_the_matrix_review_date(): void {
		$check = $this->find( $this->inspector()->checks(), 'php_version' );

		$this->assertSame( PHP_VERSION, $check->value );
		$this->assertArrayHasKey( 'matrix_reviewed', $check->meta );
		$this->assertArrayHasKey( 'security_until', $check->meta );
	}

	public function test_wordpress_up_to_date_passes(): void {
		$this->wp_version = '6.9';
		$this->seed_core_offer( '6.9' );

		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
	}

	public function test_a_maintenance_release_on_the_same_branch_is_critical(): void {
		$this->wp_version = '6.9';
		$this->seed_core_offer( '6.9.2' );

		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( 0, $check->meta['minors_behind'] );
	}

	public function test_one_feature_release_behind_warns(): void {
		$this->wp_version = '6.8';
		$this->seed_core_offer( '6.9' );

		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertSame( 1, $check->meta['minors_behind'] );
	}

	public function test_two_or_more_feature_releases_behind_is_critical(): void {
		$this->wp_version = '6.4';
		$this->seed_core_offer( '6.9' );

		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( 5, $check->meta['minors_behind'] );
	}

	public function test_the_distance_calculation_survives_a_major_version_rollover(): void {
		$this->wp_version = '5.9';
		$this->seed_core_offer( '6.0' );

		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( 1, $check->meta['minors_behind'], '5.9 → 6.0 is one feature release, not eleven.' );
	}

	public function test_no_core_update_data_reports_unknown_rather_than_ok(): void {
		$check = $this->find( $this->inspector()->checks(), 'wordpress_version' );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertNull( $check->meta['latest'] );
	}

	public function test_a_modern_mariadb_is_not_mistaken_for_mysql_55(): void {
		// MariaDB reports `5.5.5-` as a compatibility prefix; taking it at face
		// value — as core's own `wpdb::db_version()` does — would place an
		// 11.4 server in the MySQL 5.5 row and report a decade-old EOL.
		$GLOBALS['wpdb']->server_info = '5.5.5-11.4.2-MariaDB-1:11.4.2+maria~ubu2204';

		$check = $this->find( $this->inspector()->checks(), 'database_version' );

		$this->assertSame( 'mariadb', $check->meta['server'] );
		$this->assertSame( '11.4', $check->meta['branch'] );
		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
	}

	public function test_an_end_of_life_database_is_critical(): void {
		$GLOBALS['wpdb']->server_info = '5.7.44-log';

		$check = $this->find( $this->inspector()->checks(), 'database_version' );

		$this->assertSame( 'mysql', $check->meta['server'] );
		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
	}

	public function test_a_silent_database_driver_reports_unknown(): void {
		$GLOBALS['wpdb']->server_info = '';

		$check = $this->find( $this->inspector()->checks(), 'database_version' );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertNull( $check->meta['server'] );
	}

	public function test_https_on_both_urls_passes(): void {
		$this->assertSame(
			HealthCheck::STATUS_OK,
			$this->find( $this->inspector()->checks(), 'https_enforced' )->status
		);
	}

	public function test_a_plain_http_site_is_critical_and_gets_a_snippet(): void {
		$this->options['home']    = 'http://example.test';
		$this->options['siteurl'] = 'http://example.test';

		$check = $this->find( $this->inspector()->checks(), 'https_enforced' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertStringContainsString( 'FORCE_SSL_ADMIN', $check->remediation[0]['code'] );
	}

	public function test_a_half_migrated_site_is_flagged(): void {
		$this->options['siteurl'] = 'http://example.test';

		$check = $this->find( $this->inspector()->checks(), 'https_enforced' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertTrue( $check->meta['home_https'] );
		$this->assertFalse( $check->meta['site_https'] );
	}

	public function test_an_unprobed_certificate_is_unknown_not_a_pass(): void {
		$check = $this->find( $this->inspector()->checks(), 'ssl_certificate' );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertSame( 'example.test', $check->meta['host'] );
	}

	public function test_a_certificate_that_could_not_be_read_says_so_instead_of_alarming(): void {
		$this->transients[ SslProbe::CACHE_KEY ] = [
			'result'     => SslProbe::RESULT_UNREACHABLE,
			'expires_at' => null,
			'starts_at'  => null,
			'issuer'     => null,
			'message'    => 'Connection refused',
			'probed_at'  => self::NOW,
		];

		$check = $this->find( $this->inspector()->checks(), 'ssl_certificate' );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertStringContainsString( 'outbound', $check->summary );
		$this->assertSame( 'Connection refused', $check->meta['message'] );
	}

	public function test_a_healthy_certificate_passes(): void {
		$this->seed_certificate( self::NOW + ( 90 * DAY_IN_SECONDS ) );

		$check = $this->find( $this->inspector()->checks(), 'ssl_certificate' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 90, $check->meta['days_remaining'] );
	}

	public function test_a_certificate_expiring_inside_the_window_warns(): void {
		$this->seed_certificate( self::NOW + ( 10 * DAY_IN_SECONDS ) );

		$check = $this->find( $this->inspector()->checks(), 'ssl_certificate' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
	}

	public function test_the_certificate_warning_window_is_configurable(): void {
		$this->seed_certificate( self::NOW + ( 45 * DAY_IN_SECONDS ) );

		$check = $this->find(
			$this->inspector( [ 'thresholds' => [ 'ssl_expiry_warning_days' => 60 ] ] )->checks(),
			'ssl_certificate'
		);

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
	}

	public function test_an_expired_certificate_is_critical(): void {
		$this->seed_certificate( self::NOW - DAY_IN_SECONDS );

		$check = $this->find( $this->inspector()->checks(), 'ssl_certificate' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
	}

	public function test_switching_the_certificate_check_off_reports_unknown_and_probes_nothing(): void {
		$this->seed_certificate( self::NOW - DAY_IN_SECONDS );

		$check = $this->find(
			$this->inspector( [ 'ssl_check_enabled' => false ] )->checks(),
			'ssl_certificate'
		);

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertFalse( $check->meta['enabled'] );
	}

	/**
	 * Seed a cached certificate reading expiring at the given timestamp.
	 *
	 * @param int $expires_at Unix timestamp the certificate expires at.
	 */
	private function seed_certificate( int $expires_at ): void {
		$this->transients[ SslProbe::CACHE_KEY ] = [
			'result'     => SslProbe::RESULT_READ,
			'expires_at' => $expires_at,
			'starts_at'  => self::NOW - DAY_IN_SECONDS,
			'issuer'     => "Let's Encrypt",
			'message'    => '',
			'probed_at'  => self::NOW,
		];
	}
}
