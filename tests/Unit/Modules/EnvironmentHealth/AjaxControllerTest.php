<?php
/**
 * Unit tests for the Environment Health AJAX controller.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\AjaxController;
use FanxieLab\WPCore\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use FanxieLab\WPCore\Modules\EnvironmentHealth\StatusInspector;
use WP_Error;

/**
 * The four handlers are a frozen contract with the Vue tab, so the assertions
 * here are mostly about response *shape* — plus the two behavioural promises
 * the module makes: `get-report` never scans wordpress.org, and switching the
 * scan off leaves no cached lookups behind.
 */
final class EnvironmentHealthAjaxControllerTest extends EnvironmentHealthTestCase {

	private EnvironmentHealth $module;

	private StatusInspector $inspector;

	private WporgScanner $scanner;

	private SslProbe $ssl;

	private AjaxController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->module     = new EnvironmentHealth( new AjaxRouter() );
		$this->ssl        = new SslProbe();
		$this->scanner    = new WporgScanner();
		$this->inspector  = new StatusInspector( $this->module, $this->ssl, $this->scanner, self::NOW );
		$this->controller = new AjaxController( $this->module, $this->inspector, $this->scanner, $this->ssl );
	}

	public function test_get_report_returns_the_frozen_health_report_shape(): void {
		$report = $this->controller->handle_get_report();

		$this->assertContractHolds( $report );
	}

	public function test_get_report_never_scans_wordpress_org(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php', 'jetpack/jetpack.php' ];

		$this->controller->handle_get_report();

		$this->assertSame( [], $this->requested, 'A tab load must not fan out to api.wordpress.org.' );
		$this->assertSame(
			HealthCheck::STATUS_UNKNOWN,
			$this->check_in( $this->inspector->report(), 'abandoned_plugins' )['status']
		);
	}

	public function test_get_config_returns_the_settings_map(): void {
		$config = $this->controller->handle_get_config();

		$this->assertArrayHasKey( 'checks', $config );
		$this->assertArrayHasKey( 'wporg_scan_enabled', $config );
		$this->assertArrayHasKey( 'thresholds', $config );
		$this->assertArrayNotHasKey( 'checks_', $config );
	}

	public function test_save_config_rejects_a_payload_without_settings(): void {
		$result = $this->controller->handle_save_config( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_settings', $result->get_error_code() );
	}

	public function test_save_config_rejects_a_non_object_settings_value(): void {
		$result = $this->controller->handle_save_config( [ 'settings' => 'nope' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_save_config_persists_and_answers_with_a_fresh_report(): void {
		$result = $this->controller->handle_save_config(
			[ 'settings' => [ 'checks' => [ 'debug' => false ] ] ]
		);

		$this->assertIsArray( $result );
		$this->assertContractHolds( $result );
		$this->assertNotContains( 'wp_debug', $this->check_ids( $result ) );
		$this->assertFalse( $this->module->get_config()['checks']['debug'] );
	}

	public function test_switching_the_wporg_scan_off_discards_every_cached_lookup(): void {
		$this->scanner->scan( [ 'akismet' ], 5 );
		$this->assertNotSame( [], $this->scanner->results() );

		$this->controller->handle_save_config( [ 'settings' => [ 'wporg_scan_enabled' => false ] ] );

		$this->assertSame(
			[],
			$this->scanner->results(),
			'An opt-out that keeps the data it collected is not an opt-out.'
		);
	}

	public function test_saving_with_the_scan_already_off_is_a_no_op_for_the_cache(): void {
		$this->module->update_config( [ 'wporg_scan_enabled' => false ] );
		$this->options[ WporgScanner::CACHE_OPTION ] = [
			'legacy' => [
				'state'      => WporgScanner::STATE_FOUND,
				'checked_at' => self::NOW,
			],
		];

		$this->controller->handle_save_config( [ 'settings' => [ 'wporg_scan_enabled' => false ] ] );

		$this->assertArrayHasKey( WporgScanner::CACHE_OPTION, $this->options );
	}

	public function test_refresh_busts_the_report_cache_and_re_reads_the_environment(): void {
		$this->controller->handle_get_report();

		$this->options['home']    = 'http://example.test';
		$this->options['siteurl'] = 'http://example.test';

		$report = $this->controller->handle_refresh();

		$this->assertSame(
			HealthCheck::STATUS_CRITICAL,
			$this->check_in( $report, 'https_enforced' )['status']
		);
	}

	public function test_refresh_advances_the_wporg_scan_by_one_interactive_batch(): void {
		$this->options['active_plugins'] = array_map(
			static fn ( int $i ): string => "plugin-{$i}/plugin-{$i}.php",
			range( 1, 12 )
		);

		$this->controller->handle_refresh();

		$this->assertCount(
			WporgScanner::INTERACTIVE_BATCH,
			$this->requested,
			'A twelve-plugin site gets five lookups now and the rest on a follow-up pass.'
		);
		$this->assertArrayHasKey(
			$this->cron_key( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] ),
			$this->scheduled
		);
	}

	public function test_refresh_does_not_schedule_a_follow_up_once_the_scan_is_complete(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];

		$this->controller->handle_refresh();

		$this->assertCount( 1, $this->requested );
		$this->assertArrayNotHasKey(
			$this->cron_key( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] ),
			$this->scheduled
		);
	}

	public function test_refresh_makes_no_request_when_the_scan_is_switched_off(): void {
		$this->module->update_config( [ 'wporg_scan_enabled' => false ] );

		$this->controller->handle_refresh();

		$this->assertSame( [], $this->requested );
	}

	public function test_refresh_invalidates_the_certificate_cache(): void {
		$this->transients[ SslProbe::CACHE_KEY ] = [
			'result'     => SslProbe::RESULT_READ,
			'expires_at' => self::NOW + 86400,
			'starts_at'  => null,
			'issuer'     => null,
			'message'    => '',
			'probed_at'  => 1,
		];

		$this->controller->handle_refresh();

		$this->assertNotSame(
			1,
			$this->transients[ SslProbe::CACHE_KEY ]['probed_at'] ?? null,
			'A manual refresh must re-read the certificate, not serve yesterday’s answer.'
		);
	}
}
