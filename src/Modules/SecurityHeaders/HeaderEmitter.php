<?php
/**
 * Emits configured security headers on `send_headers`.
 *
 * @package FanxieLab\WPCore\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\SecurityHeaders;

use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime worker that turns the module's config into HTTP headers.
 *
 * Idempotent — before emitting any header we consult `headers_list()` and
 * skip names already set by upstream (web server, reverse proxy, another
 * plugin). The final header array passes through the
 * `fanxie_wp_core/security_headers/headers` filter so integrators can have
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
	 * Constructor.
	 *
	 * @param SecurityHeaders $module                    Module whose config drives emission.
	 * @param callable|null   $existing_header_resolver  Returns `headers_list()`-shaped array.
	 * @param callable|null   $is_https_resolver         Returns bool for "is this connection HTTPS".
	 */
	public function __construct(
		private readonly SecurityHeaders $module,
		?callable $existing_header_resolver = null,
		?callable $is_https_resolver = null,
	) {
		$this->existing_header_resolver = $existing_header_resolver ?? static function (): array {
			return function_exists( 'headers_list' ) ? headers_list() : [];
		};
		$this->is_https_resolver        = $is_https_resolver ?? static function (): bool {
			return function_exists( 'is_ssl' ) ? (bool) is_ssl() : false;
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

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_string( $value ) || '' === $value ) {
				continue;
			}

			// Idempotency — never overwrite an existing header. Compare
			// case-insensitively per RFC 7230.
			if ( in_array( strtolower( $name ), $existing, true ) ) {
				continue;
			}

			header( $name . ': ' . $value, false );
		}
	}

	/**
	 * Compose the full header array for the current config.
	 *
	 * @param array<string, mixed> $config Sanitised module config.
	 * @return array<string, string>
	 */
	public function build_headers( array $config ): array {
		$headers = [];
		$is_ssl  = $this->is_https();

		// HSTS — only ever over HTTPS.
		if ( $is_ssl && ! empty( $config['headers_hsts_enabled'] ) ) {
			$max_age = max( 0, (int) ( $config['headers_hsts_max_age'] ?? 31536000 ) );
			$value   = 'max-age=' . $max_age;
			if ( ! empty( $config['headers_hsts_include_subdomains'] ) ) {
				$value .= '; includeSubDomains';
			}
			$headers['Strict-Transport-Security'] = $value;
		}

		if ( ! empty( $config['headers_xfo_enabled'] ) ) {
			$value = (string) ( $config['headers_xfo_value'] ?? 'SAMEORIGIN' );
			if ( '' !== $value ) {
				$headers['X-Frame-Options'] = $value;
			}
		}

		if ( ! empty( $config['headers_xcto_enabled'] ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}

		if ( ! empty( $config['headers_referrer_enabled'] ) ) {
			$value = (string) ( $config['headers_referrer_value'] ?? 'strict-origin-when-cross-origin' );
			if ( '' !== $value ) {
				$headers['Referrer-Policy'] = $value;
			}
		}

		if ( ! empty( $config['headers_permissions_enabled'] ) ) {
			$value = (string) ( $config['headers_permissions_value'] ?? '' );
			if ( '' !== $value ) {
				$headers['Permissions-Policy'] = $value;
			}
		}

		if ( ! empty( $config['headers_cache_control_enabled'] ) ) {
			$value = (string) ( $config['headers_cache_control_value'] ?? 'public, max-age=3600' );
			if ( '' !== $value ) {
				$headers['Cache-Control'] = $value;
			}
		}

		// CSP.
		$mode = (string) ( $config['csp_mode'] ?? CspPolicy::MODE_OFF );

		if ( CspPolicy::MODE_OFF !== $mode ) {
			$directives = $config['csp_directives'] ?? [];
			if ( ! is_array( $directives ) ) {
				$directives = [];
			}

			$learning_mode = ! empty( $config['csp_learning_mode'] );
			$report_uri    = (string) ( $config['csp_report_uri'] ?? '' );
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
		 * Filter: fanxie_wp_core/security_headers/headers
		 *
		 * Last-chance hook to add / remove / modify the header array before
		 * emission. Return value MUST be a `header-name => header-value` map.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param array<string, string> $headers Name → value map.
		 * @param array<string, mixed>  $config  Sanitised module config.
		 */
		$filtered = apply_filters( 'fanxie_wp_core/security_headers/headers', $headers, $config );

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
