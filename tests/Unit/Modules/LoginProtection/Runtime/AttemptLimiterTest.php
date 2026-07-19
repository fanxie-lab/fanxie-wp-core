<?php
/**
 * Unit tests for the Login Protection attempt limiter.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\LoginProtection\BanStore;
use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRecorder;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
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

	protected function setUp(): void {
		parent::setUp();
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
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function config(): array {
		return [
			'enabled'            => true,
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
				return str_starts_with( (string) $key, 'fanxie_wp_core_lp_lock_ip_' );
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

		$this->assertContains( 'fanxie_wp_core_lp_cnt_ip_' . md5( '203.0.113.1' ), $deleted );
		$this->assertContains( 'fanxie_wp_core_lp_cnt_user_' . md5( 'bob' ), $deleted );
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
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_lock_' ) ) {
					return false;
				}
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_applied_' ) ) {
					return 3;
				}
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_cnt_' ) ) {
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

		$applied_key = 'fanxie_wp_core_lp_applied_ip_' . md5( '203.0.113.20' );
		$lock_key    = 'fanxie_wp_core_lp_lock_ip_' . md5( '203.0.113.20' );

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
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_lock_' ) ) {
					return false;
				}
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_applied_' ) ) {
					return 3;
				}
				if ( str_starts_with( (string) $key, 'fanxie_wp_core_lp_cnt_' ) ) {
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
}
