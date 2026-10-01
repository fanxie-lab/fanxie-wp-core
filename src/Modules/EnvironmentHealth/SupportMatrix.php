<?php
/**
 * Date-driven software support matrix for the Environment Health module.
 *
 * @package FanxieLab\Warden\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\EnvironmentHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Support/EOL matrix for PHP, MySQL, and MariaDB — expressed as dates, not
 * as version comparisons.
 *
 * PRD §7.2 originally specified thresholds as literal comparisons ("warning
 * below 8.2, critical below 8.1"). That encoding rots: the day PHP 8.2 leaves
 * security support the plugin starts lying, and nothing in the codebase makes
 * that visible. This class inverts the model — each branch stores the two
 * dates that actually define its support status:
 *
 *   - `active`   — end of active/premier support (bug fixes stop).
 *   - `security` — end of security-only support (the true EOL date).
 *
 * Status is then *derived* by comparing those dates against "now", so
 * "PHP 8.2 is EOL" becomes true on 1 Jan 2027 without a code change. Only
 * genuinely new branches need adding, and a branch that is absent from the
 * matrix reports `unknown` rather than being silently guessed at.
 *
 * Sources (verified {@see self::REVIEWED_ON}):
 *   - PHP:     https://www.php.net/supported-versions.php + https://www.php.net/eol.php
 *   - MySQL:   https://endoflife.date/mysql (Oracle premier → `active`,
 *              extended → `security`)
 *   - MariaDB: https://endoflife.date/mariadb (community support end; MariaDB
 *              publishes a single date, so `active` and `security` coincide)
 *
 * PHP 7.0–7.3 rows carry approximate month-level dates: every one of those
 * branches has been EOL for years, so the derived status (`critical`) is
 * correct regardless of the exact day. The 8.x rows — the only ones where the
 * boundary is live — are exact and sourced from php.net.
 */
final class SupportMatrix {

	/**
	 * Date the matrix below was last verified against upstream sources.
	 *
	 * Surfaced in the report payload so an operator (and a future maintainer)
	 * can see how fresh the data is without reading the source. Bump this
	 * whenever the tables are re-checked.
	 *
	 * @var string
	 */
	public const REVIEWED_ON = '2026-09-02';

	/**
	 * Lifecycle phase: branch is in full/active support.
	 *
	 * @var string
	 */
	public const PHASE_ACTIVE = 'active';

	/**
	 * Lifecycle phase: branch receives security fixes only.
	 *
	 * @var string
	 */
	public const PHASE_SECURITY = 'security';

	/**
	 * Lifecycle phase: branch is past its end-of-life date.
	 *
	 * @var string
	 */
	public const PHASE_EOL = 'eol';

	/**
	 * Lifecycle phase: branch is not present in the matrix.
	 *
	 * @var string
	 */
	public const PHASE_UNKNOWN = 'unknown';

	/**
	 * How close to EOL a still-supported branch may get before we warn.
	 *
	 * Matters most for MariaDB, whose active and security dates coincide:
	 * without this window a branch would flip straight from `ok` to
	 * `critical` overnight.
	 *
	 * @var int
	 */
	public const APPROACHING_EOL_DAYS = 180;

	/**
	 * PHP branches. Keys are `major.minor`.
	 *
	 * @var array<string, array{active: string, security: string}>
	 */
	private const PHP = [
		'5.6' => [
			'active'   => '2017-01-19',
			'security' => '2018-12-31',
		],
		'7.0' => [
			'active'   => '2018-01-04',
			'security' => '2019-01-10',
		],
		'7.1' => [
			'active'   => '2018-12-01',
			'security' => '2019-12-01',
		],
		'7.2' => [
			'active'   => '2019-11-30',
			'security' => '2020-11-30',
		],
		'7.3' => [
			'active'   => '2020-12-06',
			'security' => '2021-12-06',
		],
		'7.4' => [
			'active'   => '2021-11-28',
			'security' => '2022-11-28',
		],
		'8.0' => [
			'active'   => '2022-11-26',
			'security' => '2023-11-26',
		],
		'8.1' => [
			'active'   => '2023-11-25',
			'security' => '2025-12-31',
		],
		'8.2' => [
			'active'   => '2024-12-31',
			'security' => '2026-12-31',
		],
		'8.3' => [
			'active'   => '2025-12-31',
			'security' => '2027-12-31',
		],
		'8.4' => [
			'active'   => '2026-12-31',
			'security' => '2028-12-31',
		],
		'8.5' => [
			'active'   => '2027-12-31',
			'security' => '2029-12-31',
		],
	];

