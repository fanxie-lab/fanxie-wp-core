<?php
/**
 * Emits configured security headers on `send_headers`.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders;

use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime worker that turns the module's config into HTTP headers.
 *
 * Idempotent — before emitting any header we consult `headers_list()` and
 * skip names already set by upstream (web server, reverse proxy, another
 * plugin). The final header array passes through the
 * `fanxie_warden/security_headers/headers` filter so integrators can have
 * the last word.
 *
 * The two side-effectful helpers (`existing_header_names()` and
 * `is_https()`) are exposed as injectable callables so unit tests can swap
 * them without monkey-patching internal PHP functions.
 */
final class HeaderEmitter {

	/**
	 * Callable resolving to the list of already-sent headers (raw, per
	 * `headers_list()`).
	 *
	 * @var callable():array<int, string>
	 */
	private $existing_header_resolver;

	/**
	 * Callable returning whether the current request is HTTPS.
	 *
	 * @var callable():bool
	 */
	private $is_https_resolver;

	/**
	 * Callable that performs the actual `header()` emission. Injectable
	 * so unit tests can observe what would be sent without touching the
	 * global header buffer.
	 *
	 * @var callable(string, string):void
	 */
	private $header_writer;

	/**
	 * Constructor.
	 *
	 * @param SecurityHeaders $module                    Module whose config drives emission.
	 * @param callable|null   $existing_header_resolver  Returns `headers_list()`-shaped array.
	 * @param callable|null   $is_https_resolver         Returns bool for "is this connection HTTPS".
	 * @param callable|null   $header_writer             Receives `(string $name, string $value)` for each emitted header.
	 */
	public function __construct(
		private readonly SecurityHeaders $module,
		?callable $existing_header_resolver = null,
		?callable $is_https_resolver = null,
		?callable $header_writer = null,
	) {
		$this->existing_header_resolver = $existing_header_resolver ?? static function (): array {
			return function_exists( 'headers_list' ) ? headers_list() : [];
		};
		$this->is_https_resolver        = $is_https_resolver ?? static function (): bool {
			return function_exists( 'is_ssl' ) ? (bool) is_ssl() : false;
		};
		$this->header_writer            = $header_writer ?? static function ( string $name, string $value ): void {
			// Guard against late emission once PHP has already flushed the
			// response headers (CLI tests, output-buffered plugins flushing
			// early, etc.). `header()` would emit a non-fatal warning and
			// return void, but under `beStrictAboutOutputDuringTests` that
			// warning is promoted to a failure. Checking `headers_sent()`
			// first makes the writer a no-op in those situations without
			// masking the broader problem (nothing to emit to).
			if ( headers_sent() ) {
				return;
			}
			header( $name . ': ' . $value, false );
		};
	}

	/**
	 * Build the list of headers to emit and dispatch them.
	 */
	public function emit(): void {
		$config  = $this->module->get_config();
		$headers = $this->build_headers( $config );

		if ( [] === $headers ) {
			return;
		}

		$existing = $this->existing_header_names();
		$emit_csp = $this->should_emit_csp();

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_string( $value ) || '' === $value ) {
				continue;
			}

			// Guard: CSP headers are scoped to frontend requests only.
			//
			// The admin panel (block editor, core inline scripts, workers
			// spawned from `blob:` URLs) trips our default public-site CSP
			// and floods the violations table with non-actionable noise
			// from trusted WP internals. AJAX, cron, and REST requests
			// don't render an HTML document, so CSP doesn't apply and
			// emitting the header wastes bytes. Do NOT remove this guard
			// without also revisiting the default policy — see
			// docs/hooks.md and the module PRD.
			//
			// The `fanxie_warden/security_headers/csp_emit_context`
			// filter (applied once per request in `should_emit_csp()`)
			// lets integrators opt back in for admin/REST emission.
			if ( ! $emit_csp && $this->is_csp_header_name( $name ) ) {
				continue;
			}

			// Idempotency — never overwrite an existing header. Compare
			// case-insensitively per RFC 7230.
			if ( in_array( strtolower( $name ), $existing, true ) ) {
				continue;
			}

