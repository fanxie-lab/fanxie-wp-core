<?php
/**
 * Content-Security-Policy value object.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders\Csp;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish representation of a Content-Security-Policy.
 *
 * Holds the directive map, the active mode (`off` / `report-only` / `enforce`)
 * and an optional `report-uri`. `serialise()` formats the header value per
 * the CSP spec and applies the `fanxie_warden/security_headers/csp_directives`
 * filter so integrators can adjust directives at the last moment.
 *
 * `merge()` is dedup-aware and order-preserving — the original policy's value
 * ordering is retained, with new values from the incoming fragment appended.
 */
final class CspPolicy {

	public const MODE_OFF         = 'off';
	public const MODE_REPORT_ONLY = 'report-only';
	public const MODE_ENFORCE     = 'enforce';

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, string>> $directives Directive → values map.
	 * @param string                            $mode      One of MODE_* constants.
	 * @param string|null                       $report_uri Optional report endpoint.
	 */
	public function __construct(
		private readonly array $directives,
		private readonly string $mode = self::MODE_REPORT_ONLY,
		private readonly ?string $report_uri = null,
	) {}

	/**
	 * Accessor for the directive map.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function directives(): array {
		return $this->directives;
	}

	/**
	 * Active mode.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Report endpoint.
	 */
	public function report_uri(): ?string {
		return $this->report_uri;
	}

	/**
	 * Return a new policy with `$other` merged into this one.
	 *
	 * For each directive, we take the union of the two value lists, preserving
	 * the original ordering (current policy first, newcomers appended) and
	 * dropping duplicates.
	 *
	 * @param array<string, array<int, string>> $other Directive fragment to merge.
	 */
	public function merge( array $other ): self {
		$merged = $this->directives;

		foreach ( $other as $directive => $values ) {
			if ( ! is_string( $directive ) || '' === $directive || ! is_array( $values ) ) {
				continue;
			}

			$existing = $merged[ $directive ] ?? [];
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				if ( ! in_array( $value, $existing, true ) ) {
					$existing[] = $value;
				}
			}
			$merged[ $directive ] = $existing;
		}

		return new self( $merged, $this->mode, $this->report_uri );
	}

	/**
	 * Serialise the policy to a CSP header value.
	 *
	 * Fires `fanxie_warden/security_headers/csp_directives` so integrators
	 * can observe / mutate the directive map before serialisation.
	 */
	public function serialise(): string {
		/**
		 * Filter: fanxie_warden/security_headers/csp_directives
		 *
		 * Last chance to mutate the CSP directive map before it is serialised
		 * into a header value.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param array<string, array<int, string>> $directives Directive → values map.
		 * @param string                             $mode      Active CSP mode.
		 */
		$directives = apply_filters( 'fanxie_warden/security_headers/csp_directives', $this->directives, $this->mode );
		if ( ! is_array( $directives ) ) {
			$directives = $this->directives;
		}

		$parts = [];
		foreach ( $directives as $name => $values ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}

			$clean = [];
			foreach ( $values as $value ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$clean[] = trim( $value );
				}
			}

			if ( [] === $clean ) {
				// Bare directive (e.g. `upgrade-insecure-requests`).
				$parts[] = $name;
				continue;
			}

			$parts[] = $name . ' ' . implode( ' ', $clean );
		}

		$header = implode( '; ', $parts );

		if ( null !== $this->report_uri && '' !== $this->report_uri && self::MODE_OFF !== $this->mode ) {
			$header .= ( '' === $header ? '' : '; ' ) . 'report-uri ' . $this->report_uri;
		}

		return $header;
	}
}