	/**
	 * MySQL branches (Oracle premier support → `active`, extended → `security`).
	 *
	 * The 9.x innovation releases get roughly a quarter of support each; both
	 * dates are set to that single end date.
	 *
	 * @var array<string, array{active: string, security: string}>
	 */
	private const MYSQL = [
		'5.5' => [
			'active'   => '2015-12-31',
			'security' => '2018-12-31',
		],
		'5.6' => [
			'active'   => '2018-02-28',
			'security' => '2021-02-28',
		],
		'5.7' => [
			'active'   => '2020-10-31',
			'security' => '2023-10-31',
		],
		'8.0' => [
			'active'   => '2025-04-30',
			'security' => '2026-04-30',
		],
		'8.4' => [
			'active'   => '2029-04-30',
			'security' => '2032-04-30',
		],
		'9.0' => [
			'active'   => '2024-10-15',
			'security' => '2024-10-15',
		],
		'9.1' => [
			'active'   => '2025-01-21',
			'security' => '2025-01-21',
		],
		'9.2' => [
			'active'   => '2025-04-15',
			'security' => '2025-04-15',
		],
		'9.3' => [
			'active'   => '2025-07-22',
			'security' => '2025-07-22',
		],
		'9.4' => [
			'active'   => '2025-10-21',
			'security' => '2025-10-21',
		],
		'9.5' => [
			'active'   => '2026-01-20',
			'security' => '2026-01-20',
		],
		'9.6' => [
			'active'   => '2026-04-21',
			'security' => '2026-04-21',
		],
		'9.7' => [
			'active'   => '2034-04-21',
			'security' => '2037-04-21',
		],
	];

	/**
	 * MariaDB branches. MariaDB publishes one community-support end date per
	 * release, so `active` and `security` are identical and the
	 * {@see self::APPROACHING_EOL_DAYS} window supplies the warning phase.
	 *
	 * @var array<string, array{active: string, security: string}>
	 */
	private const MARIADB = [
		'5.5'   => [
			'active'   => '2020-04-11',
			'security' => '2020-04-11',
		],
		'10.0'  => [
			'active'   => '2019-03-31',
			'security' => '2019-03-31',
		],
		'10.1'  => [
			'active'   => '2020-10-17',
			'security' => '2020-10-17',
		],
		'10.2'  => [
			'active'   => '2022-05-23',
			'security' => '2022-05-23',
		],
		'10.3'  => [
			'active'   => '2023-05-25',
			'security' => '2023-05-25',
		],
		'10.4'  => [
			'active'   => '2024-06-18',
			'security' => '2024-06-18',
		],
		'10.5'  => [
			'active'   => '2025-06-24',
			'security' => '2025-06-24',
		],
		'10.6'  => [
			'active'   => '2026-07-06',
			'security' => '2026-07-06',
		],
		'10.7'  => [
			'active'   => '2023-02-09',
			'security' => '2023-02-09',
		],
		'10.8'  => [
			'active'   => '2023-05-20',
			'security' => '2023-05-20',
		],
		'10.9'  => [
			'active'   => '2023-08-22',
			'security' => '2023-08-22',
		],
		'10.10' => [
			'active'   => '2023-11-17',
			'security' => '2023-11-17',
		],
		'10.11' => [
			'active'   => '2028-02-16',
			'security' => '2028-02-16',
		],
		'11.0'  => [
			'active'   => '2024-06-06',
			'security' => '2024-06-06',
		],
		'11.1'  => [
			'active'   => '2024-08-21',
			'security' => '2024-08-21',
		],
		'11.2'  => [
			'active'   => '2024-11-21',
			'security' => '2024-11-21',
		],
		'11.3'  => [
			'active'   => '2024-05-29',
			'security' => '2024-05-29',
		],
		'11.4'  => [
			'active'   => '2029-05-29',
			'security' => '2029-05-29',
		],
		'11.5'  => [
			'active'   => '2024-11-21',
			'security' => '2024-11-21',
		],
		'11.6'  => [
			'active'   => '2025-02-13',
			'security' => '2025-02-13',
		],
		'11.7'  => [
			'active'   => '2025-05-12',
			'security' => '2025-05-12',
		],
		'11.8'  => [
			'active'   => '2028-06-04',
			'security' => '2028-06-04',
		],
		'12.0'  => [
			'active'   => '2025-11-18',
			'security' => '2025-11-18',
		],
		'12.1'  => [
			'active'   => '2026-02-13',
			'security' => '2026-02-13',
		],
		'12.2'  => [
			'active'   => '2026-05-28',
			'security' => '2026-05-28',
		],
		'12.3'  => [
			'active'   => '2029-06-12',
			'security' => '2029-06-12',
		],
	];

