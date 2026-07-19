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
}
