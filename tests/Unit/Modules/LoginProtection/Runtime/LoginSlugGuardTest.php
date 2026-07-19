<?php
/**
 * Unit tests for the Login Protection hide-login slug guard.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\LoginSlugGuard;
use PHPUnit\Framework\TestCase;

/**
 * Pure slug-resolution, activation, request-classification and safety carve-out
 * logic. The terminal side effects (require wp-login.php / 404 template /
 * redirect + exit) are exercised through the real WordPress hooks in the
 * integration suite; here we assert the decision surface only.
 *
 * Constant-dependent cases (`FX_CORE_LOGIN_SLUG`, `DOING_AJAX`, `DOING_CRON`,
 * `REST_REQUEST`) run in isolated processes so a constant defined by one test
 * can never leak into another test — or another test file — in the shared unit
 * process.
 */
final class LoginSlugGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );

		// Narrow, deterministic stand-ins for the WordPress helpers the guard's
		// decision surface calls. No WP runtime exists in unit mode.
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ): string {
				$title = strtolower( (string) $title );
				$title = (string) preg_replace( '/[^a-z0-9]+/', '-', $title );
				return trim( $title, '-' );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url, $component = -1 ) => parse_url( (string) $url, (int) $component )
		);
		// Pretty permalinks on by default so slug matching uses the path segment,
		// not the plain-permalink query fallback.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) {
				return 'permalink_structure' === $key ? '/%postname%/' : $fallback;
			}
		);
		// The safety carve-out consults these core helpers; default them off so
		// ordinary requests are classified, and flip them per-test for carve-outs.
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_URI'],
			$_REQUEST['action'],
			$_GET['action'],
			$_GET['my-login']
		);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $hide_login `hide_login` sub-config.
	 */
	private function guard( array $hide_login ): LoginSlugGuard {
		return new LoginSlugGuard( $hide_login );
	}

	public function test_effective_slug_returns_sanitized_stored_slug(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'Secret Door' ] );
		$this->assertSame( 'secret-door', $guard->effective_slug() );
	}

	public function test_effective_slug_is_empty_when_no_slug_stored(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => '' ] );
		$this->assertSame( '', $guard->effective_slug() );
	}

	public function test_effective_slug_rejects_reserved_stored_slug(): void {
		// Defence-in-depth: even if a reserved value reached the config, the
		// guard must never route login at a reserved path.
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'wp-admin' ] );
		$this->assertSame( '', $guard->effective_slug() );
	}

	public function test_is_active_true_when_enabled_and_valid_slug(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );
		$this->assertTrue( $guard->is_active() );
	}

	public function test_is_active_false_when_disabled_even_with_slug(): void {
		$guard = $this->guard( [ 'enabled' => false, 'slug' => 'my-login' ] );
		$this->assertFalse( $guard->is_active() );
	}

	public function test_is_active_false_when_enabled_but_no_effective_slug(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => '' ] );
		$this->assertFalse( $guard->is_active() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_stored_slug(): void {
		define( 'FX_CORE_LOGIN_SLUG', 'Constant Gate' );
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'stored-login' ] );
		$this->assertSame( 'constant-gate', $guard->effective_slug() );
		$this->assertTrue( $guard->is_active() );
	}

	/**
	 * A reserved / empty constant does not win: resolution falls back to the
	 * stored slug (which is itself already sanitised at write time).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reserved_constant_falls_back_to_stored_slug(): void {
		define( 'FX_CORE_LOGIN_SLUG', 'wp-admin' );
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'stored-login' ] );
		$this->assertSame( 'stored-login', $guard->effective_slug() );
	}

	public function test_is_slug_request_matches_trailing_path_segment(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/my-login';
		$this->assertTrue( $guard->is_slug_request() );

		$_SERVER['REQUEST_URI'] = '/my-login/';
		$this->assertTrue( $guard->is_slug_request(), 'Trailing slash still matches.' );

		$_SERVER['REQUEST_URI'] = '/some-other-page';
		$this->assertFalse( $guard->is_slug_request() );

		$_SERVER['REQUEST_URI'] = '/';
		$this->assertFalse( $guard->is_slug_request() );
	}

	public function test_is_wp_login_request_detects_raw_entry_point(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		$this->assertTrue( $guard->is_wp_login_request() );

		$_SERVER['REQUEST_URI'] = '/wp-login.php?action=logout';
		$this->assertTrue( $guard->is_wp_login_request(), 'Query string is ignored.' );

		$_SERVER['REQUEST_URI'] = '/my-login';
		$this->assertFalse( $guard->is_wp_login_request() );
	}

	public function test_is_allowed_action_recognises_preserved_flows(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		foreach ( [ 'logout', 'lostpassword', 'rp', 'resetpass', 'register', 'postpass' ] as $action ) {
			$_REQUEST['action'] = $action;
			$this->assertTrue( $guard->is_allowed_action(), "Action {$action} must be preserved." );
		}

		$_REQUEST['action'] = 'login';
		$this->assertFalse( $guard->is_allowed_action(), 'The login form itself is never an allowed pass-through.' );

		unset( $_REQUEST['action'] );
		$this->assertFalse( $guard->is_allowed_action(), 'No action is not an allowed pass-through.' );
	}

	public function test_resolve_action_serves_slug_and_denies_raw_login(): void {
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/my-login';
		$this->assertSame( 'serve', $guard->resolve_action() );

		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		unset( $_REQUEST['action'] );
		$this->assertSame( 'deny', $guard->resolve_action() );

		$_SERVER['REQUEST_URI'] = '/wp-login.php?action=logout';
		$_REQUEST['action']     = 'logout';
		$this->assertSame( 'none', $guard->resolve_action(), 'Preserved action flows are never denied.' );

		$_SERVER['REQUEST_URI'] = '/an-ordinary-page';
		unset( $_REQUEST['action'] );
		$this->assertSame( 'none', $guard->resolve_action() );
	}

	public function test_doing_ajax_short_circuits_interception(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/my-login';
		$this->assertTrue( $guard->is_safe_context() );
		$this->assertSame( 'none', $guard->resolve_action(), 'admin-ajax must never be intercepted.' );
	}

	public function test_doing_cron_short_circuits_interception(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		$this->assertTrue( $guard->is_safe_context() );
		$this->assertSame( 'none', $guard->resolve_action(), 'WP-Cron must never be intercepted.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_request_short_circuits_interception(): void {
		define( 'REST_REQUEST', true );
		$guard = $this->guard( [ 'enabled' => true, 'slug' => 'my-login' ] );

		$_SERVER['REQUEST_URI'] = '/my-login';
		$this->assertTrue( $guard->is_safe_context() );
		$this->assertSame( 'none', $guard->resolve_action(), 'The REST API must never be intercepted.' );
	}
}