	/**
	 * Evaluate a PHP version string against the matrix.
	 *
	 * @param string   $version Raw version (e.g. `8.2.14`).
	 * @param int|null $now     Unix timestamp to evaluate against (tests).
	 *
	 * @return array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null}
	 */
	public static function php( string $version, ?int $now = null ): array {
		return self::evaluate( self::PHP, self::branch( $version ), $now );
	}

	/**
	 * Evaluate a MySQL version string against the matrix.
	 *
	 * @param string   $version Raw version (e.g. `8.0.36`).
	 * @param int|null $now     Unix timestamp to evaluate against (tests).
	 *
	 * @return array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null}
	 */
	public static function mysql( string $version, ?int $now = null ): array {
		return self::evaluate( self::MYSQL, self::branch( $version ), $now );
	}

	/**
	 * Evaluate a MariaDB version string against the matrix.
	 *
	 * @param string   $version Raw version (e.g. `10.6.16-MariaDB-log`).
	 * @param int|null $now     Unix timestamp to evaluate against (tests).
	 *
	 * @return array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null}
	 */
	public static function mariadb( string $version, ?int $now = null ): array {
		return self::evaluate( self::MARIADB, self::branch( $version ), $now );
	}

	/**
	 * Reduce a version string to its `major.minor` branch key.
	 *
	 * Tolerates the decorations database servers append to `@@version`
	 * (`10.6.16-MariaDB-1:10.6.16+maria~ubu2004`, `8.0.35-0ubuntu0.22.04.1`)
	 * by matching only the leading numeric segments.
	 *
	 * @param string $version Raw version string.
	 * @return string Branch key, or an empty string when unparseable.
	 */
	public static function branch( string $version ): string {
		if ( 1 !== preg_match( '/(\d+)\.(\d+)/', $version, $matches ) ) {
			return '';
		}

		return $matches[1] . '.' . $matches[2];
	}

	/**
	 * Whether MariaDB is the server behind a raw version/server-info string.
	 *
	 * @param string $server_info Raw `SELECT VERSION()` / `mysqli::$server_info` output.
	 */
	public static function is_mariadb( string $server_info ): bool {
		return false !== stripos( $server_info, 'mariadb' );
	}

	/**
	 * Core derivation: compare a branch's support dates against "now".
	 *
	 * Ordering matters — EOL is checked before security-only, and the
	 * "approaching EOL" window is only reachable while both dates are still
	 * in the future.
	 *
	 * @param array<string, array{active: string, security: string}> $matrix Branch table.
	 * @param string                                                 $branch Branch key.
	 * @param int|null                                               $now    Timestamp override.
	 *
	 * @return array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null}
	 */
	private static function evaluate( array $matrix, string $branch, ?int $now ): array {
		$now = null !== $now ? $now : time();

		if ( '' === $branch || ! isset( $matrix[ $branch ] ) ) {
			return [
				'branch'         => $branch,
				'status'         => HealthCheck::STATUS_UNKNOWN,
				'phase'          => self::PHASE_UNKNOWN,
				'active_until'   => null,
				'security_until' => null,
				'days_remaining' => null,
			];
		}

		$entry       = $matrix[ $branch ];
		$active_ts   = self::end_of_day( $entry['active'] );
		$security_ts = self::end_of_day( $entry['security'] );

		$days_remaining = (int) floor( ( $security_ts - $now ) / DAY_IN_SECONDS );

		if ( $now > $security_ts ) {
			$status = HealthCheck::STATUS_CRITICAL;
			$phase  = self::PHASE_EOL;
		} elseif ( $now > $active_ts ) {
			$status = HealthCheck::STATUS_WARNING;
			$phase  = self::PHASE_SECURITY;
		} elseif ( $days_remaining <= self::APPROACHING_EOL_DAYS ) {
			$status = HealthCheck::STATUS_WARNING;
			$phase  = self::PHASE_ACTIVE;
		} else {
			$status = HealthCheck::STATUS_OK;
			$phase  = self::PHASE_ACTIVE;
		}

		return [
			'branch'         => $branch,
			'status'         => $status,
			'phase'          => $phase,
			'active_until'   => $entry['active'],
			'security_until' => $entry['security'],
			'days_remaining' => $days_remaining,
		];
	}

	/**
	 * Convert a `Y-m-d` matrix date into the last second of that day, UTC.
	 *
	 * Support windows are inclusive of their final day — a branch whose
	 * security support ends on 31 Dec 2026 is still supported *on* 31 Dec.
	 *
	 * @param string $date Date in `Y-m-d` form.
	 */
	private static function end_of_day( string $date ): int {
		$timestamp = strtotime( $date . ' 23:59:59 UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}
}
