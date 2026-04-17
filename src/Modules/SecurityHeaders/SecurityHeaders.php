<?php
/**
 * Security Headers module entry point.
 *
 * @package FanxieLab\WPCore\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\SecurityHeaders;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\ModuleBase;
use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspPolicy;
use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspPresetLibrary;
use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspReportController;

defined( 'ABSPATH' ) || exit;

/**
 * Module #1 — Security Headers.
 *
 * Owns metadata, schema, config, and hook registration for the seven managed
 * HTTP security headers plus CSP reporting. Runtime emission lives in
 * `HeaderEmitter`, persistence in `ViolationRepository`, REST reporting in
 * `CspReportController`, and admin AJAX in `AjaxController` — this class
 * wires them together.
 *
 * Note on config shape: the admin schema uses flat, underscore-joined keys
 * (e.g. `headers_hsts_enabled`) rather than nested dotted keys. This matches
 * `ModuleBase`'s flat sanitiser contract and keeps option reads simple.
 */
final class SecurityHeaders extends ModuleBase {

	public const MODULE_ID = 'security-headers';

	/**
	 * Daily cron hook used to prune stale violations.
	 *
	 * @var string
	 */
	public const PRUNE_HOOK = 'fanxie_wp_core_csp_violations_prune';

	/**
	 * Constructor.
	 *
	 * @param AjaxRouter          $ajax_router Shared AJAX router.
	 * @param ViolationRepository $repository  Optional override — primarily useful for tests.
	 * @param CspPresetLibrary    $presets     Optional override — primarily useful for tests.
	 */
	public function __construct(
		private readonly AjaxRouter $ajax_router,
		private readonly ViolationRepository $repository = new ViolationRepository(),
		private readonly CspPresetLibrary $presets = new CspPresetLibrary(),
	) {}

	/**
	 * Unique module slug.
	 */
	public function id(): string {
		return self::MODULE_ID;
	}

	/**
	 * Translatable display name.
	 */
	public function name(): string {
		return __( 'Security Headers', 'fanxie-wp-core' );
	}

	/**
	 * Accessor for the repository — used by `Plugin::activate()` + tests.
	 */
	public function repository(): ViolationRepository {
		return $this->repository;
	}

	/**
	 * Accessor for the preset library — used by the AJAX controller + tests.
	 */
	public function presets(): CspPresetLibrary {
		return $this->presets;
	}

	/**
	 * Toggle the module's enabled state.
	 *
	 * Kept on the module (rather than buried in the AJAX controller) so CLI
	 * commands and programmatic callers have a single, typed entry point.
	 *
	 * @param bool $enabled New enabled state.
	 */
	public function set_enabled( bool $enabled ): void {
		update_option( $this->enabled_option_key(), $enabled ? 'yes' : 'no' );
	}

