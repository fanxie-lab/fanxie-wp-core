<?php
/**
 * Tiered brute-force attempt limiter.
 *
 * @package FanxieLab\Warden\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\LoginProtection\Runtime;

use FanxieLab\Warden\Modules\LoginProtection\BanStore;
use FanxieLab\Warden\Modules\LoginProtection\IpResolver;
use FanxieLab\Warden\Modules\LoginProtection\LoginLogRecorder;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Brute-force limiter: counts failures per IP (always) and per username (only
 * when `attempts.lock_by_username` is opted in) in transients, applies tiered
 * lockouts, and blocks locked/banned subjects at `authenticate`.
 *
 * Failure counters (`cnt_*`) live for a day; lockout markers (`lock_*`) live
 * for the crossed tier's window. The gate runs at `authenticate` priority 30 —
 * after WordPress's own credential check — so a WP_Error here reads as the
 * final auth verdict for the request.
 */
final class AttemptLimiter {

	/**
	 * Shared transient key prefix for this module's counters + lockouts.
	 *
	 * @var string
	 */
	private const PREFIX = 'fanxie_warden_lp_';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config `attempts` sub-config snapshot.
	 * @param IpResolver           $ip     Client-IP resolver.
	 * @param LoginLogRecorder     $log    Login event log writer.
	 * @param BanStore             $bans   Persistent ban lookup.
	 */
	public function __construct(
		private readonly array $config,
		private readonly IpResolver $ip,
		private readonly LoginLogRecorder $log,
		private readonly BanStore $bans,
	) {}

	/**
	 * Wire the limiter's hooks when attempt limiting is enabled.
	 */
	public function register_hooks(): void {
		if ( empty( $this->config['enabled'] ) ) {
			return;
		}
		// Priority 30 runs after WordPress's own `wp_authenticate_username_password`.
		add_filter( 'authenticate', [ $this, 'gate' ], 30, 2 );
		add_action( 'wp_login_failed', [ $this, 'on_failed' ] );
		add_action( 'wp_login', [ $this, 'on_success' ], 10, 2 );
	}

	/**
	 * Block the request when the IP or username is currently locked or banned.
	 *
	 * @param mixed $user     Auth result so far (WP_User, WP_Error, or null).
	 * @param mixed $username Attempted login name.
	 * @return mixed WP_Error when blocked, else the incoming `$user` untouched.
	 */
	public function gate( mixed $user, mixed $username ): mixed {
		$ip = $this->ip->resolve();
		if ( $this->is_allowlisted( $ip ) ) {
			return $user;
		}
		$username = is_string( $username ) ? $username : '';

		if ( ! $this->is_locked_or_banned( $ip, $username ) ) {
			return $user;
		}

		$this->log->record( 'blocked_attempt', $ip, $username, null, [] );

		return new WP_Error(
			'fanxie_login_locked',
			__( 'Too many failed attempts. Try again later.', 'fanxie-warden' )
		);
	}

	/**
	 * Count a failed login against the IP (always) and, when opted in via
	 * `attempts.lock_by_username`, the username too.
	 *
	 * @param mixed $username Attempted login name.
	 */
	public function on_failed( mixed $username ): void {
		$ip       = $this->ip->resolve();
		$username = is_string( $username ) ? $username : '';
		if ( $this->is_allowlisted( $ip ) ) {
			return;
		}
		// Echo suppression: while a subject is locked or banned, `gate()` blocks at
		// `authenticate` before any credential check, so the `wp_login_failed` that
		// fired is the echo of our own block — not a genuine credential attempt.
		// Counting or logging it would let an attacker who keeps hammering a locked
		// subject perpetually renew the lock (a DoS lever against the victim) and
		// flood the log with duplicate rows. Suppressing it also stops the counter
		// from auto-escalating on traffic that never reaches a real credential check.
		if ( $this->is_locked_or_banned( $ip, $username ) ) {
			return;
		}
		$this->log->record( 'failed_login', $ip, $username, null, [] );

		// The IP dimension is ALWAYS counted. The username dimension is opt-in via
		// `attempts.lock_by_username` (default off): counting it arms an AUTOMATIC
		// lockout of the targeted account, which an attacker rotating IPs could
		// weaponise into a targeted account-lockout DoS (lock `admin` out at will).
		// Leaving it off removes that out-of-the-box lever. Manual username bans are
		// an explicit admin action and stay enforced in `is_locked_or_banned()`
		// regardless of this toggle.
		$this->bump( 'ip', $ip );
		if ( $this->lock_by_username() ) {
			$this->bump( 'user', $username );
		}
	}

