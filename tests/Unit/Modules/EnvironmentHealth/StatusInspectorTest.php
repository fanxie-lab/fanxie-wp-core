<?php
/**
 * Unit tests for the Environment Health report assembler.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use FanxieLab\WPCore\Modules\EnvironmentHealth\StatusInspector;

/**
 * Two things matter here beyond the obvious aggregation: that a disabled group
 * disappears from the report rather than lingering as a placeholder, and that
 * assembling a report performs no network I/O whatsoever.
 */
final class StatusInspectorTest extends EnvironmentHealthTestCase {

	private EnvironmentHealth $module;

	protected function setUp(): void {
		parent::setUp();
		$this->module = new EnvironmentHealth( new AjaxRouter() );
	}

	private function inspector(): StatusInspector {
		return new StatusInspector( $this->module, new SslProbe(), new WporgScanner(), self::NOW );
	}

	public function test_a_full_report_covers_every_group_and_obeys_the_contract(): void {
		$report = $this->inspector()->report();

		$this->assertContractHolds( $report );
		$this->assertSame( self::NOW, $report['generated_at'] );
		$this->assertSame( self::NOW + StatusInspector::CACHE_TTL_SEC, $report['cached_until'] );

		foreach ( HealthCheck::ALL_GROUPS as $group ) {
			$groups = array_column( $report['checks'], 'group' );
			$this->assertContains( $group, $groups, "group `{$group}` is missing from the report" );
		}
	}

	public function test_counts_add_up_to_the_number_of_checks(): void {
		$report = $this->inspector()->report();

		$this->assertSame( count( $report['checks'] ), array_sum( $report['counts'] ) );
		$this->assertSame(
			[ 'ok', 'warning', 'critical', 'unknown' ],
			array_keys( $report['counts'] ),
			'Every status key is always present so the UI never guards on existence.'
		);
	}

	public function test_assembling_a_report_never_touches_the_network(): void {
		$this->inspector()->report();

		$this->assertSame( [], $this->requested, 'Report assembly must be free of outbound requests.' );
	}

	public function test_a_disabled_group_is_absent_rather_than_placeheld(): void {
		$this->module->update_config( [ 'checks' => [ 'debug' => false ] ] );

		$ids = $this->check_ids( $this->inspector()->report( true ) );

		$this->assertNotContains( 'wp_debug', $ids );
		$this->assertNotContains( 'php_display_errors', $ids );
		$this->assertContains( 'php_version', $ids, 'Other groups are unaffected.' );
	}

	public function test_every_group_can_be_switched_off(): void {
		$this->module->update_config(
			[
				'checks' => [
					'versions'       => false,
					'cron'           => false,
					'debug'          => false,
					'plugins_themes' => false,
				],
			]
		);

		$report = $this->inspector()->report( true );

		$this->assertSame( [], $report['checks'] );
		$this->assertSame( 0, array_sum( $report['counts'] ) );
	}

	public function test_the_report_is_cached_and_reused(): void {
		$inspector = $this->inspector();
		$first     = $inspector->report();

		$this->assertArrayHasKey( StatusInspector::CACHE_KEY, $this->transients );

		// Perturb the environment; a cached read must not notice.
		$this->options['home'] = 'http://example.test';

		$this->assertSame( $first, $inspector->report() );
	}

	public function test_forcing_a_rebuild_bypasses_the_cache(): void {
		$inspector = $this->inspector();
		$inspector->report();

		$this->options['home']    = 'http://example.test';
		$this->options['siteurl'] = 'http://example.test';

		$this->assertSame(
			HealthCheck::STATUS_CRITICAL,
			$this->check_in( $inspector->report( true ), 'https_enforced' )['status']
		);
	}

	public function test_invalidating_the_cache_forces_the_next_read_to_rebuild(): void {
		$inspector = $this->inspector();
		$inspector->report();

		$inspector->invalidate_cache();

		$this->assertArrayNotHasKey( StatusInspector::CACHE_KEY, $this->transients );
	}

	public function test_cached_report_returns_null_on_a_cold_cache(): void {
		$this->assertNull( $this->inspector()->cached_report(), 'The dashboard widget must never build a report itself.' );
	}

	public function test_cached_report_returns_the_stored_payload(): void {
		$inspector = $this->inspector();
		$built     = $inspector->report();

		$this->assertSame( $built, $inspector->cached_report() );
	}

	public function test_a_payload_written_by_older_code_is_rejected(): void {
		$this->transients[ StatusInspector::CACHE_KEY ] = [ 'checks' => [] ];

		$inspector = $this->inspector();

		$this->assertNull( $inspector->cached_report() );
		$this->assertContractHolds( $inspector->report() );
	}

	public function test_active_plugin_slugs_are_exposed_for_the_scanner(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php', 'jetpack/jetpack.php' ];

		$this->assertSame( [ 'akismet', 'jetpack' ], $this->inspector()->active_plugin_slugs() );
	}

	public function test_a_certificate_probe_is_allowed_only_when_the_caller_permits_it(): void {
		$inspector = $this->inspector();

		$this->assertSame(
			HealthCheck::STATUS_UNKNOWN,
			$this->check_in( $inspector->report(), 'ssl_certificate' )['status']
		);
		$this->assertArrayNotHasKey(
			SslProbe::CACHE_KEY,
			$this->transients,
			'No socket, and no cache entry, without explicit permission.'
		);
	}
}
