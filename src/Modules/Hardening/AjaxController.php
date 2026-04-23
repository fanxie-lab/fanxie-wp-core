<?php
/**
 * Admin AJAX sub-actions for the Hardening module.
 *
 * @package FanxieLab\WPCore\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\Hardening\Runtime\RootHtaccessWriter;
use FanxieLab\WPCore\Modules\Hardening\Runtime\XmlRpcGate;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX surface consumed by the Vue admin UI.
 *
 * Nonce + `manage_fanxie_wp_core` capability are enforced centrally by the
 * AjaxRouter. Handlers validate the payload shape, delegate to the module /
 * inspector / uploads protector, and return the shared envelope:
 *
 *   { settings, status, checks }
 *
 * The envelope keys are kept stable across get-config / save-config /
 * run-checks / apply-fix so the Vue store can rehydrate from every response.
 */
final class AjaxController {

	/**
	 * Lazily-resolved root `.htaccess` writer for readme/license blocking.
	 *
	 * @var RootHtaccessWriter|null
	 */
	private ?RootHtaccessWriter $htaccess_writer;

	/**
	 * Constructor.
	 *
	 * @param Hardening               $module          Module instance (source of truth for config).
	 * @param StatusInspector         $inspector       Server-state probes + cache.
	 * @param UploadsProtector        $uploads         Uploads writer + probe.
	 * @param RootHtaccessWriter|null $htaccess_writer Optional writer override (tests).
	 */
	public function __construct(
		private readonly Hardening $module,
		private readonly StatusInspector $inspector,
		private readonly UploadsProtector $uploads,
		?RootHtaccessWriter $htaccess_writer = null,
	) {
		$this->htaccess_writer = $htaccess_writer;
	}

	/**
	 * Attach every sub-action to the shared router.
	 *
	 * @param AjaxRouter $router Shared AJAX router.
	 */
	public function register( AjaxRouter $router ): void {
		$router->register( 'hardening/get-config', [ $this, 'handle_get_config' ] );
		$router->register( 'hardening/save-config', [ $this, 'handle_save_config' ] );
		$router->register( 'hardening/run-checks', [ $this, 'handle_run_checks' ] );
		$router->register( 'hardening/apply-fix', [ $this, 'handle_apply_fix' ] );
		$router->register( 'hardening/drop-upload-guard', [ $this, 'handle_drop_upload_guard' ] );
		$router->register( 'hardening/remove-upload-guard', [ $this, 'handle_remove_upload_guard' ] );
	}

	/**
	 * Handler: `hardening/get-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_config( array $payload = [] ): array {
		unset( $payload );
		return $this->envelope();
	}

	/**
	 * Handler: `hardening/save-config`.
	 *
	 * @param array<string, mixed> $payload Expects `{ settings: array }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_save_config( array $payload ): array|WP_Error {
		if ( ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Settings payload must be an object.', 'fanxie-wp-core' ) );
		}

		$this->module->update_config( $payload['settings'] );

		// After persisting, re-apply side-effects driven by the new config.
		// Specifically: the readme/license `.htaccess` block must be added or
		// removed in lockstep with the `version_hiding.block_readme_license`
		// toggle so the HTTP status probe reports the truth on next read.
		$this->sync_readme_license_block();

		$this->inspector->invalidate_cache();

		return $this->envelope( force_probe: true );
	}

	/**
	 * Handler: `hardening/run-checks`.
	 *
	 * Bypasses the 5-minute cache — the button is there precisely to re-probe
	 * after a manual remediation.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_run_checks( array $payload = [] ): array {
		unset( $payload );
		return $this->envelope( force_probe: true );
	}

	/**
	 * Handler: `hardening/apply-fix`.
	 *
	 * @param array<string, mixed> $payload Expects `{ target: string }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_apply_fix( array $payload ): array|WP_Error {
		$target = isset( $payload['target'] ) ? sanitize_key( (string) $payload['target'] ) : '';
		if ( '' === $target ) {
			return new WP_Error( 'missing_target', __( 'A fix target is required.', 'fanxie-wp-core' ) );
		}

		$allowed = [ 'uploads_index', 'uploads_htaccess', 'readme_license_block' ];
		if ( ! in_array( $target, $allowed, true ) ) {
			return new WP_Error( 'invalid_target', __( 'Unknown fix target.', 'fanxie-wp-core' ) );
		}

		if ( 'readme_license_block' === $target ) {
			$this->htaccess_writer()->ensure_readme_license_block();
		} else {
			$this->uploads->apply_fix( $target );
		}

		$this->inspector->invalidate_cache();

		return $this->envelope( force_probe: true );
	}

	/**
	 * Handler: `hardening/drop-upload-guard`.
	 *
	 * Writes (or rewrites) the requested uploads protection file. Distinct
	 * from `apply-fix` only in that the UI surfaces it as an explicit user
	 * action alongside a "Restore" button.
	 *
	 * @param array<string, mixed> $payload Expects `{ target: 'uploads_index' | 'uploads_htaccess' }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_drop_upload_guard( array $payload ): array|WP_Error {
		$target = $this->validate_upload_target( $payload );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$this->uploads->apply_fix( $target );
		$this->inspector->invalidate_cache();

		return $this->envelope( force_probe: true );
	}

	/**
	 * Handler: `hardening/remove-upload-guard`.
	 *
	 * Deletes the requested uploads protection file (operator opts to revert).
	 *
	 * @param array<string, mixed> $payload Expects `{ target: 'uploads_index' | 'uploads_htaccess' }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_remove_upload_guard( array $payload ): array|WP_Error {
		$target = $this->validate_upload_target( $payload );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$this->uploads->remove_fix( $target );
		$this->inspector->invalidate_cache();

		return $this->envelope( force_probe: true );
	}

	/**
	 * Ensure the readme/license `.htaccess` block tracks the current toggle.
	 */
	private function sync_readme_license_block(): void {
		$config  = $this->module->get_config();
		$enabled = (bool) ( $config['version_hiding']['block_readme_license'] ?? false );

		if ( $enabled ) {
			$this->htaccess_writer()->ensure_readme_license_block();
		} else {
			$this->htaccess_writer()->remove_block();
		}
	}

