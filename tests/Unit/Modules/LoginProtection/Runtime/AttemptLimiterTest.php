<?php
/**
 * Unit tests for the Login Protection attempt limiter.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\LoginProtection\BanStore;
use FanxieLab\Warden\Modules\LoginProtection\IpResolver;
use FanxieLab\Warden\Modules\LoginProtection\LoginLogRecorder;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\AttemptLimiter;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Pure tier logic + allowlist bypass. Repo-touching paths (bump/gate lockout)
 * are exercised in the integration suite against real transients + tables.
 *
 * Note: `IpResolver` is `final` (unmockable), so a real resolver seeded via
 * `$_SERVER['REMOTE_ADDR']` stands in for the collaborator. The log + ban
 * collaborators are mocked through the narrow `LoginLogRecorder` / `BanStore`
 * interfaces the repositories implement.
 */
final class AttemptLimiterTest extends TestCase {

	/**
	 * `$_SERVER` as it was before the test, restored verbatim in tear-down so no
	 * key the test set (or unset) leaks into later tests or WP's shutdown cron.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_backup = [];

	protected function setUp(): void {
		parent::setUp();

		$this->server_backup = $_SERVER;
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );

		// WordPress defines these time constants; unit mode has no WP runtime, so
		// the `bump()` path (which passes them to `set_transient`) needs them faked.
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function config( bool $lock_by_username = false ): array {
		return [
			'enabled'            => true,
			'lock_by_username'   => $lock_by_username,
			'trust_proxy'        => false,
			'proxy_header'       => '',
			'allowlist'          => [ '198.51.100.50' ],
			'tiers'              => [
				[
					'threshold'       => 3,
					'lockout_minutes' => 15,
				],
				[
					'threshold'       => 6,
					'lockout_minutes' => 60,
				],
			],
			'log_retention_days' => 30,
		];
	}

	public function test_tier_for_returns_highest_matching_tier(): void {
		$limiter = new AttemptLimiter(
			$this->config(),
			new IpResolver( false, '' ),
			Mockery::mock( LoginLogRecorder::class ),
			Mockery::mock( BanStore::class )
		);

		$this->assertNull( $limiter->tier_for( 2 ) );
		$this->assertSame( 15, $limiter->tier_for( 3 )['lockout_minutes'] );
		$this->assertSame( 15, $limiter->tier_for( 5 )['lockout_minutes'] );
		$this->assertSame( 60, $limiter->tier_for( 9 )['lockout_minutes'] );
	}

	public function test_allowlisted_ip_is_never_gated(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.50';

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldNotReceive( 'is_banned' );
		Functions\when( 'get_transient' )->justReturn( false );

		$limiter = new AttemptLimiter(
			$this->config(),
			new IpResolver( false, '' ),
			Mockery::mock( LoginLogRecorder::class ),
			$bans
		);

		$user = new \stdClass();
		$this->assertSame( $user, $limiter->gate( $user, 'admin' ) );
	}

	public function test_on_failed_is_suppressed_while_subject_is_locked(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		// A present IP-lock transient marks this `wp_login_failed` as the echo of
		// our own `gate()` block, not a genuine credential attempt.
		Functions\when( 'get_transient' )->alias(
			static function ( $key ): bool {
				return str_starts_with( (string) $key, 'fanxie_warden_lp_lock_ip_' );
			}
		);

		$set = [];
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$set ): bool {
				$set[] = $key;
				return true;
			}
		);

		// Neither a `failed_login` nor a `lockout` row may be written.
		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldNotReceive( 'record' );

		// A present lock short-circuits before any ban lookup.
		$bans = Mockery::mock( BanStore::class );
		$bans->shouldNotReceive( 'is_banned' );

		$limiter = new AttemptLimiter(
			$this->config(),
			new IpResolver( false, '' ),
			$log,
			$bans
		);

		$limiter->on_failed( 'victim' );

		// No counter bump and no lock re-arm: the counter TTL is never touched.
		$this->assertSame( [], $set, 'A locked subject must not write any transient.' );
	}

	public function test_on_success_clears_failure_counters_for_ip_and_user(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$deleted = [];
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		$limiter = new AttemptLimiter(
			$this->config(),
			new IpResolver( false, '' ),
			Mockery::mock( LoginLogRecorder::class ),
			Mockery::mock( BanStore::class )
		);

		$limiter->on_success( 'bob' );

		$this->assertContains( 'fanxie_warden_lp_cnt_ip_' . md5( '203.0.113.1' ), $deleted );
		$this->assertContains( 'fanxie_warden_lp_cnt_user_' . md5( 'bob' ), $deleted );
	}

	/**
	 * The `is_locked()` read seam reports true only when the subject's lock
	 * transient is set, and it must query the exact key its own private
	 * `lock_key()` builder produces (proven via reflection, not a hardcoded
	 * mirror) — so a drift in the key format cannot silently break lock reporting.
	 */
	public function test_is_locked_reads_the_lock_transient_via_lock_key(): void {
		$lock_key    = new \ReflectionMethod( AttemptLimiter::class, 'lock_key' );
		$ip_lock_key = (string) $lock_key->invoke( null, 'ip', '203.0.113.55' );

		$queried = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$queried, $ip_lock_key ): mixed {
				$queried[] = (string) $key;
				return (string) $key === $ip_lock_key ? 42 : false;
			}
		);

		// A subject whose lock transient is set reads as locked, and the key looked
		// up is exactly the one `lock_key()` builds.
		$this->assertTrue( AttemptLimiter::is_locked( 'ip', '203.0.113.55' ) );
		$this->assertContains( $ip_lock_key, $queried, 'is_locked() must query the key lock_key() produces.' );

		// A subject with no lock transient reads as unlocked.
		$this->assertFalse( AttemptLimiter::is_locked( 'user', 'nobody' ) );
	}

	/**
	 * Threshold gating: a failure whose count merely re-matches the already-applied
	 * tier must not re-arm the lock or write another `lockout` row — but it MUST
	 * still refresh the `applied_*` marker's TTL so it stays in lockstep with the
	 * rolling counter (the low-and-slow lockstep fix).
	 */
	public function test_bump_re_matching_applied_tier_refreshes_marker_without_relogging(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';

		// Counter at 2 (bumps to 3 == the already-applied threshold-3 tier); the
		// applied marker already records 3; no lock is present.
		Functions\when( 'get_transient' )->alias(
			static function ( $key ): mixed {
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_lock_' ) ) {
					return false;
				}
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_applied_' ) ) {
					return 3;
				}
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_cnt_' ) ) {
					return 2;
				}
				return false;
			}
		);

		$sets = [];
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$sets ): bool {
				$sets[ (string) $key ] = [
					'value' => $value,
					'ttl'   => $ttl,
				];
				return true;
			}
		);

		$events = [];
		$log    = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->andReturnUsing(
			static function ( string $event_type ) use ( &$events ): void {
				$events[] = $event_type;
			}
		);

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );

		$limiter = new AttemptLimiter( $this->config(), new IpResolver( false, '' ), $log, $bans );

		// Empty username -> only the IP dimension bumps (the user bump short-circuits).
		$limiter->on_failed( '' );

		$applied_key = 'fanxie_warden_lp_applied_ip_' . md5( '203.0.113.20' );
		$lock_key    = 'fanxie_warden_lp_lock_ip_' . md5( '203.0.113.20' );

		$this->assertNotContains( 'lockout', $events, 'Re-matching the applied tier must not re-log a lockout.' );
		$this->assertContains( 'failed_login', $events, 'The genuine failure is still recorded.' );
		$this->assertArrayNotHasKey( $lock_key, $sets, 'The lock must not be re-armed within the same tier.' );
		$this->assertArrayHasKey( $applied_key, $sets, 'The applied marker TTL must be refreshed in lockstep with the counter.' );
		$this->assertSame( 3, $sets[ $applied_key ]['value'], 'The refreshed applied marker keeps its existing tier value.' );
		$this->assertSame( DAY_IN_SECONDS, $sets[ $applied_key ]['ttl'], 'The applied marker is refreshed to a full day.' );
	}

	/**
	 * Threshold gating: a failure whose count crosses a strictly higher tier arms
	 * the lock and writes exactly one `lockout` row.
	 */
	public function test_bump_crossing_a_strictly_higher_tier_logs_lockout_once(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.21';

		// Counter at 5 (bumps to 6 == the strictly-higher threshold-6 tier); the
		// applied marker still records the lower threshold 3; no lock is present.
		Functions\when( 'get_transient' )->alias(
			static function ( $key ): mixed {
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_lock_' ) ) {
					return false;
				}
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_applied_' ) ) {
					return 3;
				}
				if ( str_starts_with( (string) $key, 'fanxie_warden_lp_cnt_' ) ) {
					return 5;
				}
				return false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$lockouts = 0;
		$log      = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->andReturnUsing(
			static function ( string $event_type ) use ( &$lockouts ): void {
				if ( 'lockout' === $event_type ) {
					++$lockouts;
				}
			}
		);

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );

		$limiter = new AttemptLimiter( $this->config(), new IpResolver( false, '' ), $log, $bans );

		// Empty username -> only the IP dimension bumps, so exactly one lockout.
		$limiter->on_failed( '' );

		$this->assertSame( 1, $lockouts, 'Crossing a strictly higher tier arms exactly one lockout.' );
	}

	/**
	 * Username-lockout opt-in: with the DEFAULT config (`lock_by_username` off) a
	 * failure counts ONLY against the IP dimension. The username counter is never
	 * touched, so a targeted account can never be auto-locked from rotating IPs.
	 */
	public function test_on_failed_counts_only_the_ip_dimension_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';

		// No locks and no prior counters/markers: bump() lifts the counter to 1,
		// below the lowest tier (threshold 3), so only the counter transient is set.
		Functions\when( 'get_transient' )->justReturn( false );

		$set = [];
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$set ): bool {
				$set[] = (string) $key;
				return true;
			}
		);

		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->once();

		// Not locked or banned in any dimension -> the failure is a genuine attempt.
		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );

		$limiter = new AttemptLimiter( $this->config(), new IpResolver( false, '' ), $log, $bans );

		// A real attempted username is supplied — it must still not be counted.
		$limiter->on_failed( 'admin' );

		$this->assertContains(
			'fanxie_warden_lp_cnt_ip_' . md5( '203.0.113.30' ),
			$set,
			'The IP dimension is always counted.'
		);
		$this->assertNotContains(
			'fanxie_warden_lp_cnt_user_' . md5( 'admin' ),
			$set,
			'The username dimension must not be counted while lock_by_username is off.'
		);
	}

	/**
	 * Username-lockout opt-in: with `lock_by_username` ON the username dimension is
	 * counted again (the pre-toggle behaviour), so automatic account lockout works.
	 */
	public function test_on_failed_counts_the_username_dimension_when_opted_in(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.31';

		Functions\when( 'get_transient' )->justReturn( false );

		$set = [];
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$set ): bool {
				$set[] = (string) $key;
				return true;
			}
		);

		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->once();

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );

		$limiter = new AttemptLimiter( $this->config( true ), new IpResolver( false, '' ), $log, $bans );

		$limiter->on_failed( 'admin' );

		$this->assertContains(
			'fanxie_warden_lp_cnt_ip_' . md5( '203.0.113.31' ),
			$set,
			'The IP dimension is always counted.'
		);
		$this->assertContains(
			'fanxie_warden_lp_cnt_user_' . md5( 'admin' ),
			$set,
			'The username dimension is counted once opted in.'
		);
	}

	/**
	 * A MANUAL username ban is an explicit admin action, not an automatic lockout,
	 * so `gate()` must block it even while `lock_by_username` is OFF.
	 */
	public function test_gate_blocks_a_manual_username_ban_even_with_lock_by_username_off(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.32';

		// No lock transients in any dimension.
		Functions\when( 'get_transient' )->justReturn( false );

		// The IP is not banned, but the username carries a manual ban.
		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->with( 'ip', '203.0.113.32' )->andReturn( false );
		$bans->shouldReceive( 'is_banned' )->with( 'username', 'victim' )->andReturn( true );

		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->with( 'blocked_attempt', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any() )->once();

		$limiter = new AttemptLimiter( $this->config(), new IpResolver( false, '' ), $log, $bans );

		$result = $limiter->gate( null, 'victim' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fanxie_login_locked', $result->get_error_code() );
	}

	/**
	 * With `lock_by_username` OFF, an automatic username LOCK transient must be
	 * ignored by `gate()` — no username lock is ever written when the toggle is off,
	 * and this proves a stale one could never block a login either.
	 */
	public function test_gate_ignores_a_username_lock_transient_when_lock_by_username_off(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.33';

		$user_lock_key = 'fanxie_warden_lp_lock_user_' . md5( 'victim' );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( $user_lock_key ): mixed {
				return (string) $key === $user_lock_key ? 7 : false;
			}
		);

		// Neither dimension is banned.
		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );

		// A pass-through never records a blocked_attempt.
		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldNotReceive( 'record' );

		$limiter = new AttemptLimiter( $this->config(), new IpResolver( false, '' ), $log, $bans );

		$user = new \stdClass();
		$this->assertSame(
			$user,
			$limiter->gate( $user, 'victim' ),
			'A username lock transient must not block while lock_by_username is off.'
		);
	}

	/**
	 * With `lock_by_username` ON, the automatic username LOCK transient blocks at
	 * `gate()` on its own — no ban lookup needed.
	 */
	public function test_gate_enforces_a_username_lock_transient_when_opted_in(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.34';

		$user_lock_key = 'fanxie_warden_lp_lock_user_' . md5( 'victim' );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( $user_lock_key ): mixed {
				return (string) $key === $user_lock_key ? 9 : false;
			}
		);

		// The username lock short-circuits before any ban lookup is reached.
		$bans = Mockery::mock( BanStore::class );
		$bans->shouldNotReceive( 'is_banned' );

		$log = Mockery::mock( LoginLogRecorder::class );
		$log->shouldReceive( 'record' )->with( 'blocked_attempt', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any() )->once();

		$limiter = new AttemptLimiter( $this->config( true ), new IpResolver( false, '' ), $log, $bans );

		$result = $limiter->gate( null, 'victim' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'fanxie_login_locked', $result->get_error_code() );
	}
}
