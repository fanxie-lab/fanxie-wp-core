<?php
/**
 * Immutable value object wrapping a full environment health report.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

defined( 'ABSPATH' ) || exit;

/**
 * The complete report returned by every Environment Health AJAX sub-action.
 *
 * Serialised shape (frozen contract with the Vue tab + dashboard widget):
 *
 *   {
 *     generated_at: number,                     // unix ts
 *     cached_until: number,                     // unix ts
 *     counts: { ok, warning, critical, unknown },
 *     checks: HealthCheck[]
 *   }
 *
 * `counts` is derived rather than supplied so it can never drift from
 * `checks`, and every status key is always present (zero-filled) so the
 * frontend can render its pills without existence checks.
 */
final class HealthReport {

	/**
	 * Constructor.
	 *
	 * @param array<int, HealthCheck> $checks       Ordered checks.
	 * @param int                     $generated_at Unix timestamp the report was built.
	 * @param int                     $cached_until Unix timestamp the cached copy expires.
	 */
	public function __construct(
		private readonly array $checks,
		private readonly int $generated_at,
		private readonly int $cached_until,
	) {}

	/**
	 * The checks in this report.
	 *
	 * @return array<int, HealthCheck>
	 */
	public function checks(): array {
		return $this->checks;
	}

	/**
	 * Tally of checks per status, zero-filled across every known status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array_fill_keys( HealthCheck::ALL_STATUSES, 0 );

		foreach ( $this->checks as $check ) {
			$status = in_array( $check->status, HealthCheck::ALL_STATUSES, true )
				? $check->status
				: HealthCheck::STATUS_UNKNOWN;

			++$counts[ $status ];
		}

		return $counts;
	}

	/**
	 * Worst status present in the report, using `critical > warning > unknown > ok`.
	 *
	 * Drives the dashboard widget's headline pill. `unknown` outranks `ok`
	 * deliberately: "we could not check" should not read as "all clear".
	 */
	public function worst_status(): string {
		return self::worst_from_counts( $this->counts() );
	}

	/**
	 * Same ranking, applied to an already-serialised `counts` map.
	 *
	 * The dashboard widget renders straight from the cached payload and never
	 * rehydrates value objects, so it needs this without a report instance.
	 *
	 * @param array<string, mixed> $counts Status → count map.
	 */
	public static function worst_from_counts( array $counts ): string {
		foreach ( [ HealthCheck::STATUS_CRITICAL, HealthCheck::STATUS_WARNING, HealthCheck::STATUS_UNKNOWN ] as $status ) {
			if ( (int) ( $counts[ $status ] ?? 0 ) > 0 ) {
				return $status;
			}
		}

		return HealthCheck::STATUS_OK;
	}

	/**
	 * Serialise for the AJAX payload.
	 *
	 * @return array{generated_at: int, cached_until: int, counts: array<string, int>, checks: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'generated_at' => $this->generated_at,
			'cached_until' => $this->cached_until,
			'counts'       => $this->counts(),
			'checks'       => array_map(
				static fn ( HealthCheck $check ): array => $check->to_array(),
				array_values( $this->checks )
			),
		];
	}
}