	/**
	 * Resolve the lazily-instantiated root `.htaccess` writer.
	 */
	private function htaccess_writer(): RootHtaccessWriter {
		if ( null === $this->htaccess_writer ) {
			$this->htaccess_writer = new RootHtaccessWriter();
		}
		return $this->htaccess_writer;
	}

	/**
	 * Validate a drop/remove upload-guard payload, returning the sanitised target.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return string|WP_Error
	 */
	private function validate_upload_target( array $payload ): string|WP_Error {
		$target = isset( $payload['target'] ) ? sanitize_key( (string) $payload['target'] ) : '';
		if ( '' === $target ) {
			return new WP_Error( 'missing_target', __( 'A fix target is required.', 'fanxie-wp-core' ) );
		}

		$allowed = [ 'uploads_index', 'uploads_htaccess' ];
		if ( ! in_array( $target, $allowed, true ) ) {
			return new WP_Error( 'invalid_target', __( 'Unknown fix target.', 'fanxie-wp-core' ) );
		}

		return $target;
	}

	/**
	 * Build the canonical response envelope used by every handler.
	 *
	 * @param bool $force_probe Bypass the `StatusInspector` cache.
	 *
	 * @return array<string, mixed>
	 */
	private function envelope( bool $force_probe = false ): array {
		$settings = $this->module->get_config();
		$checks   = $this->inspector->snapshot( $force_probe );

		return [
			'settings' => $settings,
			'status'   => $this->derive_status( $settings, $checks ),
			'checks'   => $checks,
		];
	}

	/**
	 * Derive human-friendly status from the config + checks.
	 *
	 * @param array<string, mixed> $settings Nested config.
	 * @param array<string, mixed> $checks   Inspector snapshot.
	 * @return array<string, mixed>
	 */
	private function derive_status( array $settings, array $checks ): array {
		$active   = $this->any_toggle_active( $settings );
		$warnings = $this->collect_warnings( $settings, $checks );

		return [
			'active'   => $active,
			'summary'  => $this->build_summary( $active, $settings, $checks ),
			'warnings' => $warnings,
		];
	}

	/**
	 * Whether any hardening toggle is currently active.
	 *
	 * @param array<string, mixed> $settings Nested config.
	 */
	private function any_toggle_active( array $settings ): bool {
		$paths = [
			[ 'user_enumeration', 'block_author_archive' ],
			[ 'user_enumeration', 'block_rest_users_endpoint' ],
			[ 'version_hiding', 'remove_powered_by' ],
			[ 'version_hiding', 'remove_wp_generator' ],
			[ 'version_hiding', 'remove_rss_generator' ],
			[ 'version_hiding', 'strip_version_query' ],
			[ 'version_hiding', 'block_readme_license' ],
			[ 'uploads', 'drop_index' ],
			[ 'uploads', 'block_php_execution' ],
			[ 'login', 'obfuscate_errors' ],
			[ 'file_editing', 'runtime_enforce' ],
			[ 'application_passwords', 'disable' ],
		];

		foreach ( $paths as $path ) {
			if ( true === $this->read_path( $settings, $path, false ) ) {
				return true;
			}
		}

		$xmlrpc_mode = (string) $this->read_path( $settings, [ 'xmlrpc', 'mode' ], XmlRpcGate::MODE_OFF );
		return XmlRpcGate::MODE_OFF !== $xmlrpc_mode;
	}

