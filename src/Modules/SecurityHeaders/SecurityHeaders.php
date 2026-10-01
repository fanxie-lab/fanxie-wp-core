<?php
/**
 * Security Headers module entry point.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\ModuleBase;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPolicy;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPresetLibrary;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspReportController;

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
 * Config shape: nested per the PRD + Vue `types.ts` contract
 * (`config.headers.hsts.enabled`, `config.csp.mode`, …). `ModuleBase`'s
 * schema sanitiser walks dot-path field ids to keep the storage shape in
 * lock-step with the TypeScript types.
 */
final class SecurityHeaders extends ModuleBase {

	public const MODULE_ID = 'security-headers';

	/**
	 * Daily cron hook used to prune stale violations.
	 *
	 * @var string
	 */
	public const PRUNE_HOOK = 'fanxie_warden_csp_violations_prune';

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
		return __( 'Security Headers', 'fanxie-warden' );
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
	 * Default configuration — nested to match the Vue `SecurityHeadersConfig`
	 * TypeScript contract (see `assets/admin/src/modules/SecurityHeaders/types.ts`).
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return [
			'headers' => [
				'hsts'          => [
					// HSTS is OFF by default. Once a browser caches an HSTS
					// header it will refuse plain HTTP for `max_age` seconds,
					// which is a one-way ticket to outage on any domain that
					// is not fully and permanently HTTPS. Operators must opt
					// in deliberately. `includeSubDomains` is likewise off
					// because it can lock out subdomains that haven't yet
					// migrated to TLS.
					'enabled'            => false,
					'max_age'            => 31536000,
					'include_subdomains' => false,
				],
				'xfo'           => [
					'enabled' => true,
					'value'   => 'SAMEORIGIN',
				],
				'xcto'          => [
					'enabled' => true,
				],
				'referrer'      => [
					'enabled' => true,
					'value'   => 'strict-origin-when-cross-origin',
				],
				'permissions'   => [
					'enabled' => true,
					'value'   => 'camera=(), microphone=(), geolocation=()',
				],
				'cache_control' => [
					'enabled' => false,
					'value'   => 'public, max-age=3600',
				],
			],
			'csp'     => [
				'mode'          => CspPolicy::MODE_REPORT_ONLY,
				'learning_mode' => true,
				'directives'    => $this->default_csp_directives(),
				'report_uri'    => $this->default_report_uri(),
			],
		];
	}

	/**
	 * Schema consumed by the admin UI and the ModuleBase sanitiser.
	 *
	 * Field ids are dot-paths into the nested config declared in
	 * `get_default_config()`. The `csp.directives` field carries a structured
	 * `array<string, string[]>` payload, so it uses `sanitizer_callback`
	 * instead of a scalar sanitiser identifier.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			// HSTS.
			[
				'id'        => 'headers.hsts.enabled',
				'label'     => __( 'Enable Strict-Transport-Security', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Off by default. Only enable on a site that is fully and permanently HTTPS — once cached, browsers will refuse plain HTTP for the configured max-age.', 'fanxie-warden' ),
			],
			[
				'id'        => 'headers.hsts.max_age',
				'label'     => __( 'HSTS max-age (seconds)', 'fanxie-warden' ),
				'type'      => 'int',
				'default'   => 31536000,
				'sanitizer' => 'absint',
			],
			[
				'id'        => 'headers.hsts.include_subdomains',
				'label'     => __( 'Include subdomains', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			],

			// X-Frame-Options.
			[
				'id'        => 'headers.xfo.enabled',
				'label'     => __( 'Enable X-Frame-Options', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers.xfo.value',
				'label'     => __( 'X-Frame-Options value', 'fanxie-warden' ),
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
				'id'        => 'headers.xcto.enabled',
				'label'     => __( 'Enable X-Content-Type-Options: nosniff', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// Referrer-Policy.
			[
				'id'        => 'headers.referrer.enabled',
				'label'     => __( 'Enable Referrer-Policy', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers.referrer.value',
				'label'     => __( 'Referrer-Policy value', 'fanxie-warden' ),
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
				'id'        => 'headers.permissions.enabled',
				'label'     => __( 'Enable Permissions-Policy', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers.permissions.value',
				'label'     => __( 'Permissions-Policy value', 'fanxie-warden' ),
				'type'      => 'text',
				'default'   => 'camera=(), microphone=(), geolocation=()',
				'sanitizer' => 'text',
			],

			// Cache-Control.
			[
				'id'        => 'headers.cache_control.enabled',
				'label'     => __( 'Enable Cache-Control (non-authenticated pages)', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Off by default — a wrong value can break dynamic pages.', 'fanxie-warden' ),
			],
			[
				'id'        => 'headers.cache_control.value',
				'label'     => __( 'Cache-Control value', 'fanxie-warden' ),
				'type'      => 'text',
				'default'   => 'public, max-age=3600',
				'sanitizer' => 'text',
			],

			// CSP.
			[
				'id'        => 'csp.mode',
				'label'     => __( 'Content-Security-Policy mode', 'fanxie-warden' ),
				'type'      => 'select',
				'default'   => CspPolicy::MODE_REPORT_ONLY,
				'sanitizer' => 'key',
				'options'   => [
					CspPolicy::MODE_OFF         => __( 'Off', 'fanxie-warden' ),
					CspPolicy::MODE_REPORT_ONLY => __( 'Report only', 'fanxie-warden' ),
					CspPolicy::MODE_ENFORCE     => __( 'Enforce', 'fanxie-warden' ),
				],
			],
			[
				'id'        => 'csp.learning_mode',
				'label'     => __( 'CSP learning mode (dual-emit)', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Keep collecting violation reports while the policy is enforced.', 'fanxie-warden' ),
			],
			[
				'id'                 => 'csp.directives',
				'label'              => __( 'CSP directives', 'fanxie-warden' ),
				'type'               => 'textarea',
				'default'            => $this->default_csp_directives(),
				'sanitizer_callback' => [ $this, 'sanitize_directives' ],
			],
			[
				'id'        => 'csp.report_uri',
				'label'     => __( 'CSP report-uri', 'fanxie-warden' ),
				'type'      => 'text',
				'default'   => $this->default_report_uri(),
				'sanitizer' => 'url',
			],
		];
	}

	/**
	 * Register every hook the module needs.
	 *
	 * With no module-level enabled gate, admin AJAX/REST plumbing and runtime
	 * emitters register together. Runtime consumers (HeaderEmitter,
	 * CspReportController) consult settings on each request to decide whether
	 * to do any work.
	 */
	public function register_hooks(): void {
		// Admin AJAX surface (Vue SPA config read/write, presets, violations).
		$ajax = new AjaxController( $this, $this->repository, $this->presets );
		$ajax->register( $this->ajax_router );

		// Custom table install runs regardless of CSP state so historical
		// violations remain queryable even after CSP is turned off.
		//
		// `register_hooks()` is invoked by the ModuleRegistry on `init:5`, so
		// hooking install() onto an earlier `init` priority would silently
		// no-op — WordPress never re-enters earlier priorities of an action
		// that is already firing. We invoke install() directly when we are
		// already inside `init`; otherwise we schedule it for the next
		// available `init` priority so pre-init bootstraps (e.g. tests) still
		// get the table created on time.
		if ( function_exists( 'did_action' ) && did_action( 'init' ) > 0 ) {
			$this->repository->install();
		} else {
			add_action(
				'init',
				function (): void {
					$this->repository->install();
				},
				-1
			);
		}

		// Header emission — HeaderEmitter short-circuits per-header based on
		// `headers.<name>.enabled` and skips HSTS on non-HTTPS requests.
		$emitter = new HeaderEmitter( $this );
		add_action( 'send_headers', [ $emitter, 'emit' ] );

		// REST endpoint for CSP reports. The route is always registered so
		// cached browsers posting to a stale URL still get a clean 204; the
		// controller itself checks `csp.mode` and drops reports when CSP is
		// switched off (see CspReportController::handle()).
		add_action(
			'rest_api_init',
			function (): void {
				$controller = new CspReportController( $this->repository, $this );
				$controller->register();
			}
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
	 * Normalise a CSP directive map — keys are directive names (lower-case,
	 * no whitespace), values are lists of strings.
	 *
	 * Public so it can be wired as a `sanitizer_callback` in the schema; that
	 * also makes it convenient to re-use from callers that hold arbitrary
	 * directive payloads (e.g. preset application).
	 *
	 * @param mixed $value Raw value from the payload.
	 * @return array<string, array<int, string>>
	 */
	public function sanitize_directives( mixed $value ): array {
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
