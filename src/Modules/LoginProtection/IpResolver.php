<?php
/**
 * Client IP resolver for the Login Protection module.
 *
 * @package FanxieLab\Warden\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the client IP for rate-limiting. REMOTE_ADDR only unless a trusted
 * proxy header is explicitly configured — forwarded headers are attacker-
 * controlled and would otherwise allow lockout evasion or victim framing.
 */
final class IpResolver {

	/**
	 * Constructor.
	 *
	 * @param bool   $trust_proxy  Whether to honour the configured forwarded header.
	 * @param string $proxy_header $_SERVER key to read the forwarded IP from (e.g. HTTP_X_FORWARDED_FOR).
	 */
	public function __construct( private readonly bool $trust_proxy, private readonly string $proxy_header ) {}

	/**
	 * Resolve the client IP.
	 *
	 * Returns REMOTE_ADDR unless proxy trust is enabled, in which case the
	 * left-most valid public IP from the configured header wins, falling back
	 * to REMOTE_ADDR. Returns '' when nothing valid is available.
	 */
	public function resolve(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated via FILTER_VALIDATE_IP below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$remote = false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( ! $this->trust_proxy || '' === $this->proxy_header || ! isset( $_SERVER[ $this->proxy_header ] ) ) {
			return $remote;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated per-candidate below.
		$forwarded = (string) $_SERVER[ $this->proxy_header ];
		foreach ( explode( ',', $forwarded ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $candidate;
			}
		}
		return $remote;
	}
}