	/**
	 * Default configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return [
			// Strict-Transport-Security.
			'headers_hsts_enabled'            => true,
			'headers_hsts_max_age'            => 31536000,
			'headers_hsts_include_subdomains' => true,

			// X-Frame-Options.
			'headers_xfo_enabled'             => true,
			'headers_xfo_value'               => 'SAMEORIGIN',

			// X-Content-Type-Options.
			'headers_xcto_enabled'            => true,

			// Referrer-Policy.
			'headers_referrer_enabled'        => true,
			'headers_referrer_value'          => 'strict-origin-when-cross-origin',

			// Permissions-Policy.
			'headers_permissions_enabled'     => true,
			'headers_permissions_value'       => 'camera=(), microphone=(), geolocation=()',

			// Cache-Control — off by default (breaks dynamic pages easily).
			'headers_cache_control_enabled'   => false,
			'headers_cache_control_value'     => 'public, max-age=3600',

			// CSP.
			'csp_mode'                        => CspPolicy::MODE_REPORT_ONLY,
			'csp_learning_mode'               => true,
			'csp_directives'                  => $this->default_csp_directives(),
			'csp_report_uri'                  => $this->default_report_uri(),
		];
	}

	/**
	 * Schema consumed by the admin UI and the ModuleBase sanitiser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			// HSTS.
			[
				'id'        => 'headers_hsts_enabled',
				'label'     => __( 'Enable Strict-Transport-Security', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Only emitted on HTTPS connections.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'headers_hsts_max_age',
				'label'     => __( 'HSTS max-age (seconds)', 'fanxie-wp-core' ),
				'type'      => 'int',
				'default'   => 31536000,
				'sanitizer' => 'absint',
			],
			[
				'id'        => 'headers_hsts_include_subdomains',
				'label'     => __( 'Include subdomains', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// X-Frame-Options.
			[
				'id'        => 'headers_xfo_enabled',
				'label'     => __( 'Enable X-Frame-Options', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers_xfo_value',
				'label'     => __( 'X-Frame-Options value', 'fanxie-wp-core' ),
				'type'      => 'select',
				'default'   => 'SAMEORIGIN',
				'sanitizer' => 'text',
				'options'   => [
					'SAMEORIGIN' => 'SAMEORIGIN',
					'DENY'       => 'DENY',
				],
			],

			// X-Content-Type-Options.
			[
				'id'        => 'headers_xcto_enabled',
				'label'     => __( 'Enable X-Content-Type-Options: nosniff', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// Referrer-Policy.
			[
				'id'        => 'headers_referrer_enabled',
				'label'     => __( 'Enable Referrer-Policy', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers_referrer_value',
				'label'     => __( 'Referrer-Policy value', 'fanxie-wp-core' ),
				'type'      => 'select',
				'default'   => 'strict-origin-when-cross-origin',
				'sanitizer' => 'text',
				'options'   => [
					'no-referrer'                     => 'no-referrer',
					'no-referrer-when-downgrade'      => 'no-referrer-when-downgrade',
					'origin'                          => 'origin',
					'origin-when-cross-origin'        => 'origin-when-cross-origin',
					'same-origin'                     => 'same-origin',
					'strict-origin'                   => 'strict-origin',
					'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
					'unsafe-url'                      => 'unsafe-url',
				],
			],

			// Permissions-Policy.
			[
				'id'        => 'headers_permissions_enabled',
				'label'     => __( 'Enable Permissions-Policy', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers_permissions_value',
				'label'     => __( 'Permissions-Policy value', 'fanxie-wp-core' ),
				'type'      => 'text',
				'default'   => 'camera=(), microphone=(), geolocation=()',
				'sanitizer' => 'text',
			],

			// Cache-Control.
			[
				'id'        => 'headers_cache_control_enabled',
				'label'     => __( 'Enable Cache-Control (non-authenticated pages)', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Off by default — a wrong value can break dynamic pages.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'headers_cache_control_value',
				'label'     => __( 'Cache-Control value', 'fanxie-wp-core' ),
				'type'      => 'text',
				'default'   => 'public, max-age=3600',
				'sanitizer' => 'text',
			],

			// CSP.
			[
				'id'        => 'csp_mode',
				'label'     => __( 'Content-Security-Policy mode', 'fanxie-wp-core' ),
				'type'      => 'select',
				'default'   => CspPolicy::MODE_REPORT_ONLY,
				'sanitizer' => 'key',
				'options'   => [
					CspPolicy::MODE_OFF         => __( 'Off', 'fanxie-wp-core' ),
					CspPolicy::MODE_REPORT_ONLY => __( 'Report only', 'fanxie-wp-core' ),
					CspPolicy::MODE_ENFORCE     => __( 'Enforce', 'fanxie-wp-core' ),
				],
			],
			[
				'id'        => 'csp_learning_mode',
				'label'     => __( 'CSP learning mode (dual-emit)', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Keep collecting violation reports while the policy is enforced.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'csp_directives',
				'label'     => __( 'CSP directives', 'fanxie-wp-core' ),
				'type'      => 'textarea',
				'default'   => $this->default_csp_directives(),
				'sanitizer' => 'csp_directives',
			],
			[
				'id'        => 'csp_report_uri',
				'label'     => __( 'CSP report-uri', 'fanxie-wp-core' ),
				'type'      => 'text',
				'default'   => $this->default_report_uri(),
				'sanitizer' => 'url',
			],
		];
	}

	/**
	 * Register hooks when the module is enabled.
	 */
	public function register_hooks(): void {
		// Header emission.
		$emitter = new HeaderEmitter( $this );
		add_action( 'send_headers', [ $emitter, 'emit' ] );

		// Admin AJAX.
		$ajax = new AjaxController( $this, $this->repository, $this->presets );
		$ajax->register( $this->ajax_router );

		// REST endpoint for CSP reports.
		add_action(
			'rest_api_init',
			function (): void {
				$controller = new CspReportController( $this->repository );
				$controller->register();
			}
		);

		// Ensure the custom table exists early on every request (idempotent).
		add_action(
			'init',
			function (): void {
				$this->repository->install();
			},
			-1
		);

		// Daily prune via WP-Cron / Action Scheduler. Action Scheduler is
		// bundled with WooCommerce and will transparently replace this schedule
		// when it loads — either way, the `PRUNE_HOOK` fires once a day.
		if ( false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
		add_action(
			self::PRUNE_HOOK,
			function (): void {
				$this->repository->prune( 30 );
			}
		);
	}

	/**
	 * Override ModuleBase's sanitiser map to handle our nested directives array.
	 *
	 * Every other field uses the base `apply_sanitizer()`; the `csp_directives`
	 * field carries a nested `array<string, string[]>` shape that ModuleBase's
	 * scalar-only sanitisers can't meaningfully handle, so we wire it here.
	 *
	 * @param string $sanitizer Sanitizer identifier.
	 * @param mixed  $value     Raw value.
	 * @return mixed
	 */
	protected function apply_sanitizer( string $sanitizer, mixed $value ): mixed {
		if ( 'csp_directives' === $sanitizer ) {
			return $this->sanitize_directives( $value );
		}

		return parent::apply_sanitizer( $sanitizer, $value );
	}

	/**
	 * Normalise a CSP directive map — keys are directive names (lower-case,
	 * no whitespace), values are lists of strings.
	 *
	 * @param mixed $value Raw value from the payload.
	 * @return array<string, array<int, string>>
	 */
	private function sanitize_directives( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $directive => $values ) {
			if ( ! is_string( $directive ) ) {
				continue;
			}
			$directive = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $directive ) );
			if ( ! is_string( $directive ) || '' === $directive ) {
				continue;
			}