			( $this->header_writer )( $name, $value );
		}

		// Note: we intentionally do NOT backfill the DB to delete
		// pre-existing admin-origin violation rows. The 30-day prune
		// handles stale entries, and the guard above stops new noise at
		// the source — a one-off cleanup would add deploy complexity for
		// a self-healing problem.
	}

	/**
	 * Whether the current request context should receive a CSP header.
	 *
	 * Short-circuits to `false` for admin, AJAX, cron, and REST requests;
	 * runs the decision through the public
	 * `fanxie_warden/security_headers/csp_emit_context` filter so
	 * integrators with tailored policies can force emission anyway.
	 */
	private function should_emit_csp(): bool {
		$is_admin = function_exists( 'is_admin' ) && is_admin();
		$is_ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_cron  = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_rest  = defined( 'REST_REQUEST' ) && REST_REQUEST;

		$should_emit = ! ( $is_admin || $is_ajax || $is_cron || $is_rest );

		/**
		 * Filter: fanxie_warden/security_headers/csp_emit_context
		 *
		 * Controls whether CSP is emitted in the current request context.
		 * By default CSP is skipped on admin, AJAX, cron, and REST
		 * requests to avoid false-positive violations from trusted
		 * internal WordPress code (block editor workers, core inline
		 * scripts, etc.).
		 *
		 * @since 0.1.0-dev
		 *
		 * @param bool $should_emit Current decision (`true` = emit CSP).
		 */
		return (bool) apply_filters( 'fanxie_warden/security_headers/csp_emit_context', $should_emit );
	}

	/**
	 * Whether a given header name is a CSP header (enforce or report-only).
	 *
	 * @param string $name Header name, any case.
	 */
	private function is_csp_header_name( string $name ): bool {
		$lower = strtolower( $name );

		return 'content-security-policy' === $lower
			|| 'content-security-policy-report-only' === $lower;
	}

	/**
	 * Compose the full header array for the current config.
	 *
	 * @param array<string, mixed> $config Sanitised module config (nested shape).
	 * @return array<string, string>
	 */
	public function build_headers( array $config ): array {
		$headers = [];
		$is_ssl  = $this->is_https();

		$headers_cfg = isset( $config['headers'] ) && is_array( $config['headers'] ) ? $config['headers'] : [];
		$csp_cfg     = isset( $config['csp'] ) && is_array( $config['csp'] ) ? $config['csp'] : [];

		$hsts     = isset( $headers_cfg['hsts'] ) && is_array( $headers_cfg['hsts'] ) ? $headers_cfg['hsts'] : [];
		$xfo      = isset( $headers_cfg['xfo'] ) && is_array( $headers_cfg['xfo'] ) ? $headers_cfg['xfo'] : [];
		$xcto     = isset( $headers_cfg['xcto'] ) && is_array( $headers_cfg['xcto'] ) ? $headers_cfg['xcto'] : [];
		$referrer = isset( $headers_cfg['referrer'] ) && is_array( $headers_cfg['referrer'] ) ? $headers_cfg['referrer'] : [];
		$perms    = isset( $headers_cfg['permissions'] ) && is_array( $headers_cfg['permissions'] ) ? $headers_cfg['permissions'] : [];
		$cache    = isset( $headers_cfg['cache_control'] ) && is_array( $headers_cfg['cache_control'] ) ? $headers_cfg['cache_control'] : [];

		// HSTS — only ever over HTTPS.
		if ( $is_ssl && ! empty( $hsts['enabled'] ) ) {
			$max_age = max( 0, (int) ( $hsts['max_age'] ?? 31536000 ) );
			$value   = 'max-age=' . $max_age;
			if ( ! empty( $hsts['include_subdomains'] ) ) {
				$value .= '; includeSubDomains';
			}
			$headers['Strict-Transport-Security'] = $value;
		}

		if ( ! empty( $xfo['enabled'] ) ) {
			$value = (string) ( $xfo['value'] ?? 'SAMEORIGIN' );
			if ( '' !== $value ) {
				$headers['X-Frame-Options'] = $value;
			}
		}

		if ( ! empty( $xcto['enabled'] ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}

		if ( ! empty( $referrer['enabled'] ) ) {
			$value = (string) ( $referrer['value'] ?? 'strict-origin-when-cross-origin' );
			if ( '' !== $value ) {
				$headers['Referrer-Policy'] = $value;
			}
		}

		if ( ! empty( $perms['enabled'] ) ) {
			$value = (string) ( $perms['value'] ?? '' );
			if ( '' !== $value ) {
				$headers['Permissions-Policy'] = $value;
			}
		}

		if ( ! empty( $cache['enabled'] ) ) {
			$value = (string) ( $cache['value'] ?? 'public, max-age=3600' );
			if ( '' !== $value ) {
				$headers['Cache-Control'] = $value;
			}
		}

		// CSP.
		$mode = (string) ( $csp_cfg['mode'] ?? CspPolicy::MODE_OFF );

		if ( CspPolicy::MODE_OFF !== $mode ) {
			$directives = $csp_cfg['directives'] ?? [];
			if ( ! is_array( $directives ) ) {
				$directives = [];
			}

			$learning_mode = ! empty( $csp_cfg['learning_mode'] );
			$report_uri    = (string) ( $csp_cfg['report_uri'] ?? '' );
			$report_uri    = '' === $report_uri ? null : $report_uri;

			$policy = new CspPolicy( $directives, $mode, $report_uri );
			$value  = $policy->serialise();

			if ( '' !== $value ) {
				if ( CspPolicy::MODE_ENFORCE === $mode ) {
					$headers['Content-Security-Policy'] = $value;
					if ( $learning_mode ) {
						// Dual-emit: keep collecting reports while enforcing.
						$headers['Content-Security-Policy-Report-Only'] = $value;
					}
				} else {
					$headers['Content-Security-Policy-Report-Only'] = $value;
				}
			}
		}

		/**
		 * Filter: fanxie_warden/security_headers/headers
		 *
		 * Last-chance hook to add / remove / modify the header array before
		 * emission. Return value MUST be a `header-name => header-value` map.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param array<string, string> $headers Name → value map.
		 * @param array<string, mixed>  $config  Sanitised module config (nested shape).
		 */
		$filtered = apply_filters( 'fanxie_warden/security_headers/headers', $headers, $config );

		return is_array( $filtered ) ? $filtered : $headers;
	}

	/**
	 * Return the lowercased list of header names already sent.
	 *
	 * @return array<int, string>
	 */
	private function existing_header_names(): array {
		$raw   = ( $this->existing_header_resolver )();
		$names = [];

		if ( ! is_array( $raw ) ) {
			return [];
		}

		foreach ( $raw as $header ) {
			if ( ! is_string( $header ) ) {
				continue;
			}

			$colon = strpos( $header, ':' );
			if ( false === $colon ) {
				continue;
			}

			$names[] = strtolower( trim( substr( $header, 0, $colon ) ) );
		}

		return $names;
	}

	/**
	 * Whether the current request is served over HTTPS.
	 */
	private function is_https(): bool {
		return (bool) ( $this->is_https_resolver )();
	}
}
