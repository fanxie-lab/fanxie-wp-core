<?php
/**
 * "At a glance" dashboard widget for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\SettingsPage;
use FanxieLab\WPCore\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * PRD §7.6 — a summary of the health report on the WordPress dashboard.
 *
 * Rendered in plain PHP rather than by the Vue app: the dashboard is not one
 * of our screens, so the SPA bundle is not (and must not be) loaded there.
 *
 * The widget is strictly a *reader*. It renders whatever
 * {@see StatusInspector::cached_report()} already holds and, on a cold cache,
 * says so and links to the module tab. It never assembles a report and never
 * touches the network — a dashboard load must not pay for either.
 */
final class DashboardWidget {

	/**
	 * Widget id registered with `wp_add_dashboard_widget()`.
	 *
	 * @var string
	 */
	public const WIDGET_ID = 'fanxie_wp_core_environment_health';

	/**
	 * Number of failing checks listed before the widget links out for the rest.
	 *
	 * @var int
	 */
	private const MAX_LISTED_ISSUES = 5;

	/**
	 * Constructor.
	 *
	 * @param StatusInspector $inspector Report reader.
	 */
	public function __construct( private readonly StatusInspector $inspector ) {}

	/**
	 * Register the widget on `wp_dashboard_setup`.
	 */
	public function register(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		// `wp_dashboard_setup` normally fires with wp-admin/includes/dashboard.php
		// already loaded, but the hook is public and can be fired from anywhere
		// (WP-CLI, a test bootstrap, a plugin doing its own setup). Bail rather
		// than fatal when the registration helper is not available.
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			esc_html__( 'Environment Health', 'fanxie-wp-core' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the widget body.
	 *
	 * Capability is re-checked here as well as at registration: a widget can be
	 * rendered through paths that do not re-run `wp_dashboard_setup`.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$report  = $this->inspector->cached_report();
		$tab_url = add_query_arg(
			[
				'page' => SettingsPage::MENU_SLUG,
				'tab'  => EnvironmentHealth::MODULE_ID,
			],
			admin_url( 'admin.php' )
		);

		if ( null === $report ) {
			printf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'No health report has been generated yet.', 'fanxie-wp-core' ),
				esc_url( $tab_url ),
				esc_html__( 'Run the checks', 'fanxie-wp-core' )
			);
			return;
		}

		$counts = is_array( $report['counts'] ) ? $report['counts'] : [];
		$worst  = HealthReport::worst_from_counts( $counts );

		echo '<p><strong>' . esc_html( $this->headline( $worst, $counts ) ) . '</strong></p>';

		echo '<ul style="margin:0 0 12px;">';
		foreach ( HealthCheck::ALL_STATUSES as $status ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: 1: status label, e.g. "Critical". 2: number of checks with that status. */
						__( '%1$s: %2$d', 'fanxie-wp-core' ),
						$this->status_label( $status ),
						(int) ( $counts[ $status ] ?? 0 )
					)
				)
			);
		}
		echo '</ul>';

		$issues = $this->failing_checks( $report );
		if ( [] !== $issues ) {
			echo '<ul style="margin:0 0 12px;list-style:disc;padding-left:18px;">';
			foreach ( array_slice( $issues, 0, self::MAX_LISTED_ISSUES ) as $issue ) {
				printf(
					'<li>%s</li>',
					esc_html(
						sprintf(
							/* translators: 1: health check name. 2: one-line summary of the finding. */
							__( '%1$s — %2$s', 'fanxie-wp-core' ),
							$issue['label'],
							$issue['summary']
						)
					)
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a href="%s">%s</a> <span class="description">%s</span></p>',
			esc_url( $tab_url ),
			esc_html__( 'View all checks', 'fanxie-wp-core' ),
			esc_html(
				sprintf(
					/* translators: %s: human-readable age of the cached report, e.g. "12 minutes". */
					__( 'Last measured %s ago.', 'fanxie-wp-core' ),
					human_time_diff( (int) $report['generated_at'], time() )
				)
			)
		);
	}

	/**
	 * Headline sentence for the widget.
	 *
	 * @param string               $worst  Worst status present.
	 * @param array<string, mixed> $counts Status → count map.
	 */
	private function headline( string $worst, array $counts ): string {
		$critical = (int) ( $counts[ HealthCheck::STATUS_CRITICAL ] ?? 0 );
		$warning  = (int) ( $counts[ HealthCheck::STATUS_WARNING ] ?? 0 );

		switch ( $worst ) {
			case HealthCheck::STATUS_CRITICAL:
				return sprintf(
					/* translators: %d: number of checks needing immediate attention. */
					_n( '%d check needs attention now.', '%d checks need attention now.', $critical, 'fanxie-wp-core' ),
					$critical
				);

			case HealthCheck::STATUS_WARNING:
				return sprintf(
					/* translators: %d: number of checks worth reviewing. */
					_n( '%d check is worth a look.', '%d checks are worth a look.', $warning, 'fanxie-wp-core' ),
					$warning
				);

			case HealthCheck::STATUS_UNKNOWN:
				return __( 'Nothing is wrong, but some checks could not be completed.', 'fanxie-wp-core' );

			default:
				return __( 'Everything checks out.', 'fanxie-wp-core' );
		}
	}

	/**
	 * Checks that are warning or critical, worst first.
	 *
	 * @param array<string, mixed> $report Cached report payload.
	 *
	 * @return array<int, array{label: string, summary: string, status: string}>
	 */
	private function failing_checks( array $report ): array {
		$checks = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : [];

		$critical = [];
		$warning  = [];

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['status'] ) ) {
				continue;
			}

			$row = [
				'label'   => isset( $check['label'] ) ? (string) $check['label'] : '',
				'summary' => isset( $check['summary'] ) ? (string) $check['summary'] : '',
				'status'  => (string) $check['status'],
			];

			if ( HealthCheck::STATUS_CRITICAL === $row['status'] ) {
				$critical[] = $row;
			} elseif ( HealthCheck::STATUS_WARNING === $row['status'] ) {
				$warning[] = $row;
			}
		}

		return array_merge( $critical, $warning );
	}

	/**
	 * Translated label for a status key.
	 *
	 * @param string $status Status key.
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			HealthCheck::STATUS_CRITICAL => __( 'Critical', 'fanxie-wp-core' ),
			HealthCheck::STATUS_WARNING  => __( 'Warning', 'fanxie-wp-core' ),
			HealthCheck::STATUS_UNKNOWN  => __( 'Unknown', 'fanxie-wp-core' ),
			default                      => __( 'Passing', 'fanxie-wp-core' ),
		};
	}
}
