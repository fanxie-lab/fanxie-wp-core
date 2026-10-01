<?php
/**
 * Admin AJAX sub-actions for the Security Headers module.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPolicy;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPresetLibrary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX surface consumed by the Vue admin UI.
 *
 * Nonce + `manage_fanxie_warden` capability are enforced centrally by the
 * AjaxRouter — handlers here focus on payload validation and domain logic.
 * Every handler returns either an associative array (success) or a WP_Error
 * (the router converts it into the frontend's error envelope).
 */
final class AjaxController {

	/**
	 * Constructor.
	 *
	 * @param SecurityHeaders     $module     Module instance (source of truth for config).
	 * @param ViolationRepository $repository Persistence layer for violations.
	 * @param CspPresetLibrary    $presets    Preset catalog.
	 */
	public function __construct(
		private readonly SecurityHeaders $module,
		private readonly ViolationRepository $repository,
		private readonly CspPresetLibrary $presets,
	) {}

	/**
	 * Attach every sub-action to the shared router.
	 *
	 * @param AjaxRouter $router Shared AJAX router.
	 */
	public function register( AjaxRouter $router ): void {
		$router->register( 'security-headers/get-config', [ $this, 'handle_get_config' ] );
		$router->register( 'security-headers/save-config', [ $this, 'handle_save_config' ] );
		$router->register( 'security-headers/apply-preset', [ $this, 'handle_apply_preset' ] );
		$router->register( 'security-headers/list-violations', [ $this, 'handle_list_violations' ] );
		$router->register( 'security-headers/purge-violations', [ $this, 'handle_purge_violations' ] );
	}

	/**
	 * Handler: `security-headers/get-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused but part of the router contract).
	 *
	 * @return array<string, mixed>
	 */
	public function handle_get_config( array $payload = [] ): array {
		unset( $payload );
		return [
			'settings' => $this->module->get_config(),
			'status'   => $this->derive_status(),
			'presets'  => $this->preset_descriptors(),
		];
	}

