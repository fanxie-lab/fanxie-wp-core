<?php
/**
 * Admin AJAX sub-actions for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX surface consumed by the Vue admin tab.
 *
 * Nonce and the `manage_fanxie_wp_core` capability are enforced centrally by
 * {@see AjaxRouter} — both, on every sub-action.
 *
 * Response shapes are a frozen contract with the frontend:
 *
 *   - `environment-health/get-report`   → HealthReport (cached)
 *   - `environment-health/refresh`      → HealthReport (caches busted, re-run)
 *   - `environment-health/get-config`   → the settings map
 *   - `environment-health/save-config`  → HealthReport (rebuilt from new settings)
 *
 * Note for the admin UI: `save-config` deliberately answers with the report,
 * not with the stored settings. Settings pass through the schema sanitiser on
 * the way in, so a client that wants the canonical post-save values should
 * follow up with `get-config` rather than assume its optimistic copy survived
 * untouched.
 */
final class AjaxController {

	/**
	 * Constructor.
	 *
	 * @param EnvironmentHealth $module    Module instance (source of truth for config).
	 * @param StatusInspector   $inspector Report assembler + cache.
	 * @param WporgScanner      $scanner   wordpress.org freshness scanner.
	 * @param SslProbe          $ssl       TLS certificate probe.
	 */
	public function __construct(
		private readonly EnvironmentHealth $module,
		private readonly StatusInspector $inspector,
		private readonly WporgScanner $scanner,
		private readonly SslProbe $ssl,
	) {}

	/**
	 * Attach every sub-action to the shared router.
	 *
	 * @param AjaxRouter $router Shared AJAX router.
	 */
	public function register( AjaxRouter $router ): void {
		$router->register( 'environment-health/get-report', [ $this, 'handle_get_report' ] );
		$router->register( 'environment-health/refresh', [ $this, 'handle_refresh' ] );
		$router->register( 'environment-health/get-config', [ $this, 'handle_get_config' ] );
		$router->register( 'environment-health/save-config', [ $this, 'handle_save_config' ] );
	}

	/**
	 * Handler: `environment-health/get-report`.
	 *
	 * Serves the cached report. A cold TLS cache is allowed to fill itself
	 * here — this is an explicit admin request, not a page render, and one
	 * five-second socket beats showing the operator a permanent "unknown".
	 * The wordpress.org scan is never run from this path.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_report( array $payload = [] ): array {
		unset( $payload );

		return $this->inspector->report( false, true );
	}

	/**
	 * Handler: `environment-health/refresh`.
	 *
	 * Busts every cache the module owns, advances the wordpress.org scan by one
	 * interactive batch, and re-assembles. When slugs remain unchecked the
	 * remainder is handed to a one-off cron event a minute out rather than
	 * being hammered through inline.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_refresh( array $payload = [] ): array {
		unset( $payload );

		$this->ssl->invalidate_cache();
		$this->advance_wporg_scan( WporgScanner::INTERACTIVE_BATCH );
		$this->inspector->invalidate_cache();

		return $this->inspector->report( true, true );
	}

	/**
	 * Handler: `environment-health/get-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_config( array $payload = [] ): array {
		unset( $payload );

		return $this->module->get_config();
	}

	/**
	 * Handler: `environment-health/save-config`.
	 *
	 * @param array<string, mixed> $payload Expects `{ settings: array }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_save_config( array $payload ): array|WP_Error {
		if ( ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Settings payload must be an object.', 'fanxie-wp-core' ) );
		}

		$was_scanning = $this->module->wporg_scan_enabled();

		$this->module->update_config( $payload['settings'] );

		// Switching the wordpress.org scan off must leave nothing behind: the
		// cached lookups are the only record that the site ever talked to
		// api.wordpress.org, and an opt-out that keeps the data is not one.
		if ( $was_scanning && ! $this->module->wporg_scan_enabled() ) {
			$this->scanner->forget_all();
		}

		$this->inspector->invalidate_cache();

		return $this->inspector->report( true, false );
	}

	/**
	 * Run one throttled batch of wordpress.org lookups, if the scan is on.
	 *
	 * @param int $budget Maximum lookups this pass.
	 */
	private function advance_wporg_scan( int $budget ): void {
		if ( ! $this->module->wporg_scan_enabled() ) {
			return;
		}

		$result = $this->scanner->scan( $this->inspector->active_plugin_slugs(), $budget );

		if ( ! $result['complete'] ) {
			$this->module->schedule_follow_up_scan();
		}
	}
}
