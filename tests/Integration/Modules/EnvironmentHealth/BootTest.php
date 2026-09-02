<?php
/**
 * Integration tests for Environment Health boot wiring and the scheduled scan.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use FanxieLab\WPCore\Modules\EnvironmentHealth\StatusInspector;
use FanxieLab\WPCore\Modules\ModuleRegistry;
use FanxieLab\WPCore\Plugin;

/**
 * Covers the module's place in the plugin container, its option round-trip,
 * and the behaviour of the recurring scan — the one unattended path in the
 * module that is allowed to touch the network.
 */
final class EnvironmentHealthBootTest extends EnvironmentHealthTestCase {

	public function test_the_module_is_registered_in_the_container_and_the_registry(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin );

		$registry = $plugin->get( ModuleRegistry::class );
		$this->assertInstanceOf( ModuleRegistry::class, $registry );

		$ids = array_map(
			static fn ( $module ): string => $module->id(),
			array_values( $registry->all() )
		);

		$this->assertContains( EnvironmentHealth::MODULE_ID, $ids );
	}

	public function test_settings_round_trip_through_the_namespaced_option(): void {
		$module = $this->module();

		$module->update_config(
			[
				'wporg_scan_enabled' => false,
				'thresholds'         => [ 'abandoned_critical_days' => 500 ],
			]
		);

		$stored = get_option( self::OPTION_KEY );
		$this->assertIsArray( $stored );

		$config = $module->get_config();
		$this->assertFalse( $config['wporg_scan_enabled'] );
		$this->assertSame( 500, $config['thresholds']['abandoned_critical_days'] );
		$this->assertTrue( $config['checks']['versions'], 'Untouched branches keep their defaults.' );
	}

	public function test_the_recurring_scan_is_armed_at_boot(): void {
		// `reset_module_state()` clears the schedule, so re-run the wiring the
		// registry performs on `init`.
		$this->module()->register_hooks();

		$next = wp_next_scheduled( EnvironmentHealth::SCAN_HOOK );

		$this->assertIsInt( $next );
		$this->assertGreaterThan( time(), $next );
	}

	public function test_the_scheduled_scan_populates_the_report_cache(): void {
		$this->assertFalse( get_transient( StatusInspector::CACHE_KEY ) );

		$this->module()->run_scheduled_scan();

		$report = get_transient( StatusInspector::CACHE_KEY );

		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'checks', $report );
		$this->assertNotSame( [], $report['checks'] );
	}

	public function test_the_scheduled_scan_caches_wordpress_org_lookups(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);
		update_option( 'active_plugins', [ 'akismet/akismet.php' ] );

		$this->module()->run_scheduled_scan();

		$cache = get_option( WporgScanner::CACHE_OPTION );

		$this->assertIsArray( $cache );
		$this->assertArrayHasKey( 'akismet', $cache );
		$this->assertSame( WporgScanner::STATE_FOUND, $cache['akismet']['state'] );
	}

	public function test_a_second_scan_inside_the_cache_window_makes_no_request(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);
		update_option( 'active_plugins', [ 'akismet/akismet.php' ] );

		$module = $this->module();
		$module->run_scheduled_scan();
		$this->assertCount( 1, $this->requested );

		$module->run_scheduled_scan();

		$this->assertCount( 1, $this->requested, 'Results are cached for 24 hours.' );
	}

	public function test_a_large_site_is_scanned_in_batches_with_a_follow_up_queued(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);

		$plugins = [];
		for ( $i = 1; $i <= WporgScanner::SCHEDULED_BATCH + 5; $i++ ) {
			$plugins[] = "plugin-{$i}/plugin-{$i}.php";
		}
		update_option( 'active_plugins', $plugins );

		$this->module()->run_scheduled_scan();

		$this->assertCount(
			WporgScanner::SCHEDULED_BATCH,
			$this->requested,
			'A large site must never fire one request per plugin in a single pass.'
		);
		$this->assertIsInt( wp_next_scheduled( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] ) );
	}

	public function test_the_follow_up_event_fires_the_handler_without_fataling(): void {
		// The follow-up is scheduled with a `'follow-up'` argument so it does not
		// collide with the recurring event; the handler takes no parameters and
		// must tolerate the extra argument WordPress passes it.
		$this->module()->register_hooks();

		do_action( EnvironmentHealth::SCAN_HOOK, 'follow-up' );

		$this->assertIsArray( get_transient( StatusInspector::CACHE_KEY ) );
	}

	public function test_the_scheduled_scan_makes_no_request_when_switched_off(): void {
		$this->module()->run_scheduled_scan();

		$this->assertSame( [], $this->requested );
	}

	public function test_deactivation_clears_the_modules_cron_event(): void {
		$this->module()->register_hooks();
		$this->assertIsInt( wp_next_scheduled( EnvironmentHealth::SCAN_HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( EnvironmentHealth::SCAN_HOOK ) );
	}
}