			$items = [];
			if ( is_string( $values ) ) {
				$values = preg_split( '/\s+/', trim( $values ) );
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $item ) {
				if ( ! is_string( $item ) ) {
					continue;
				}
				$item = trim( $item );
				if ( '' === $item ) {
					continue;
				}
				$items[] = sanitize_text_field( $item );
			}

			$clean[ $directive ] = array_values( array_unique( $items ) );
		}

		return $clean;
	}

	/**
	 * Safe starter CSP that permits same-origin only.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function default_csp_directives(): array {
		return [
			'default-src' => [ "'self'" ],
			'script-src'  => [ "'self'" ],
			'style-src'   => [ "'self'", "'unsafe-inline'" ],
			'img-src'     => [ "'self'", 'data:' ],
			'font-src'    => [ "'self'", 'data:' ],
			'connect-src' => [ "'self'" ],
			'object-src'  => [ "'none'" ],
			'base-uri'    => [ "'self'" ],
			'form-action' => [ "'self'" ],
		];
	}

	/**
	 * Resolve the default report URI to our own REST endpoint.
	 */
	private function default_report_uri(): string {
		if ( ! function_exists( 'rest_url' ) ) {
			return '';
		}

		$url = rest_url( CspReportController::ROUTE_NAMESPACE . CspReportController::ROUTE );
		return is_string( $url ) ? $url : '';
	}
}