	/**
	 * Handler: `security-headers/save-config`.
	 *
	 * @param array<string, mixed> $payload Expects `{ settings: array }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_save_config( array $payload ): array|WP_Error {
		if ( ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Settings payload must be an object.', 'fanxie-warden' ) );
		}

		$this->module->update_config( $payload['settings'] );

		return [
			'settings' => $this->module->get_config(),
			'status'   => $this->derive_status(),
		];
	}

	/**
	 * Handler: `security-headers/apply-preset`.
	 *
	 * @param array<string, mixed> $payload Expects `{ preset_id: string }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_apply_preset( array $payload ): array|WP_Error {
		$preset_id = isset( $payload['preset_id'] ) ? sanitize_key( (string) $payload['preset_id'] ) : '';
		if ( '' === $preset_id ) {
			return new WP_Error( 'missing_preset_id', __( 'Preset id is required.', 'fanxie-warden' ) );
		}

		$preset = $this->presets->get( $preset_id );
		if ( null === $preset ) {
			return new WP_Error( 'unknown_preset', __( 'The requested preset was not found.', 'fanxie-warden' ) );
		}

		$current    = $this->module->get_config();
		$csp        = isset( $current['csp'] ) && is_array( $current['csp'] ) ? $current['csp'] : [];
		$directives = isset( $csp['directives'] ) && is_array( $csp['directives'] )
			? $csp['directives']
			: [];

		foreach ( $preset->directives as $name => $values ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $values ) ) {
				continue;
			}
			$existing = isset( $directives[ $name ] ) && is_array( $directives[ $name ] ) ? $directives[ $name ] : [];
			foreach ( $values as $value ) {
				if ( is_string( $value ) && '' !== $value && ! in_array( $value, $existing, true ) ) {
					$existing[] = $value;
				}
			}
			$directives[ $name ] = $existing;
		}

		$csp['directives'] = $directives;
		$current['csp']    = $csp;
		$this->module->update_config( $current );

		return [
			'settings' => $this->module->get_config(),
			'status'   => $this->derive_status(),
			'preset'   => $preset->to_array(),
		];
	}

	/**
	 * Handler: `security-headers/list-violations`.
	 *
	 * @param array<string, mixed> $payload Expects `{ page?, per_page?, directive?, since?, until? }`.
	 * @return array<string, mixed>
	 */
	public function handle_list_violations( array $payload ): array {
		$page     = isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1;
		$per_page = isset( $payload['per_page'] ) ? max( 1, min( 200, (int) $payload['per_page'] ) ) : 25;

		$filters = [];
		if ( isset( $payload['directive'] ) && '' !== (string) $payload['directive'] ) {
			$filters['directive'] = sanitize_text_field( (string) $payload['directive'] );
		}
		if ( isset( $payload['since'] ) && '' !== (string) $payload['since'] ) {
			$filters['since'] = sanitize_text_field( (string) $payload['since'] );
		}
		if ( isset( $payload['until'] ) && '' !== (string) $payload['until'] ) {
			$filters['until'] = sanitize_text_field( (string) $payload['until'] );
		}

		$result = $this->repository->query( $filters, $page, $per_page );

		return [
			'rows'     => array_map( static fn ( ViolationRecord $r ): array => $r->to_array(), $result['rows'] ),
			'total'    => $result['total'],
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Handler: `security-headers/purge-violations`.
	 *
	 * @param array<string, mixed> $payload Expects `{ older_than_days?: int, all?: bool }`.
	 * @return array<string, mixed>
	 */
	public function handle_purge_violations( array $payload ): array {
		if ( ! empty( $payload['all'] ) ) {
			$deleted = $this->repository->purge_all();
			return [ 'deleted' => $deleted ];
		}

		$days    = isset( $payload['older_than_days'] ) ? max( 1, (int) $payload['older_than_days'] ) : 30;
		$deleted = $this->repository->prune( $days );

		return [ 'deleted' => $deleted ];
	}

	/**
	 * Derived status information surfaced in the admin UI.
	 *
	 * Shape mirrors the `SecurityHeadersStatus` TS contract consumed by the
	 * Vue store — keep the keys in sync with
	 * `assets/admin/src/modules/SecurityHeaders/types.ts`.
	 *
	 * @return array<string, mixed>
	 */
	private function derive_status(): array {
		$is_ssl  = function_exists( 'is_ssl' ) ? (bool) is_ssl() : false;
		$config  = $this->module->get_config();
		$headers = isset( $config['headers'] ) && is_array( $config['headers'] ) ? $config['headers'] : [];
		$csp     = isset( $config['csp'] ) && is_array( $config['csp'] ) ? $config['csp'] : [];
		$hsts    = isset( $headers['hsts'] ) && is_array( $headers['hsts'] ) ? $headers['hsts'] : [];

		$report_endpoint = isset( $csp['report_uri'] ) && is_string( $csp['report_uri'] )
			? $csp['report_uri']
			: '';

		// Count enabled non-CSP headers. Keys mirror `get_default_config()`.
		$active_header_count = 0;
		foreach ( [ 'hsts', 'xfo', 'xcto', 'referrer', 'permissions', 'cache_control' ] as $key ) {
			if ( isset( $headers[ $key ]['enabled'] ) && true === (bool) $headers[ $key ]['enabled'] ) {
				++$active_header_count;
			}
		}

		$csp_mode   = isset( $csp['mode'] ) && is_string( $csp['mode'] ) ? $csp['mode'] : CspPolicy::MODE_OFF;
		$csp_active = CspPolicy::MODE_OFF !== $csp_mode;
		$active     = $active_header_count > 0 || $csp_active;

		return [
			'is_https'            => $is_ssl,
			'hsts_detected'       => $is_ssl && ! empty( $hsts['enabled'] ),
			'csp_detected'        => $csp_active,
			'report_endpoint'     => $report_endpoint,
			'active'              => $active,
			'active_header_count' => $active_header_count,
			'csp_active'          => $csp_active,
			'summary'             => $this->build_summary( $active_header_count, $csp_mode ),
		];
	}

	/**
	 * Human-friendly summary for the status badge.
	 *
	 * @param int    $count    Number of enabled non-CSP headers.
	 * @param string $csp_mode One of the `CspPolicy::MODE_*` constants.
	 */
	private function build_summary( int $count, string $csp_mode ): string {
		$csp_active = CspPolicy::MODE_OFF !== $csp_mode;

		if ( 0 === $count && ! $csp_active ) {
			return __( 'Inactive', 'fanxie-warden' );
		}

		$mode_label = $this->csp_mode_label( $csp_mode );

		if ( $count > 0 && $csp_active ) {
			return sprintf(
				/* translators: 1: header count, 2: CSP mode label. */
				_n( '%1$d header · CSP %2$s', '%1$d headers · CSP %2$s', $count, 'fanxie-warden' ),
				$count,
				$mode_label
			);
		}

		if ( $count > 0 ) {
			return sprintf(
				/* translators: %d: header count. */
				_n( '%d header active', '%d headers active', $count, 'fanxie-warden' ),
				$count
			);
		}

		return sprintf(
			/* translators: %s: CSP mode label. */
			__( 'CSP %s only', 'fanxie-warden' ),
			$mode_label
		);
	}

	/**
	 * Translate a CSP mode constant into a human-friendly label.
	 *
	 * @param string $mode One of the `CspPolicy::MODE_*` constants.
	 */
	private function csp_mode_label( string $mode ): string {
		return match ( $mode ) {
			CspPolicy::MODE_REPORT_ONLY => __( 'Report-Only', 'fanxie-warden' ),
			CspPolicy::MODE_ENFORCE     => __( 'Enforce', 'fanxie-warden' ),
			default                     => __( 'Off', 'fanxie-warden' ),
		};
	}

	/**
	 * Preset catalog in array form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function preset_descriptors(): array {
		return array_values(
			array_map(
				static fn ( $preset ): array => $preset->to_array(),
				$this->presets->all(),
			)
		);
	}
}
