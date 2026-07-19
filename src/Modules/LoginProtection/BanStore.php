<?php
/**
 * Ban-management contract consumed by the login-protection runtime.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * The ban lookup + mutation surface runtime emitters contract on.
 *
 * Extracted so collaborators depend on a mockable seam rather than the `final`
 * {@see BanRepository} concretion. {@see BanRepository} implements it.
 */
interface BanStore {

	/**
	 * Whether the given subject currently has an active (non-expired) ban.
	 *
	 * @param string $subject_type  Ban dimension, e.g. `ip` or `username`.
	 * @param string $subject_value The value to check.
	 */
	public function is_banned( string $subject_type, string $subject_value ): bool;

	/**
	 * Create or refresh a ban for the given subject.
	 *
	 * @param string      $subject_type  Ban dimension, e.g. `ip` or `username`.
	 * @param string      $subject_value The banned value.
	 * @param string|null $reason        Optional human-readable reason.
	 * @param int|null    $ttl_minutes   Lifetime in minutes; `null` for an indefinite ban.
	 */
	public function add( string $subject_type, string $subject_value, ?string $reason = null, ?int $ttl_minutes = null ): void;

	/**
	 * Remove a ban for the given subject.
	 *
	 * @param string $subject_type  Ban dimension.
	 * @param string $subject_value The banned value.
	 * @return int Rows deleted (0 or 1).
	 */
	public function remove( string $subject_type, string $subject_value ): int;
}
