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
use WP_UnitTestCase;

/**
 * End-to-end round-trip through the real `authenticate` filter and
 * `wp_login_failed` action, against real transients and the real login-log
 * table: failures accumulate, cross a tier, and the gate then blocks; an
 * allowlisted IP is never counted or locked.
 */
final class AttemptLimiterIntegrationTest extends WP_UnitTestCase {

	private LoginLogRepository $log;

	private BanRepository $bans;

	protected function setUp(): void {
		parent::setUp();
		$this->log = new LoginLogRepository();
		$this->log->install();
		$this->bans = new BanRepository();
		$this->bans->install();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
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
