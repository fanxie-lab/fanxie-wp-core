<?php
/**
 * Integration tests for the Environment Health dashboard widget.
 *
 * @package FanxieLab\Warden\Tests\Integration\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\EnvironmentHealth;

use FanxieLab\Warden\Modules\EnvironmentHealth\DashboardWidget;
use FanxieLab\Warden\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\Warden\Modules\EnvironmentHealth\StatusInspector;

/**
 * The widget's contract is narrow but strict: it renders from cache, it never
 * builds a report or opens a connection, and it is invisible to users without
 * the plugin capability.
 */
final class EnvironmentHealthDashboardWidgetTest extends EnvironmentHealthTestCase {

	private function widget(): DashboardWidget {
		return new DashboardWidget( $this->module()->status_inspector() );
	}

	/**
	 * Capture the widget's rendered markup.
	 */
	private function render(): string {
		ob_start();
		$this->widget()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Seed the report cache with a synthetic payload.
	 *
	 * @param array<string, int>               $counts Status tallies.
	 * @param array<int, array<string, mixed>> $checks Serialised checks.
	 */
	private function seed_report( array $counts, array $checks = [] ): void {
		set_transient(
			StatusInspector::CACHE_KEY,
			[
				'generated_at' => time() - 60,
				'cached_until' => time() + 3600,
				'counts'       => array_merge(
					[
						'ok'       => 0,
						'warning'  => 0,
						'critical' => 0,
						'unknown'  => 0,
					],
					$counts
				),
				'checks'       => $checks,
			],
			HOUR_IN_SECONDS
		);
	}

	public function test_the_widget_is_registered_for_a_capable_user(): void {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'dashboard' );
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		do_action( 'wp_dashboard_setup' );

		global $wp_meta_boxes;

		$this->assertIsArray( $wp_meta_boxes );
		$this->assertArrayHasKey(
			DashboardWidget::WIDGET_ID,
			$wp_meta_boxes['dashboard']['normal']['core'] ?? []
		);
	}

	public function test_the_widget_is_hidden_from_users_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		ob_start();
		$this->widget()->register();
		$this->widget()->render();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_a_cold_cache_says_so_instead_of_building_a_report(): void {
		wp_set_current_user( $this->admin_user_id );

		$output = $this->render();

		$this->assertStringContainsString( 'No health report has been generated yet', $output );
		$this->assertStringContainsString( 'tab=environment-health', str_replace( '&#038;', '&', $output ) );
		$this->assertFalse( get_transient( StatusInspector::CACHE_KEY ), 'Rendering must not populate the cache.' );
		$this->assertSame( [], $this->requested );
	}

	public function test_an_all_clear_report_reads_as_all_clear(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->seed_report( [ 'ok' => 12 ] );

		$output = $this->render();

		$this->assertStringContainsString( 'Everything checks out', $output );
		$this->assertStringContainsString( 'Passing: 12', $output );
	}

	public function test_unknown_checks_do_not_read_as_all_clear(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->seed_report(
			[
				'ok'      => 10,
				'unknown' => 2,
			]
		);

		$output = $this->render();

		$this->assertStringContainsString( 'some checks could not be completed', $output );
		$this->assertStringNotContainsString( 'Everything checks out', $output );
	}

	public function test_critical_findings_are_headlined_and_listed_worst_first(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->seed_report(
			[
				'ok'       => 8,
				'warning'  => 1,
				'critical' => 1,
			],
			[
				[
					'id'      => 'inactive_plugins',
					'group'   => HealthCheck::GROUP_PLUGINS_THEMES,
					'label'   => 'Inactive plugins',
					'status'  => HealthCheck::STATUS_WARNING,
					'summary' => 'Two installed plugins are not active.',
				],
				[
					'id'      => 'php_version',
					'group'   => HealthCheck::GROUP_VERSIONS,
					'label'   => 'PHP version',
					'status'  => HealthCheck::STATUS_CRITICAL,
					'summary' => 'PHP 8.1 reached end of life.',
				],
			]
		);

		$output = $this->render();

		$this->assertStringContainsString( '1 check needs attention now', $output );
		$this->assertLessThan(
			strpos( $output, 'Inactive plugins' ),
			strpos( $output, 'PHP version' ),
			'Critical findings come before warnings.'
		);
	}

	public function test_passing_checks_are_not_listed_individually(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->seed_report(
			[ 'ok' => 1 ],
			[
				[
					'id'      => 'https_enforced',
					'group'   => HealthCheck::GROUP_VERSIONS,
					'label'   => 'HTTPS',
					'status'  => HealthCheck::STATUS_OK,
					'summary' => 'Site and home URLs both use HTTPS.',
				],
			]
		);

		$output = $this->render();

		$this->assertStringNotContainsString( 'Site and home URLs both use HTTPS', $output );
	}

	public function test_rendered_output_is_escaped(): void {
		wp_set_current_user( $this->admin_user_id );
		$this->seed_report(
			[ 'critical' => 1 ],
			[
				[
					'id'      => 'php_version',
					'group'   => HealthCheck::GROUP_VERSIONS,
					'label'   => '<script>alert(1)</script>',
					'status'  => HealthCheck::STATUS_CRITICAL,
					'summary' => 'Broken.',
				],
			]
		);

		$output = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_the_widget_can_be_switched_off_in_settings(): void {
		update_option(
			self::OPTION_KEY,
			[
				'ssl_check_enabled' => false,
				'dashboard_widget'  => false,
			]
		);

		$this->assertFalse( $this->module()->get_config()['dashboard_widget'] );
	}
}
