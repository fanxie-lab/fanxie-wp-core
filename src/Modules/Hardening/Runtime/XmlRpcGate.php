<?php
/**
 * XML-RPC gate with three enforcement modes.
 *
 * @package FanxieLab\WPCore\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Three enforcement modes, applied at boot and each request:
 *
 *   - `disabled`         — full kill-switch via `xmlrpc_enabled`.
 *   - `restrict_methods` — drop `system.multicall` + `pingback.*` from the
 *                          method table (`xmlrpc_methods`).
 *   - `restrict_ips`     — allowlist the caller's `REMOTE_ADDR`; otherwise
 *                          kill the connection before method dispatch.
 *   - `off`              — do nothing.
 *
 * Regardless of mode, the `X-Pingback` autodiscovery header is stripped via
 * `wp_headers` when XML-RPC is not in `off` mode.
 */
final class XmlRpcGate {

	public const MODE_DISABLED         = 'disabled';
	public const MODE_RESTRICT_METHODS = 'restrict_methods';
	public const MODE_RESTRICT_IPS     = 'restrict_ips';
	public const MODE_OFF              = 'off';

	public const ALL_MODES = [
		self::MODE_DISABLED,
		self::MODE_RESTRICT_METHODS,
		self::MODE_RESTRICT_IPS,
		self::MODE_OFF,
	];

	/**
	 * Methods stripped when `restrict_methods` is active.
	 *
	 * @var array<int, string>
	 */
	private const DANGEROUS_METHOD_PREFIXES = [
		'system.multicall',
		'pingback.',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach mode-specific hooks.
	 */
	public function register_hooks(): void {
		$mode = $this->mode();

		if ( self::MODE_OFF === $mode ) {
			return;
		}

		// Always strip the X-Pingback header when we're actively restricting
		// XML-RPC — it's the discovery vector for pingback abuse.
		add_filter( 'wp_headers', [ $this, 'strip_pingback_header' ] );

		switch ( $mode ) {
			case self::MODE_DISABLED:
				// Belt-and-braces: the `xmlrpc_enabled` filter stops WP's own
				// `wp-login` auth, `xmlrpc_methods => empty array` kills
				// method discovery (`system.listMethods` et al), and the
				// `xmlrpc_call` hard-block 403s the request before any method
				// handler runs. `PHP_INT_MAX` so we override later filters.
				add_filter( 'xmlrpc_enabled', '__return_false' );
				add_filter( 'xmlrpc_methods', '__return_empty_array', PHP_INT_MAX );
				add_action( 'xmlrpc_call', [ $this, 'hard_block' ] );
				break;
			case self::MODE_RESTRICT_METHODS:
				// `PHP_INT_MAX` so we run after every other `xmlrpc_methods`
				// filter — third-party plugins that re-add `pingback.*` can't
				// sneak past us.
				add_filter( 'xmlrpc_methods', [ $this, 'filter_methods' ], PHP_INT_MAX );
				break;
			case self::MODE_RESTRICT_IPS:
				add_filter( 'xmlrpc_methods', [ $this, 'filter_methods_for_ip' ], PHP_INT_MAX );
				break;
		}
	}

	/**
	 * Terminate XML-RPC dispatch with a 403 before any method handler runs.
	 *
	 * Hooked to `xmlrpc_call` when XML-RPC is in `disabled` mode. Some client
	 * libraries ignore an empty method table and keep retrying auth; this
	 * short-circuits them with an unambiguous HTTP status.
	 */
	public function hard_block(): void {
		if ( function_exists( 'status_header' ) ) {
			status_header( 403 );
		}

		if ( function_exists( 'wp_die' ) ) {
			wp_die(
				esc_html__( 'XML-RPC services are disabled on this site.', 'fanxie-wp-core' ),
				'',
				[ 'response' => 403 ]
			);
		}
	}

	/**
	 * Remove `X-Pingback` from the outgoing header map.
	 *
	 * @param array<string, string>|mixed $headers Raw header map.
	 * @return array<string, string>
	 */
	public function strip_pingback_header( mixed $headers ): array {
		if ( ! is_array( $headers ) ) {
			return [];
		}

		unset( $headers['X-Pingback'], $headers['x-pingback'] );

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- PHPStan type narrow.
		/** Headers array narrowed by the `is_array` guard above. @var array<string, string> $headers */
		return $headers;
	}

	/**
	 * Drop dangerous XML-RPC methods from the method table.
	 *
	 * @param array<string, callable>|mixed $methods Method name → callback.
	 * @return array<string, callable>
	 */
	public function filter_methods( mixed $methods ): array {
		if ( ! is_array( $methods ) ) {
			return [];
		}

		foreach ( array_keys( $methods ) as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			foreach ( self::DANGEROUS_METHOD_PREFIXES as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					unset( $methods[ $name ] );
					break;
				}
			}
		}

		/** Methods array narrowed by the `is_array` guard above. @var array<string, callable> $methods */
		return $methods;
	}

	/**
	 * Kill every method unless the caller's IP is on the allowlist.
	 *
	 * We return an empty method table (so every call resolves to
	 * `xmlrpc_invalid_method`) rather than throwing — that matches WP's
	 * existing UX on unknown methods.
	 *
	 * @param array<string, callable>|mixed $methods Method table.
	 * @return array<string, callable>
	 */
	public function filter_methods_for_ip( mixed $methods ): array {
		if ( ! is_array( $methods ) ) {
			$methods = [];
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$allow  = $this->allowed_ips();

		if ( '' !== $remote && in_array( $remote, $allow, true ) ) {
			/** Methods array narrowed above. @var array<string, callable> $methods */
			return $methods;
		}

		return [];
	}

	/**
	 * Resolve the normalised IP allowlist, filterable at runtime.
	 *
	 * @return array<int, string>
	 */
	private function allowed_ips(): array {
		$ips = isset( $this->config['xmlrpc']['allowed_ips'] ) && is_array( $this->config['xmlrpc']['allowed_ips'] )
			? $this->config['xmlrpc']['allowed_ips']
			: [];

		$clean = [];
		foreach ( $ips as $ip ) {
			if ( ! is_string( $ip ) ) {
				continue;
			}
			$ip = trim( $ip );
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$clean[] = $ip;
			}
		}

		/**
		 * Filter: fanxie_wp_core/hardening/xmlrpc_allowed_ips
		 *
		 * Override the XML-RPC IP allowlist at runtime.
		 *
		 * @since 0.2.0-dev
		 *
		 * @param array<int, string>   $clean  Sanitised IP allowlist.
		 * @param array<string, mixed> $config Module config snapshot.
		 */
		$filtered = apply_filters( 'fanxie_wp_core/hardening/xmlrpc_allowed_ips', $clean, $this->config );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $clean;
	}

	/**
	 * Resolve the configured mode, defaulting to `disabled`.
	 */
	private function mode(): string {
		$mode = isset( $this->config['xmlrpc']['mode'] ) && is_string( $this->config['xmlrpc']['mode'] )
			? $this->config['xmlrpc']['mode']
			: self::MODE_DISABLED;

		return in_array( $mode, self::ALL_MODES, true ) ? $mode : self::MODE_DISABLED;
	}
}
