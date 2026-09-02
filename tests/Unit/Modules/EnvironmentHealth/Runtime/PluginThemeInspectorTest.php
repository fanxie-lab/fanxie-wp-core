<?php
/**
 * Unit tests for the plugin/theme hygiene checks.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\PluginThemeInspector;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use PHPUnit\Framework\TestCase;
use WP_Theme;

/**
 * Covers inactive-plugin and unused-theme detection, and the abandoned-plugin
 * verdict — including the two cases the PRD calls out by name: a premium
 * plugin wp.org has never listed, and a lookup that has not happened yet.
 */
final class PluginThemeInspectorTest extends TestCase {

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
	 * `get_plugins()` return value.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $installed = [];

	/**
	 * `wp_get_themes()` return value.
	 *
	 * @var array<string, WP_Theme>
	 */
	private array $themes = [];

	/**
	 * `wp_get_theme()` return value.
	 */
	private WP_Theme $active_theme;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}

		$this->options      = [];
		$this->installed    = [];
		$this->active_theme = new WP_Theme( 'acme-theme', 'Acme Theme' );
		$this->themes       = [ 'acme-theme' => $this->active_theme ];

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-admin/' . (string) $path );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( [] );
		Functions\when( 'get_option' )->alias(
			fn ( $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'get_plugins' )->alias( fn () => $this->installed );
		Functions\when( 'wp_get_themes' )->alias( fn () => $this->themes );
		Functions\when( 'wp_get_theme' )->alias( fn () => $this->active_theme );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an inspector pinned to the fixed clock.
	 *
	 * @param array<string, mixed>                                                                          $config  Module config.
	 * @param array<string, array{state: string, last_updated: int|null, checked_at: int, message: string}> $results wp.org cache rows.
	 */
	private function inspector( array $config = [], array $results = [] ): PluginThemeInspector {
		$this->options[ WporgScanner::CACHE_OPTION ] = $results;

		return new PluginThemeInspector( $config, new WporgScanner(), self::NOW );
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
	 * Build a wp.org cache row for a plugin last updated N days ago.
	 *
	 * @param int $days How long ago the plugin was last updated.
	 * @return array{state: string, last_updated: int, checked_at: int, message: string}
	 */
	private function updated_days_ago( int $days ): array {
		return [
			'state'        => WporgScanner::STATE_FOUND,
			'last_updated' => self::NOW - ( $days * DAY_IN_SECONDS ),
			'checked_at'   => self::NOW,
			'message'      => '',
		];
	}

	/**
	 * Install one active plugin plus N inactive ones named "Dormant N".
	 *
	 * @param int $count How many inactive plugins to install.
	 */
	private function install_inactive_plugins( int $count ): void {
		$this->installed = [ 'active/active.php' => [ 'Name' => 'Active Plugin' ] ];

		for ( $i = 1; $i <= $count; $i++ ) {
			$this->installed[ "dormant-{$i}/dormant.php" ] = [ 'Name' => "Dormant {$i}" ];
		}

		$this->options['active_plugins'] = [ 'active/active.php' ];
	}

	/**
	 * Install the active theme plus N unused ones named "Dormant N".
	 *
	 * @param int $count How many unused themes to install.
	 */
	private function install_inactive_themes( int $count ): void {
		$this->themes = [ 'acme-theme' => $this->active_theme ];

		for ( $i = 1; $i <= $count; $i++ ) {
			$this->themes[ "dormant-{$i}" ] = new WP_Theme( "dormant-{$i}", "Dormant {$i}" );
		}
	}

	public function test_all_plugins_active_passes(): void {
		$this->installed              = [ 'akismet/akismet.php' => [ 'Name' => 'Akismet' ] ];
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( '0', $check->value );
		$this->assertSame( [], $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
	}

	public function test_an_installed_but_inactive_plugin_warns_and_is_named(): void {
		$this->installed = [
			'akismet/akismet.php' => [ 'Name' => 'Akismet' ],
			'old-thing/old.php'   => [ 'Name' => 'Old Thing' ],
		];
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertSame( '1', $check->value );
		$this->assertSame( [ 'Old Thing' ], $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
		$this->assertSame( 2, $check->meta['total_installed'] );
		$this->assertSame( '1 installed plugin is not active: Old Thing.', $check->summary );
	}

	public function test_a_short_inactive_plugin_list_is_named_in_the_summary(): void {
		$this->install_inactive_plugins( 2 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( '2 installed plugins are not active: Dormant 1, Dormant 2.', $check->summary );
	}

	public function test_a_long_inactive_plugin_list_summarises_by_count_alone(): void {
		$this->install_inactive_plugins( 4 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( '4 installed plugins are not active.', $check->summary );
		$this->assertCount( 4, $check->meta['names'] );
	}

	public function test_exactly_fifteen_inactive_plugins_are_listed_with_nothing_omitted(): void {
		$this->install_inactive_plugins( 15 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( '15', $check->value );
		$this->assertCount( 15, $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
	}

	public function test_a_longer_inactive_plugin_list_is_capped_and_reports_what_it_dropped(): void {
		$this->install_inactive_plugins( 20 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( '20', $check->value );
		$this->assertCount( 15, $check->meta['names'] );
		$this->assertSame( 5, $check->meta['names_omitted'] );
		$this->assertSame( 'Dormant 1', $check->meta['names'][0] );
		$this->assertSame( 'Dormant 15', $check->meta['names'][14] );
	}

	public function test_a_plugin_name_containing_a_comma_stays_one_list_entry(): void {
		$this->installed = [
			'active/active.php' => [ 'Name' => 'Active Plugin' ],
			'sfs/sfs.php'       => [ 'Name' => 'Search, Filter & Sort' ],
			'other/other.php'   => [ 'Name' => 'Other Thing' ],
		];

		$this->options['active_plugins'] = [ 'active/active.php' ];

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( [ 'Search, Filter & Sort', 'Other Thing' ], $check->meta['names'] );
	}

	public function test_a_plugin_with_no_readable_header_is_listed_by_its_slug(): void {
		$this->installed = [ 'mystery/mystery.php' => [] ];

		$check = $this->find( $this->inspector()->checks(), 'inactive_plugins' );

		$this->assertSame( [ 'mystery' ], $check->meta['names'] );
	}

	public function test_active_plugin_slugs_are_derived_from_the_directory_name(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php', 'hello.php' ];

		$this->assertSame( [ 'akismet', 'hello' ], $this->inspector()->active_plugin_slugs() );
	}

	public function test_a_default_theme_is_kept_as_a_fallback_without_warning(): void {
		$this->themes = [
			'acme-theme'       => $this->active_theme,
			'twentytwentyfive' => new WP_Theme( 'twentytwentyfive', 'Twenty Twenty-Five' ),
		];

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 1, $check->meta['default_retained'] );
		$this->assertSame( [], $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
	}

	public function test_an_unused_non_default_theme_warns(): void {
		$this->themes = [
			'acme-theme' => $this->active_theme,
			'old-theme'  => new WP_Theme( 'old-theme', 'Old Theme' ),
		];

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertSame( [ 'Old Theme' ], $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
		$this->assertSame( '1 unused theme is installed: Old Theme.', $check->summary );
	}

	public function test_a_short_unused_theme_list_is_named_in_the_summary(): void {
		$this->install_inactive_themes( 2 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( '2 unused themes are installed: Dormant 1, Dormant 2.', $check->summary );
	}

	public function test_a_long_unused_theme_list_summarises_by_count_alone(): void {
		$this->install_inactive_themes( 4 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( '4 unused themes are installed.', $check->summary );
	}

	public function test_exactly_fifteen_unused_themes_are_listed_with_nothing_omitted(): void {
		$this->install_inactive_themes( 15 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( '15', $check->value );
		$this->assertCount( 15, $check->meta['names'] );
		$this->assertSame( 0, $check->meta['names_omitted'] );
	}

	public function test_a_longer_unused_theme_list_is_capped_and_reports_what_it_dropped(): void {
		$this->install_inactive_themes( 20 );

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( '20', $check->value );
		$this->assertCount( 15, $check->meta['names'] );
		$this->assertSame( 5, $check->meta['names_omitted'] );
	}

	public function test_a_theme_name_containing_a_comma_stays_one_list_entry(): void {
		$this->themes = [
			'acme-theme' => $this->active_theme,
			'old-theme'  => new WP_Theme( 'old-theme', 'Bold, Bright' ),
			'older'      => new WP_Theme( 'older', 'Older' ),
		];

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( [ 'Bold, Bright', 'Older' ], $check->meta['names'] );
	}

	public function test_a_child_themes_parent_is_never_flagged(): void {
		$parent             = new WP_Theme( 'acme-parent', 'Acme Parent' );
		$this->active_theme = new WP_Theme( 'acme-child', 'Acme Child', $parent );
		$this->themes       = [
			'acme-child'  => $this->active_theme,
			'acme-parent' => $parent,
		];

		$check = $this->find( $this->inspector()->checks(), 'inactive_themes' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
	}

	public function test_a_plugin_stale_for_two_years_is_critical(): void {
		$this->options['active_plugins'] = [ 'stale/stale.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'stale' => $this->updated_days_ago( 900 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( [ 'stale' ], $check->meta['critical_names'] );
		$this->assertSame( 0, $check->meta['critical_omitted'] );
	}

	public function test_an_abandoned_plugin_is_named_by_its_header_not_its_slug(): void {
		$this->installed = [ 'contact-form-7/wp-contact-form-7.php' => [ 'Name' => 'Contact Form 7' ] ];

		$this->options['active_plugins'] = [ 'contact-form-7/wp-contact-form-7.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'contact-form-7' => $this->updated_days_ago( 900 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( [ 'Contact Form 7' ], $check->meta['critical_names'] );
		$this->assertSame( '1 active plugin has not been updated in over a year: Contact Form 7.', $check->summary );
	}

	public function test_an_abandoned_plugin_falls_back_to_its_slug_when_no_header_is_available(): void {
		// Active, but absent from `get_plugins()` — an unreadable or
		// half-removed plugin directory still has to be nameable.
		$this->options['active_plugins'] = [ 'ghost/ghost.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'ghost' => $this->updated_days_ago( 900 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( [ 'ghost' ], $check->meta['critical_names'] );
	}

	public function test_the_abandoned_plugin_lists_are_capped_and_report_what_they_dropped(): void {
		$active  = [];
		$results = [];

		for ( $i = 1; $i <= 20; $i++ ) {
			$file                     = "stale-{$i}/stale-{$i}.php";
			$active[]                 = $file;
			$this->installed[ $file ] = [ 'Name' => "Stale {$i}" ];
			$results[ "stale-{$i}" ]  = $this->updated_days_ago( 900 );
		}

		$this->options['active_plugins'] = $active;

		$check = $this->find( $this->inspector( [], $results )->checks(), 'abandoned_plugins' );

		$this->assertCount( 15, $check->meta['critical_names'] );
		$this->assertSame( 5, $check->meta['critical_omitted'] );
		$this->assertSame( [], $check->meta['warning_names'] );
		$this->assertSame( 0, $check->meta['warning_omitted'] );
		$this->assertSame( '20 active plugins have not been updated in over a year.', $check->summary );
	}

	public function test_a_plugin_stale_for_a_year_only_warns(): void {
		$this->options['active_plugins'] = [ 'ageing/ageing.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'ageing' => $this->updated_days_ago( 400 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertSame( [ 'ageing' ], $check->meta['warning_names'] );
		$this->assertSame( 0, $check->meta['warning_omitted'] );
	}

	public function test_a_recently_updated_plugin_passes(): void {
		$this->options['active_plugins'] = [ 'fresh/fresh.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'fresh' => $this->updated_days_ago( 10 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 0, $check->meta['pending'] );
	}

	public function test_a_premium_plugin_absent_from_wporg_is_excluded_from_the_verdict(): void {
		$this->options['active_plugins'] = [ 'acme-pro/acme-pro.php' ];

		$check = $this->find(
			$this->inspector(
				[],
				[
					'acme-pro' => [
						'state'        => WporgScanner::STATE_NOT_ON_WPORG,
						'last_updated' => null,
						'checked_at'   => self::NOW,
						'message'      => '',
					],
				]
			)->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 1, $check->meta['not_on_wporg'] );
		$this->assertSame( [], $check->meta['critical_names'] );
		$this->assertSame( [], $check->meta['warning_names'] );
	}

	public function test_a_failed_lookup_is_counted_as_an_error_not_as_abandoned(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];

		$check = $this->find(
			$this->inspector(
				[],
				[
					'akismet' => [
						'state'        => WporgScanner::STATE_ERROR,
						'last_updated' => null,
						'checked_at'   => self::NOW,
						'message'      => 'http_503',
					],
				]
			)->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 1, $check->meta['errors'] );
	}

	public function test_an_unchecked_site_reports_unknown_rather_than_a_pass(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php', 'jetpack/jetpack.php' ];

		$check = $this->find( $this->inspector()->checks(), 'abandoned_plugins' );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertSame( 2, $check->meta['pending'] );
	}

	public function test_a_partially_scanned_site_reports_what_it_knows(): void {
		$this->options['active_plugins'] = [ 'fresh/fresh.php', 'unknown-yet/unknown-yet.php' ];

		$check = $this->find(
			$this->inspector( [], [ 'fresh' => $this->updated_days_ago( 5 ) ] )->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( 1, $check->meta['pending'] );
		$this->assertSame( 1, $check->meta['checked'] );
	}

	public function test_switching_the_wporg_scan_off_reports_unknown_and_never_reads_the_cache(): void {
		$this->options['active_plugins'] = [ 'stale/stale.php' ];

		$check = $this->find(
			$this->inspector(
				[ 'wporg_scan_enabled' => false ],
				[ 'stale' => $this->updated_days_ago( 900 ) ]
			)->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $check->status );
		$this->assertFalse( $check->meta['enabled'] );
	}

	public function test_the_abandonment_thresholds_are_configurable(): void {
		$this->options['active_plugins'] = [ 'ageing/ageing.php' ];

		$check = $this->find(
			$this->inspector(
				[
					'thresholds' => [
						'abandoned_warning_days'  => 30,
						'abandoned_critical_days' => 60,
					],
				],
				[ 'ageing' => $this->updated_days_ago( 90 ) ]
			)->checks(),
			'abandoned_plugins'
		);

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( 60, $check->meta['critical_after'] );
	}

	public function test_every_check_is_tagged_with_the_plugins_themes_group(): void {
		foreach ( $this->inspector()->checks() as $check ) {
			$this->assertSame( HealthCheck::GROUP_PLUGINS_THEMES, $check->group );
		}
	}
}