	/**
	 * Clear the failure counters and applied-tier markers on a successful login.
	 *
	 * The rolling counters and their companion `applied_*` markers are reset so a
	 * fresh sequence of failures starts clean and can re-enter every tier. The
	 * active lockout markers are intentionally left untouched: a locked subject can
	 * never reach `wp_login` (the gate returns a WP_Error first), so only the
	 * counting state needs resetting to avoid a stale count re-triggering.
	 *
	 * @param mixed $user_login The user's login name.
	 * @param mixed $user       The authenticated user object (unused).
	 */
	public function on_success( mixed $user_login, mixed $user = null ): void {
		unset( $user );
		$ip = $this->ip->resolve();
		if ( '' !== $ip ) {
			delete_transient( self::count_key( 'ip', $ip ) );
			delete_transient( self::applied_key( 'ip', $ip ) );
		}
		if ( is_string( $user_login ) && '' !== $user_login ) {
			delete_transient( self::count_key( 'user', $user_login ) );
			delete_transient( self::applied_key( 'user', $user_login ) );
		}
	}

	/**
	 * Delete the live failure counter, lockout, and applied-tier transients for
	 * a subject, releasing any active lock without touching persistent bans.
	 *
	 * A static, config-independent entry point (the transient keys derive only
	 * from the subject, never from settings) shared by the admin
	 * `login_protection/clear-lockout` AJAX action and the `wp fx-warden login
	 * unlock` CLI command. `$type` accepts the module's canonical subject types
	 * — `ip` or `username` — and maps `username` onto the internal `user`
	 * counter dimension so callers speak the same vocabulary the bans + log use.
	 *
	 * @param string $type  Subject dimension, `ip` or `username`.
	 * @param string $value The subject value (IP address or login name).
	 */
	public static function clear_subject( string $type, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$dimension = 'ip' === $type ? 'ip' : 'user';

		delete_transient( self::count_key( $dimension, $value ) );
		delete_transient( self::lock_key( $dimension, $value ) );
		delete_transient( self::applied_key( $dimension, $value ) );
	}

	/**
	 * Whether a subject's lockout transient is currently set.
	 *
	 * The read-seam companion to {@see self::clear_subject()}: both derive their
	 * key from the single private {@see self::lock_key()} builder, so the lock-key
	 * format has exactly one source of truth. Used by the `wp fx-warden login unlock
	 * --dry-run` CLI report to inspect lock state without reconstructing the
	 * private key scheme.
	 *
	 * @param string $type  Lockout dimension, `ip` or `user`.
	 * @param string $value The subject value (IP address or login name).
	 * @return bool True when the subject is currently locked in that dimension.
	 */
	public static function is_locked( string $type, string $value ): bool {
		return false !== get_transient( self::lock_key( $type, $value ) );
	}

	/**
	 * Resolve the strongest applicable tier for a failure count.
	 *
	 * Returns the tier with the highest `threshold` that is still `<=` the
	 * failure count, or `null` when the count is below every threshold. Order
	 * of the configured tiers does not matter.
	 *
	 * @param int $failures Current failure count.
	 * @return array{threshold: int, lockout_minutes: int}|null
	 */
	public function tier_for( int $failures ): ?array {
		$match = null;
		foreach ( $this->tiers() as $tier ) {
			if ( $failures >= $tier['threshold'] && ( null === $match || $tier['threshold'] > $match['threshold'] ) ) {
				$match = $tier;
			}
		}
		return $match;
	}

	/**
	 * Increment a subject's failure counter and lock it when a tier is crossed.
	 *
	 * @param string $type  Counter dimension, `ip` or `user`.
	 * @param string $value The subject value.
	 */
	private function bump( string $type, string $value ): void {
		if ( '' === $value ) {
			return;
		}
		$key   = self::count_key( $type, $value );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, DAY_IN_SECONDS );

		$tier = $this->tier_for( $count );
		if ( null === $tier ) {
			return;
		}

		// Only (re-)arm the lock and log a `lockout` when this failure enters a
		// tier STRICTLY higher than the last one already applied to this subject.
		// The `applied_*` marker persists across expired lock windows (it lives as
		// long as the counter), so the counter can keep climbing and cross into each
		// higher tier exactly once. A count that merely re-matches the current tier
		// — e.g. a genuine failure after a lower-tier lock window has expired — will
		// not renew the lock or write a duplicate `lockout` row.
		$applied_key  = self::applied_key( $type, $value );
		$applied_tier = (int) get_transient( $applied_key );
		if ( $tier['threshold'] <= $applied_tier ) {
			// Keep the marker's TTL in lockstep with the counter (both rolling to a
			// full day on every bump). Without this refresh, a low-and-slow attacker
			// whose failures are >24h from the last tier crossing but <24h apart could
			// let `applied_*` expire while the counter survives — resetting
			// `applied_tier` to 0 and re-arming/re-logging the same tier ~once a day.
			set_transient( $applied_key, $applied_tier, DAY_IN_SECONDS );
			return;
		}