	/**
	 * Derive caveats the UI should surface alongside the status pill.
	 *
	 * @param array<string, mixed> $settings Nested config.
	 * @param array<string, mixed> $checks   Inspector snapshot.
	 * @return array<int, string>
	 */
	private function collect_warnings( array $settings, array $checks ): array {
		$warnings = [];

		if ( true === $this->read_path( $settings, [ 'version_hiding', 'remove_powered_by' ], false )
			&& ! empty( $checks['x_powered_by_present'] )
		) {
			$warnings[] = __( 'X-Powered-By header is still present — your web server or php.ini is injecting it.', 'fanxie-wp-core' );
		}

		if ( true === $this->read_path( $settings, [ 'uploads', 'drop_index' ], false )
			&& true === ( $checks['uploads_dir_listable'] ?? null )
		) {
			$warnings[] = __( 'Uploads directory is still listable over HTTP.', 'fanxie-wp-core' );
		}

		if ( true === $this->read_path( $settings, [ 'uploads', 'block_php_execution' ], false )
			&& true === ( $checks['uploads_php_executable'] ?? null )
		) {
			$warnings[] = __( 'PHP files in uploads still execute — server configuration (likely nginx) needs updating.', 'fanxie-wp-core' );
		}

		if ( true === $this->read_path( $settings, [ 'version_hiding', 'block_readme_license' ], false ) ) {
			if ( false === ( $checks['readme_blocked'] ?? true ) ) {
				$warnings[] = __( '/readme.html is still reachable — server rules are not being honoured.', 'fanxie-wp-core' );
			}
			if ( false === ( $checks['license_blocked'] ?? true ) ) {
				$warnings[] = __( '/license.txt is still reachable — server rules are not being honoured.', 'fanxie-wp-core' );
			}
		}

		$server_type = isset( $checks['server_type'] ) ? (string) $checks['server_type'] : 'unknown';
		if ( 'nginx' === $server_type
			&& true === $this->read_path( $settings, [ 'uploads', 'block_php_execution' ], false )
		) {
			$warnings[] = __( 'nginx detected — drop the provided server snippet into your vhost to block PHP execution in uploads.', 'fanxie-wp-core' );
		}

		return $warnings;
	}

	/**
	 * Compose a one-line status label.
	 *
	 * @param bool                 $active   Whether anything is on.
	 * @param array<string, mixed> $settings Nested config.
	 * @param array<string, mixed> $checks   Inspector snapshot.
	 */
	private function build_summary( bool $active, array $settings, array $checks ): string {
		unset( $checks );

		if ( ! $active ) {
			return __( 'Inactive', 'fanxie-wp-core' );
		}

		$count = $this->active_toggle_count( $settings );

		return sprintf(
			/* translators: %d: number of active hardening toggles. */
			_n( '%d safeguard active', '%d safeguards active', $count, 'fanxie-wp-core' ),
			$count
		);
	}

	/**
	 * Count the number of toggles currently enabled.
	 *
	 * @param array<string, mixed> $settings Nested config.
	 */
	private function active_toggle_count( array $settings ): int {
		$count = 0;
		$paths = [
			[ 'user_enumeration', 'block_author_archive' ],
			[ 'user_enumeration', 'block_rest_users_endpoint' ],
			[ 'version_hiding', 'remove_powered_by' ],
			[ 'version_hiding', 'remove_wp_generator' ],
			[ 'version_hiding', 'remove_rss_generator' ],
			[ 'version_hiding', 'strip_version_query' ],
			[ 'version_hiding', 'block_readme_license' ],
			[ 'uploads', 'drop_index' ],
			[ 'uploads', 'block_php_execution' ],
			[ 'login', 'obfuscate_errors' ],
			[ 'file_editing', 'runtime_enforce' ],
			[ 'application_passwords', 'disable' ],
		];

		foreach ( $paths as $path ) {
			if ( true === $this->read_path( $settings, $path, false ) ) {
				++$count;
			}
		}

		$xmlrpc_mode = (string) $this->read_path( $settings, [ 'xmlrpc', 'mode' ], XmlRpcGate::MODE_OFF );
		if ( XmlRpcGate::MODE_OFF !== $xmlrpc_mode ) {
			++$count;
		}

		return $count;
	}

	/**
	 * Read a value at a segmented path, falling back to a default.
	 *
	 * @param array<string, mixed> $source   Source array.
	 * @param array<int, string>   $path     Segmented path.
	 * @param mixed                $fallback Returned on missing segment.
	 * @return mixed
	 */
	private function read_path( array $source, array $path, mixed $fallback ): mixed {
		$cursor = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $fallback;
			}
			$cursor = $cursor[ $segment ];
		}
		return $cursor;
	}
}
