<?php
/**
 * Integration tests for the Login Protection per-role session timeout.
 *
 * @package FanxieLab\Warden\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\LoginProtection;

use FanxieLab\Warden\Modules\LoginProtection\Runtime\SessionTimeout;
use WP_UnitTestCase;

/**
 * Round-trip through the real `auth_cookie_expiration` filter with real users:
 * an administrator's session is capped to its per-role window, an unlisted role
 * falls back to `default`, and a length shorter than the cap is never extended.
 * `WP_UnitTestCase` restores the hook globals after each test, so the wiring is
 * torn down automatically.
 */
final class SessionTimeoutIntegrationTest extends WP_UnitTestCase {

	/**
	 * Register an enabled per-role timeout against the live hook system.
	 */
	private function enable(): void {
		( new SessionTimeout(
			[
				'enabled'  => true,
				'timeouts' => [
					'administrator' => 30,
					'default'       => 120,
				],
			]
		) )->register_hooks();
	}

	public function test_auth_cookie_expiration_is_capped_for_an_administrator(): void {
		$this->enable();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// WP's remember-me default (14 days) is shortened to the 30-minute cap.
		$result = apply_filters( 'auth_cookie_expiration', 1209600, $admin_id, true );

		$this->assertSame( 30 * 60, $result );
	}

	public function test_auth_cookie_expiration_falls_back_to_default_for_a_subscriber(): void {
		$this->enable();
		$sub_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// `subscriber` has no explicit timeout, so the 120-minute default applies.
		$result = apply_filters( 'auth_cookie_expiration', 172800, $sub_id, false );

		$this->assertSame( 120 * 60, $result );
	}

	public function test_auth_cookie_expiration_never_extends_a_shorter_length(): void {
		$this->enable();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// WordPress already grants only 10 minutes; the cap must not lengthen it.
		$result = apply_filters( 'auth_cookie_expiration', 600, $admin_id, false );

		$this->assertSame( 600, $result );
	}
}