		set_transient( $applied_key, $tier['threshold'], DAY_IN_SECONDS );
		set_transient( self::lock_key( $type, $value ), $count, $tier['lockout_minutes'] * MINUTE_IN_SECONDS );
		$this->log->record(
			'lockout',
			'ip' === $type ? $value : '',
			'user' === $type ? $value : '',
			null,
			[ 'tier' => $tier ]
		);
	}

	/**
	 * Whether the subject is currently locked or banned in an enforced dimension.
	 *
	 * Shared by `gate()` (to block the request) and `on_failed()` (to suppress the
	 * echoed `wp_login_failed` a block triggers). Empty dimensions are skipped, and
	 * each ban lookup is only reached when no lock transient already answers.
	 *
	 * Enforced dimensions:
	 *  - IP lock transient (always) and IP ban (always).
	 *  - Manual USERNAME ban (always) — an explicit admin action, distinct from
	 *    automatic lockout, so it is enforced regardless of `lock_by_username`.
	 *  - AUTOMATIC username lock transient — only when `lock_by_username` is on.
	 *    With the toggle off no username lock transient is ever written, but the
	 *    check is gated explicitly so a stale marker can never block a login.
	 *
	 * @param string $ip       Client IP (may be empty).
	 * @param string $username Attempted login name (may be empty).
	 */
	private function is_locked_or_banned( string $ip, string $username ): bool {
		return ( '' !== $ip && false !== get_transient( self::lock_key( 'ip', $ip ) ) )
			|| ( $this->lock_by_username() && '' !== $username && false !== get_transient( self::lock_key( 'user', $username ) ) )
			|| ( '' !== $ip && $this->bans->is_banned( 'ip', $ip ) )
			|| ( '' !== $username && $this->bans->is_banned( 'username', $username ) );
	}

	/**
	 * Whether automatic lockout should also apply to the username dimension.
	 *
	 * Opt-in (default off): reads `attempts.lock_by_username` from the config
	 * snapshot the limiter was constructed with. Governs both the username counter
	 * bump in `on_failed()` and the automatic username lock-transient check in
	 * `is_locked_or_banned()`. Manual username bans are never gated by it.
	 */
	private function lock_by_username(): bool {
		return ! empty( $this->config['lock_by_username'] );
	}

	/**
	 * Whether the resolved IP is on the trusted allowlist.
	 *
	 * @param string $ip Client IP.
	 */
	private function is_allowlisted( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		$allowlist = isset( $this->config['allowlist'] ) && is_array( $this->config['allowlist'] )
			? $this->config['allowlist']
			: [];
		return in_array( $ip, $allowlist, true );
	}

	/**
	 * Normalised lockout tiers from config.
	 *
	 * @return array<int, array{threshold: int, lockout_minutes: int}>
	 */
	private function tiers(): array {
		$raw = isset( $this->config['tiers'] ) && is_array( $this->config['tiers'] ) ? $this->config['tiers'] : [];

		$tiers = [];
		foreach ( $raw as $tier ) {
			if ( is_array( $tier ) && isset( $tier['threshold'], $tier['lockout_minutes'] ) ) {
				$tiers[] = [
					'threshold'       => (int) $tier['threshold'],
					'lockout_minutes' => (int) $tier['lockout_minutes'],
				];
			}
		}
		return $tiers;
	}

	/**
	 * Transient key for a subject's rolling failure counter.
	 *
	 * @param string $type  Counter dimension, `ip` or `user`.
	 * @param string $value The subject value.
	 */
	private static function count_key( string $type, string $value ): string {
		return self::PREFIX . 'cnt_' . $type . '_' . md5( $value );
	}

	/**
	 * Transient key for a subject's active lockout marker.
	 *
	 * @param string $type  Counter dimension, `ip` or `user`.
	 * @param string $value The subject value.
	 */
	private static function lock_key( string $type, string $value ): string {
		return self::PREFIX . 'lock_' . $type . '_' . md5( $value );
	}

	/**
	 * Transient key for a subject's highest-applied tier threshold.
	 *
	 * Outlives individual lock windows (kept for the counter's lifetime) so tiered
	 * escalation crosses into each higher tier exactly once. See `bump()`.
	 *
	 * @param string $type  Counter dimension, `ip` or `user`.
	 * @param string $value The subject value.
	 */
	private static function applied_key( string $type, string $value ): string {
		return self::PREFIX . 'applied_' . $type . '_' . md5( $value );
	}
}
