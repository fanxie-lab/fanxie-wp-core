<?php
/**
 * Integration tests for the Login Protection attempt limiter.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use WP_Error;

/**
 * End-to-end round-trip through the real `authenticate` filter and
 * `wp_login_failed` action, against real transients and the real login-log
 * table: failures accumulate, cross a tier, and the gate then blocks; an
 * allowlisted IP is never counted or locked.
 */
final class AttemptLimiterIntegrationTest extends LoginProtectionTableTestCase {

	private LoginLogRepository $log;

	private BanRepository $bans;

	protected function setUp(): void {
		// The base case installs and truncates both custom tables before every
		// test; here we only need local repository handles for the assertions.
		parent::setUp();
		$this->log  = new LoginLogRepository();
		$this->bans = new BanRepository();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Count `lockout` rows recorded for a given IP dimension.
	 *
	 * @param string $ip Client IP the lockout was recorded against.
	 */
	private function count_ip_lockouts( string $ip ): int {
		$out = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => $ip,
			],
			1,
			25
		);
		return (int) $out['total'];
	}

	private function make_limiter( array $tiers, array $allowlist = [] ): AttemptLimiter {
		$config  = [
			'enabled'            => true,
			'trust_proxy'        => false,
			'proxy_header'       => '',
			'allowlist'          => $allowlist,
			'tiers'              => $tiers,
			'log_retention_days' => 30,
		];
		$limiter = new AttemptLimiter( $config, new IpResolver( false, '' ), $this->log, $this->bans );
		$limiter->register_hooks();
		return $limiter;
	}

	public function test_failures_cross_tier_then_authenticate_is_blocked_and_lockout_logged(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->make_limiter( [ [ 'threshold' => 2, 'lockout_minutes' => 15 ] ] );

		// Two failures at the real action -> crosses the threshold-2 tier.
		do_action( 'wp_login_failed', 'victim' );
		do_action( 'wp_login_failed', 'victim' );

		// The real `authenticate` filter must now return our lock error.
		$result = apply_filters( 'authenticate', null, 'victim', 'wrong-password' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fanxie_login_locked', $result->get_error_code() );

		// A per-IP `lockout` row was written to the login log.
		$out = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.77',
			],
			1,
			25
		);
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( '203.0.113.77', $out['rows'][0]['ip'] );

		// The block itself was recorded.
		$blocked = $this->log->query( [ 'event_type' => 'blocked_attempt' ], 1, 25 );
		$this->assertGreaterThanOrEqual( 1, $blocked['total'] );
	}

	public function test_locked_subject_repeated_attempts_do_not_renew_lock_or_relog(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
		$this->make_limiter( [ [ 'threshold' => 2, 'lockout_minutes' => 15 ] ] );

		// Two genuine failures cross the threshold-2 tier and arm the lock.
		do_action( 'wp_login_failed', 'victim' );
		do_action( 'wp_login_failed', 'victim' );

		$lock_ip_key   = 'fanxie_wp_core_lp_lock_ip_' . md5( '203.0.113.88' );
		$count_ip_key  = 'fanxie_wp_core_lp_cnt_ip_' . md5( '203.0.113.88' );
		$timeout_key   = '_transient_timeout_' . $lock_ip_key;
		$lockouts_at_2 = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.88',
			],
			1,
			25
		);

		// Baseline captured while locked.
		$this->assertSame( 1, $lockouts_at_2['total'], 'Exactly one lockout row after crossing the tier once.' );
		$this->assertSame( 2, (int) get_transient( $count_ip_key ), 'Counter sits at the tier threshold.' );
		$lock_timeout_before = get_option( $timeout_key );

		// Simulate an attacker hammering the already-locked subject: `gate()`
		// returns our WP_Error, which `wp_signon()` echoes as `wp_login_failed`.
		for ( $i = 0; $i < 5; $i++ ) {
			$result = apply_filters( 'authenticate', null, 'victim', 'wrong-password' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'fanxie_login_locked', $result->get_error_code() );
			do_action( 'wp_login_failed', 'victim' );
		}

		// The lock must not renew and no new `lockout` rows may be written.
		$lockouts_after = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.88',
			],
			1,
			25
		);
		$this->assertSame( 1, $lockouts_after['total'], 'Blocked attempts must not add lockout rows.' );
		$this->assertSame( 2, (int) get_transient( $count_ip_key ), 'Blocked attempts must not bump the counter.' );
		$this->assertSame( $lock_timeout_before, get_option( $timeout_key ), 'The lock TTL must not be extended.' );
	}

	public function test_escalation_arms_each_higher_tier_exactly_once_across_windows(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->make_limiter(
			[
				[
					'threshold'       => 2,
					'lockout_minutes' => 15,
				],
				[
					'threshold'       => 4,
					'lockout_minutes' => 60,
				],
			]
		);

		$lock_ip_key   = 'fanxie_wp_core_lp_lock_ip_' . md5( '203.0.113.99' );
		$lock_user_key = 'fanxie_wp_core_lp_lock_user_' . md5( 'victim' );

		// Reach the first tier (threshold 2): one IP lockout row.
		do_action( 'wp_login_failed', 'victim' );
		do_action( 'wp_login_failed', 'victim' );
		$first = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.99',
			],
			1,
			25
		);
		$this->assertSame( 1, $first['total'], 'First tier arms exactly one IP lockout row.' );

		// Simulate the tier-1 lock window expiring (delete both lock markers).
		delete_transient( $lock_ip_key );
		delete_transient( $lock_user_key );

		// A genuine failure that merely re-matches the current tier (count 3, still
		// < threshold 4) must NOT re-arm or re-log.
		do_action( 'wp_login_failed', 'victim' );
		$same_tier = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.99',
			],
			1,
			25
		);
		$this->assertSame( 1, $same_tier['total'], 'Re-matching the current tier must not add a lockout row.' );

		// One more genuine failure reaches the higher tier (count 4 == threshold 4).
		do_action( 'wp_login_failed', 'victim' );
		$second = $this->log->query(
			[
				'event_type' => 'lockout',
				'ip'         => '203.0.113.99',
			],
			1,
			25
		);
		$this->assertSame( 2, $second['total'], 'The higher tier arms exactly once.' );

		// The higher-tier lock is now active and the gate blocks again.
		$result = apply_filters( 'authenticate', null, 'victim', 'wrong-password' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fanxie_login_locked', $result->get_error_code() );
	}

	public function test_low_and_slow_keeps_applied_marker_in_lockstep_with_counter(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.111';
		$this->make_limiter(
			[
				[
					'threshold'       => 2,
					'lockout_minutes' => 15,
				],
				[
					'threshold'       => 4,
					'lockout_minutes' => 60,
				],
			]
		);

		$lock_ip_key         = 'fanxie_wp_core_lp_lock_ip_' . md5( '203.0.113.111' );
		$lock_user_key       = 'fanxie_wp_core_lp_lock_user_' . md5( 'victim' );
		$count_ip_key        = 'fanxie_wp_core_lp_cnt_ip_' . md5( '203.0.113.111' );
		$applied_ip_key      = 'fanxie_wp_core_lp_applied_ip_' . md5( '203.0.113.111' );
		$applied_timeout_key = '_transient_timeout_' . $applied_ip_key;

		// Cross tier 1: exactly one IP `lockout` row, applied marker records it.
		do_action( 'wp_login_failed', 'victim' );
		do_action( 'wp_login_failed', 'victim' );
		$this->assertSame( 1, $this->count_ip_lockouts( '203.0.113.111' ), 'One lockout after crossing tier 1.' );
		$this->assertSame( 2, (int) get_transient( $applied_ip_key ), 'Applied marker records the tier-1 threshold.' );

		// Low-and-slow: the tier-1 window has expired (lock gone) but the rolling
		// counter survives. Simulate the applied marker being on the verge of
		// expiry — as it would be ~24h after the last tier crossing while the
		// counter keeps getting refreshed by fresh failures.
		delete_transient( $lock_ip_key );
		delete_transient( $lock_user_key );
		update_option( $applied_timeout_key, time() + 5 );

		// A patient genuine failure re-matching tier 1 (count 3, still < threshold 4).
		do_action( 'wp_login_failed', 'victim' );

		// The counter is still alive, and the fix refreshes the applied marker's
		// TTL to a full day in lockstep so it can never expire out from under a
		// still-alive counter and reset `applied_tier` to 0.
		$this->assertSame( 3, (int) get_transient( $count_ip_key ), 'The rolling counter survived the low-and-slow gap.' );
		$this->assertSame( 2, (int) get_transient( $applied_ip_key ), 'Applied marker is still present after the bump.' );
		$this->assertGreaterThan(
			time() + DAY_IN_SECONDS - 60,
			(int) get_option( $applied_timeout_key ),
			'Applied marker TTL was refreshed to ~a day in lockstep with the counter.'
		);

		// No same-tier re-arm or duplicate `lockout` row.
		$this->assertSame( 1, $this->count_ip_lockouts( '203.0.113.111' ), 'Re-matching the current tier must not add a lockout row.' );
		$this->assertFalse( get_transient( $lock_ip_key ), 'The lock must not be re-armed within the same tier.' );
	}

	public function test_allowlisted_ip_never_locks(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.50';
		$this->make_limiter(
			[ [ 'threshold' => 2, 'lockout_minutes' => 15 ] ],
			[ '198.51.100.50' ]
		);

		// Hammer well past the highest threshold.
		for ( $i = 0; $i < 5; $i++ ) {
			do_action( 'wp_login_failed', 'admin' );
		}

		// No lockout transient was ever set for the allowlisted IP.
		$this->assertFalse( get_transient( 'fanxie_wp_core_lp_lock_ip_' . md5( '198.51.100.50' ) ) );

		// The gate lets the attempt pass through (never our lock error).
		$result = apply_filters( 'authenticate', null, 'admin', 'wrong-password' );
		if ( $result instanceof WP_Error ) {
			$this->assertNotSame( 'fanxie_login_locked', $result->get_error_code() );
		}

		// Nothing was logged for the allowlisted subject.
		$out = $this->log->query( [], 1, 25 );
		$this->assertSame( 0, $out['total'] );
	}
}
