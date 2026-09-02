<?php
/**
 * Integration tests for the Environment Health AJAX surface.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use WPAjaxDieContinueException;

/**
 * Drives the four sub-actions through the real `admin-ajax.php` dispatcher, so
 * the nonce check, the capability check, and the payload contract are all
 * exercised exactly as the Vue tab will exercise them.
 */
final class EnvironmentHealthAjaxSurfaceTest extends EnvironmentHealthTestCase {

	public function test_get_report_returns_the_frozen_report_shape(): void {
		$response = $this->dispatch( 'environment-health/get-report' );

		$this->assertTrue( $response['success'] );

		$report = $response['data'];
		$this->assertSame( [ 'generated_at', 'cached_until', 'counts', 'checks' ], array_keys( $report ) );
		$this->assertSame( [ 'ok', 'warning', 'critical', 'unknown' ], array_keys( $report['counts'] ) );
		$this->assertSame( count( $report['checks'] ), array_sum( $report['counts'] ) );
		$this->assertGreaterThan( $report['generated_at'], $report['cached_until'] );
	}

	public function test_every_check_obeys_the_closed_enums_and_is_translated(): void {
		$report = $this->dispatch( 'environment-health/get-report' )['data'];

		foreach ( $report['checks'] as $check ) {
			$this->assertContains( $check['status'], HealthCheck::ALL_STATUSES );
			$this->assertContains( $check['group'], HealthCheck::ALL_GROUPS );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $check['id'] );
			$this->assertNotSame( '', $check['label'] );
			$this->assertNotSame( '', $check['summary'] );

			foreach ( $check['remediation'] as $entry ) {
				$this->assertContains( $entry['kind'], [ 'snippet', 'link' ] );
				$this->assertNotSame( '', $entry['label'] );
			}
		}
	}

	public function test_the_report_covers_all_four_prd_groups(): void {
		$report = $this->dispatch( 'environment-health/get-report' )['data'];
		$ids    = array_column( $report['checks'], 'id' );

		foreach (
			[
				'wordpress_version',
				'php_version',
				'database_version',
				'ssl_certificate',
				'https_enforced',
				'cron_disabled',
				'cron_lock',
				'cron_overdue_events',
				'wp_debug',
				'wp_debug_display',
				'wp_debug_log',
				'script_debug',
				'php_display_errors',
				'php_error_reporting',
				'inactive_plugins',
				'inactive_themes',
				'abandoned_plugins',
			] as $id
		) {
			$this->assertContains( $id, $ids, "report is missing the `{$id}` check" );
		}
	}

	public function test_get_report_makes_no_outbound_request(): void {
		$this->dispatch( 'environment-health/get-report' );

		$this->assertSame( [], $this->requested, 'Loading the tab must not fan out to api.wordpress.org.' );
	}

	public function test_the_database_check_reads_the_real_server_version(): void {
		$check = $this->check_in( $this->dispatch( 'environment-health/get-report' )['data'], 'database_version' );

		$this->assertContains( $check['meta']['server'], [ 'mysql', 'mariadb' ] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', (string) $check['meta']['branch'] );
	}

	public function test_get_config_returns_the_settings_map(): void {
		$response = $this->dispatch( 'environment-health/get-config' );

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'checks', $response['data'] );
		$this->assertArrayHasKey( 'wporg_scan_enabled', $response['data'] );
		$this->assertArrayHasKey( 'thresholds', $response['data'] );
	}

	public function test_save_config_persists_to_the_namespaced_option(): void {
		$response = $this->dispatch(
			'environment-health/save-config',
			[
				'settings' => [
					'wporg_scan_enabled' => false,
					'thresholds'         => [ 'ssl_expiry_warning_days' => 14 ],
				],
			]
		);

		$this->assertTrue( $response['success'] );

		$stored = get_option( self::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertFalse( $stored['wporg_scan_enabled'] );
		$this->assertSame( 14, $stored['thresholds']['ssl_expiry_warning_days'] );
	}

	public function test_save_config_answers_with_a_rebuilt_report(): void {
		$response = $this->dispatch(
			'environment-health/save-config',
			[ 'settings' => [ 'checks' => [ 'cron' => false ] ] ]
		);

		$ids = array_column( $response['data']['checks'], 'id' );

		$this->assertNotContains( 'cron_overdue_events', $ids );
		$this->assertContains( 'php_version', $ids );
	}

	public function test_out_of_range_thresholds_are_clamped_by_the_endpoint(): void {
		// The browser enforces these bounds too, but `save-config` is a plain
		// AJAX endpoint — any caller can post anything, so the server has to be
		// the one that decides.
		$this->dispatch(
			'environment-health/save-config',
			[
				'settings' => [
					'thresholds' => [
						'ssl_expiry_warning_days' => 0,
						'cron_overdue_minutes'    => 0,
						'abandoned_warning_days'  => 99999999,
					],
				],
			]
		);

		$stored = get_option( self::OPTION_KEY );

		$this->assertIsArray( $stored );
		$this->assertSame( 1, $stored['thresholds']['ssl_expiry_warning_days'] );
		$this->assertSame( 1, $stored['thresholds']['cron_overdue_minutes'] );
		$this->assertSame( 3650, $stored['thresholds']['abandoned_warning_days'] );
	}

	public function test_a_clamped_value_surfaces_on_the_next_get_config(): void {
		// The module's stated contract is that a client wanting canonical
		// post-save values re-reads `get-config`. That only pays off if the
		// clamped value is what comes back.
		$this->dispatch(
			'environment-health/save-config',
			[ 'settings' => [ 'thresholds' => [ 'ssl_expiry_warning_days' => 5000 ] ] ]
		);

		$config = $this->dispatch( 'environment-health/get-config' )['data'];

		$this->assertSame( 365, $config['thresholds']['ssl_expiry_warning_days'] );
	}

	public function test_a_malformed_threshold_falls_back_to_its_default(): void {
		$this->dispatch(
			'environment-health/save-config',
			[ 'settings' => [ 'thresholds' => [ 'cron_overdue_minutes' => 'garbage' ] ] ]
		);

		$config = $this->dispatch( 'environment-health/get-config' )['data'];

		$this->assertSame(
			60,
			$config['thresholds']['cron_overdue_minutes'],
			'Non-numeric input must not land on zero, which alarms permanently.'
		);
	}

	public function test_an_inverted_abandoned_pair_is_resolved_by_the_endpoint(): void {
		$this->dispatch(
			'environment-health/save-config',
			[
				'settings' => [
					'thresholds' => [
						'abandoned_warning_days'  => 600,
						'abandoned_critical_days' => 90,
					],
				],
			]
		);

		$config = $this->dispatch( 'environment-health/get-config' )['data'];

		$this->assertSame( 600, $config['thresholds']['abandoned_warning_days'] );
		$this->assertSame( 600, $config['thresholds']['abandoned_critical_days'] );
	}

	public function test_a_clamped_cron_threshold_does_not_mark_healthy_events_overdue(): void {
		// The end-to-end consequence: with `absint`, posting 0 here made the
		// cron check report every scheduled event as overdue for ever.
		wp_schedule_single_event( time() + 600, 'fanxie_test_future_event' );

		$report = $this->dispatch(
			'environment-health/save-config',
			[ 'settings' => [ 'thresholds' => [ 'cron_overdue_minutes' => 0 ] ] ]
		)['data'];

		$check = $this->check_in( $report, 'cron_overdue_events' );

		$this->assertSame( 1, $check['meta']['threshold_minutes'] );
		$this->assertSame( HealthCheck::STATUS_OK, $check['status'] );

		wp_clear_scheduled_hook( 'fanxie_test_future_event' );
	}

	public function test_save_config_rejects_a_payload_without_settings(): void {
		$response = $this->dispatch( 'environment-health/save-config' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_settings', $response['data']['code'] );
	}

	public function test_switching_the_wporg_scan_off_deletes_the_cached_lookups(): void {
		update_option(
			WporgScanner::CACHE_OPTION,
			[
				'akismet' => [
					'state'      => WporgScanner::STATE_FOUND,
					'checked_at' => time(),
				],
			]
		);

		$this->dispatch(
			'environment-health/save-config',
			[ 'settings' => [ 'wporg_scan_enabled' => false ] ]
		);

		$this->assertFalse( get_option( WporgScanner::CACHE_OPTION ) );
	}

	public function test_refresh_rebuilds_the_report_and_scans_wordpress_org(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);
		update_option( 'active_plugins', [ 'akismet/akismet.php' ] );

		$response = $this->dispatch( 'environment-health/refresh' );

		$this->assertTrue( $response['success'] );
		$this->assertNotSame( [], $this->requested );

		foreach ( $this->requested as $url ) {
			$this->assertStringStartsWith( WporgScanner::API_ENDPOINT, $url );
		}
	}

	public function test_refresh_sends_nothing_but_plugin_slugs_to_wordpress_org(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);
		update_option( 'active_plugins', [ 'akismet/akismet.php' ] );

		$this->dispatch( 'environment-health/refresh' );

		$this->assertCount( 1, $this->requested );

		$query = [];
		parse_str( (string) wp_parse_url( $this->requested[0], PHP_URL_QUERY ), $query );

		$this->assertSame( 'plugin_information', $query['action'] );
		$this->assertSame( 'akismet', $query['slug'] );
		$this->assertArrayNotHasKey( 'user', $query );
	}

	public function test_refresh_makes_no_request_when_the_scan_is_switched_off(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => false,
			]
		);

		$this->dispatch( 'environment-health/refresh' );

		$this->assertSame( [], $this->requested );
	}

	public function test_a_wp_error_from_wordpress_org_never_breaks_the_report(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled'  => false,
				'wporg_scan_enabled' => true,
			]
		);
		update_option( 'active_plugins', [ 'akismet/akismet.php' ] );

		$this->responses[] = new \WP_Error( 'http_request_failed', 'Operation timed out' );

		$response = $this->dispatch( 'environment-health/refresh' );

		$this->assertTrue( $response['success'] );

		$check = $this->check_in( $response['data'], 'abandoned_plugins' );
		$this->assertSame( HealthCheck::STATUS_OK, $check['status'] );
		$this->assertSame( 1, $check['meta']['errors'] );
	}

	public function test_a_bad_nonce_is_rejected(): void {
		wp_set_current_user( $this->admin_user_id );

		$_POST = [
			'action'      => AjaxRouter::AJAX_ACTION,
			'_action'     => 'environment-health/get-report',
			'_ajax_nonce' => 'not-a-nonce',
		];

		$response = $this->dispatch_raw();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['data']['code'] );
	}

	public function test_a_user_without_the_capability_is_rejected(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$_POST = [
			'action'      => AjaxRouter::AJAX_ACTION,
			'_action'     => 'environment-health/get-report',
			'_ajax_nonce' => wp_create_nonce( AjaxRouter::NONCE_ACTION ),
		];

		$response = $this->dispatch_raw();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'forbidden', $response['data']['code'] );
	}

	/**
	 * Dispatch a sub-action as the administrator.
	 *
	 * @param string               $sub_action Sub-action slug.
	 * @param array<string, mixed> $payload    Extra payload fields.
	 * @return array<string, mixed>
	 */
	private function dispatch( string $sub_action, array $payload = [] ): array {
		wp_set_current_user( $this->admin_user_id );

		$_POST = array_merge(
			$payload,
			[
				'action'      => AjaxRouter::AJAX_ACTION,
				'_action'     => $sub_action,
				'_ajax_nonce' => wp_create_nonce( AjaxRouter::NONCE_ACTION ),
			]
		);

		return $this->dispatch_raw();
	}

	/**
	 * Fire the AJAX action with whatever is already in `$_POST`.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch_raw(): array {
		// Deliberately *not* `define( 'DOING_AJAX', true )`: a constant cannot be
		// unset, so defining one here would put every later test in the run into
		// an AJAX context — which, among other things, makes Login Protection's
		// slug guard treat ordinary requests as a safe carve-out. The filter
		// gives the same signal for the duration of one dispatch.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );

		ob_start();

		try {
			do_action( 'wp_ajax_' . AjaxRouter::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected termination.
			// Expected — the handler called wp_die() to flush the JSON body.
		} finally {
			remove_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$decoded = json_decode( (string) ob_get_clean(), true );

		$this->assertIsArray( $decoded, 'The dispatcher should always emit a JSON envelope.' );

		return $decoded;
	}

	/**
	 * Sink for `wp_die()` so a dispatched handler terminates cleanly mid-test.
	 *
	 * @return callable
	 */
	public function ajax_die_handler(): callable {
		return static function (): void {
			throw new WPAjaxDieContinueException( 'ajax-dispatched' );
		};
	}
}
