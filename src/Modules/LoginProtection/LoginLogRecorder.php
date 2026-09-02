<?php
/**
 * Narrow write-side contract for the login event log.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * The single method runtime emitters need to append a login event.
 *
 * Extracted so collaborators depend on a mockable seam rather than the `final`
 * {@see LoginLogRepository} concretion. {@see LoginLogRepository} implements it.
 */
interface LoginLogRecorder {

	/**
	 * Append a login event to the log.
	 *
	 * @param string               $event_type Short event slug (e.g. `failed_login`).
	 * @param string               $ip         Client IP address.
	 * @param string               $username   Attempted username.
	 * @param int|null             $user_id    Resolved user id when the username matched an account.
	 * @param array<string, mixed> $context    Extra detail, JSON-encoded into the `context` column.
	 */
	public function record( string $event_type, string $ip, string $username, ?int $user_id, array $context = [] ): void;
}
