<?php
/**
 * Unit tests for the Login Protection per-role session timeout.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\SessionTimeout;
use PHPUnit\Framework\TestCase;

/**
 * Pure `cookie_lifetime()` role-resolution logic (shorten-not-extend) and the
 * hook-wiring gate (`register_hooks()` attaches nothing unless sessions are on).
 * The live `auth_cookie_expiration` round-trip runs in the integration suite.
 */
final class SessionTimeoutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A fully-enabled sessions config, with selective overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function config( array $overrides = [] ): array {
		return array_merge(
			[
				'enabled'  => true,
				'timeouts' => [
					'administrator' => 30,
					'default'       => 120,
				],
			],
			$overrides
		);
	}

	/**
	 * Stub `get_userdata()` to hand back a user whose primary role is $role.
	 *
	 * @param string $role The role slug to expose as `roles[0]`.
	 */
	private function stub_user_with_role( string $role ): void {
		$user        = new \stdClass();
		$user->ID    = 7;
		$user->roles = [ $role ];
		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	public function test_cookie_lifetime_shortens_to_administrator_timeout(): void {
		$this->stub_user_with_role( 'administrator' );
		$session = new SessionTimeout( $this->config() );

		// WP's remember-me default is 14 days; the 30-minute admin cap wins.
		$this->assertSame( 30 * 60, $session->cookie_lifetime( 1209600, 7, true ) );
	}

	public function test_cookie_lifetime_uses_default_for_unlisted_role(): void {
		$this->stub_user_with_role( 'editor' );
		$session = new SessionTimeout( $this->config() );

		// `editor` has no explicit timeout, so the 120-minute default applies.
		$this->assertSame( 120 * 60, $session->cookie_lifetime( 172800, 7, false ) );
	}

	public function test_cookie_lifetime_never_extends_a_shorter_session(): void {
		$this->stub_user_with_role( 'administrator' );
		$session = new SessionTimeout( $this->config() );

		// WP already grants only 10 minutes; a security cap must not lengthen it.
		$this->assertSame( 600, $session->cookie_lifetime( 600, 7, false ) );
	}

	public function test_cookie_lifetime_passes_through_when_user_cannot_be_resolved(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$session = new SessionTimeout( $this->config() );

		$this->assertSame( 172800, $session->cookie_lifetime( 172800, 999, false ) );
	}

	public function test_cookie_lifetime_passes_through_for_a_roleless_user(): void {
		$user        = new \stdClass();
		$user->ID    = 7;
		$user->roles = [];
		Functions\when( 'get_userdata' )->justReturn( $user );
		$session = new SessionTimeout( $this->config() );

		$this->assertSame( 172800, $session->cookie_lifetime( 172800, 7, false ) );
	}

	public function test_cookie_lifetime_passes_through_for_a_non_positive_user_id(): void {
		// A zero/anonymous user id resolves to nothing; the length is untouched.
		Functions\expect( 'get_userdata' )->never();
		$session = new SessionTimeout( $this->config() );

		$this->assertSame( 172800, $session->cookie_lifetime( 172800, 0, false ) );
	}

	public function test_enqueue_idle_script_injects_numeric_timeout_and_logout_url(): void {
		$current        = new \stdClass();
		$current->ID    = 42;
		$current->roles = [ 'administrator' ];
		Functions\when( 'wp_get_current_user' )->justReturn( $current );
		Functions\when( 'wp_logout_url' )->justReturn( 'https://example.test/logout?nonce=abc' );

		// Mirror WordPress's real wp_json_encode(): plain json_encode preserves
		// scalar types (an int stays a JSON number), which is the whole point of
		// switching away from wp_localize_script()'s string-casting behaviour.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		$enqueued = [];
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ): void {
				$enqueued[] = (string) $handle;
			}
		);

		$inline = [];
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $data, $position = 'after' ) use ( &$inline ): bool {
				$inline = [
					'handle'   => (string) $handle,
					'data'     => (string) $data,
					'position' => (string) $position,
				];
				return true;
			}
		);

		( new SessionTimeout( $this->config() ) )->enqueue_idle_script();

		$this->assertSame( [ 'fanxie-wp-core-idle-logout' ], $enqueued );
		$this->assertSame( 'fanxie-wp-core-idle-logout', $inline['handle'] );
		$this->assertSame( 'before', $inline['position'] );

		// The config is assigned to the same global the script reads.
		$this->assertStringContainsString( 'window.fanxieWpCoreIdle =', $inline['data'] );

		// Regression guard: the timeout MUST be delivered as a bare JSON number.
		// The old wp_localize_script() path stringified it to "1800000", which
		// tripped the script's guard and silently disabled the idle-logout.
		$this->assertStringContainsString( '"timeoutMs":1800000', $inline['data'] );
		$this->assertStringNotContainsString( '"timeoutMs":"', $inline['data'] );

		// Decode the injected literal and prove the type + values survived.
		$json    = trim( str_replace( 'window.fanxieWpCoreIdle =', '', $inline['data'] ) );
		$decoded = json_decode( rtrim( $json, ';' ), true );
		$this->assertIsInt( $decoded['timeoutMs'] );
		$this->assertSame( 30 * 60 * 1000, $decoded['timeoutMs'] );
		$this->assertSame( 'https://example.test/logout?nonce=abc', $decoded['logoutUrl'] );
	}

	public function test_enqueue_idle_script_skips_a_logged_out_visitor(): void {
		$anon     = new \stdClass();
		$anon->ID = 0;
		Functions\when( 'wp_get_current_user' )->justReturn( $anon );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		( new SessionTimeout( $this->config() ) )->enqueue_idle_script();

		// A no-op is the whole assertion; keep PHPUnit from flagging it as risky.
		$this->assertTrue( true );
	}

	public function test_enqueue_idle_script_skips_a_roleless_user(): void {
		$current        = new \stdClass();
		$current->ID    = 42;
		$current->roles = [];
		Functions\when( 'wp_get_current_user' )->justReturn( $current );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		( new SessionTimeout( $this->config() ) )->enqueue_idle_script();

		$this->assertTrue( true );
	}

	public function test_register_hooks_attaches_nothing_when_sessions_are_off(): void {
		$added = $this->capture_hook_registrations();

		$session = new SessionTimeout( $this->config( [ 'enabled' => false ] ) );
		$session->register_hooks();

		$this->assertSame( [], $added->getArrayCopy(), 'No hooks may be attached while sessions are off.' );
	}

	public function test_register_hooks_wires_filter_and_enqueue_when_enabled(): void {
		$added = $this->capture_hook_registrations();

		$session = new SessionTimeout( $this->config( [ 'enabled' => true ] ) );
		$session->register_hooks();

		$this->assertSame(
			[
				[ 'filter', 'auth_cookie_expiration', 'cookie_lifetime', 10, 3 ],
				[ 'action', 'admin_enqueue_scripts', 'enqueue_idle_script', 10, 1 ],
			],
			$added->getArrayCopy(),
			'Enabling sessions wires the cookie-lifetime filter and the idle-script enqueue.'
		);
	}

	/**
	 * Capture every `add_action` / `add_filter` call as a flat, assertable tuple:
	 * `[ kind, hook, handler-method, priority, accepted_args ]`.
	 *
	 * Returns an `ArrayObject` (a handle, not a value copy) so registrations the
	 * stubbed hook functions append are visible to the caller after
	 * `register_hooks()` runs.
	 *
	 * @return \ArrayObject<int, array{0: string, 1: string, 2: string, 3: int, 4: int}> Captured registrations, in call order.
	 */
	private function capture_hook_registrations(): \ArrayObject {
		$added = new \ArrayObject();

		$recorder = static function ( string $kind ) use ( $added ): callable {
			return static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( $kind, $added ): bool {
				$method  = is_array( $callback ) ? (string) $callback[1] : (string) $callback;
				$added[] = [ $kind, (string) $hook, $method, (int) $priority, (int) $accepted_args ];
				return true;
			};
		};

		Functions\when( 'add_action' )->alias( $recorder( 'action' ) );
		Functions\when( 'add_filter' )->alias( $recorder( 'filter' ) );

		return $added;
	}
}
