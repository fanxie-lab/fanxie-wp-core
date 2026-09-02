<?php
/**
 * Integration tests for the Login Protection hide-login slug guard.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\Runtime\LoginSlugGuard;
use WP_UnitTestCase;

/**
 * Drives the guard against the real WordPress URL-generation filters
 * (`site_url` / `network_site_url` / `login_url` / `wp_redirect`) and the real
 * `$_SERVER` / `is_admin()` request state.
 *
 * Testing approach: the terminal side effects (require `wp-login.php`, load the
 * 404 template, redirect) all `exit`, which the in-process test harness cannot
 * capture. We therefore assert on (a) the real filter output that rewrites
 * generated login URLs to the slug, and (b) the guard's decision surface
 * (`resolve_action()`, `should_redirect_admin()`, `is_safe_context()`) under
 * crafted request state — the exact branch each terminal method dispatches on.
 */
final class LoginSlugGuardIntegrationTest extends WP_UnitTestCase {

	private const SLUG = 'secret-portal';

	private ?string $original_request_uri = null;

	private ?LoginSlugGuard $guard = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null;
	}

	protected function tearDown(): void {
		// Explicitly remove the guard's hooks so a registered `wp_loaded` listener
		// can never fire (and `exit`) during an unrelated later test, whatever the
		// suite's execution order.
		if ( null !== $this->guard ) {
			remove_action( 'wp_loaded', [ $this->guard, 'dispatch' ], 1 );
			remove_filter( 'site_url', [ $this->guard, 'filter_site_url' ], 10 );
			remove_filter( 'network_site_url', [ $this->guard, 'filter_network_site_url' ], 10 );
			remove_filter( 'login_url', [ $this->guard, 'filter_login_url' ], 20 );
			remove_filter( 'wp_redirect', [ $this->guard, 'filter_wp_redirect' ], 10 );
			$this->guard = null;
		}

		unset( $_REQUEST['action'], $_GET['action'] );
		// Restore (never leave unset — WP's shutdown cron spawner reads it).
		$_SERVER['REQUEST_URI'] = $this->original_request_uri ?? '/';
		// Reset admin context in case a test flipped it.
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides Overrides for the `hide_login` sub-config.
	 */
	private function active_guard( array $overrides = [] ): LoginSlugGuard {
		$this->guard = new LoginSlugGuard( array_merge( [ 'enabled' => true, 'slug' => self::SLUG ], $overrides ) );
		$this->guard->register_hooks();

		return $this->guard;
	}

	public function test_wp_login_url_points_at_the_slug(): void {
		$this->active_guard();

		$login_url = wp_login_url();
		$this->assertStringContainsString( self::SLUG, $login_url );
		$this->assertStringNotContainsString( 'wp-login.php', $login_url );
	}

	public function test_logout_url_keeps_the_action_and_uses_the_slug(): void {
		$this->active_guard();

		$logout_url = wp_logout_url();
		$this->assertStringContainsString( self::SLUG, $logout_url );
		$this->assertStringContainsString( 'action=logout', $logout_url );
		$this->assertStringNotContainsString( 'wp-login.php', $logout_url );
	}

	public function test_password_reset_link_is_rewritten_and_preserves_its_query(): void {
		$this->active_guard();

		$reset = network_site_url( 'wp-login.php?action=rp&key=abc123&login=admin', 'login' );
		$this->assertStringContainsString( self::SLUG, $reset );
		$this->assertStringContainsString( 'action=rp', $reset );
		$this->assertStringContainsString( 'key=abc123', $reset );
		$this->assertStringContainsString( 'login=admin', $reset );
		$this->assertStringNotContainsString( 'wp-login.php', $reset );
	}

	public function test_runtime_redirect_to_wp_login_is_rewritten_to_slug(): void {
		$this->active_guard();

		// Core's post-logout / check-your-email redirects are hard-coded relative
		// `wp-login.php` strings that never pass through site_url(); the wp_redirect
		// filter is what keeps them landing on the slug.
		$location = apply_filters( 'wp_redirect', home_url( '/wp-login.php?loggedout=true' ), 302 );
		$this->assertStringContainsString( self::SLUG, $location );
		$this->assertStringContainsString( 'loggedout=true', $location );
		$this->assertStringNotContainsString( 'wp-login.php', $location );
	}

	public function test_non_login_urls_are_left_untouched(): void {
		$this->active_guard();

		$admin = admin_url( 'options-general.php' );
		$this->assertStringContainsString( 'wp-admin', $admin );
		$this->assertStringNotContainsString( self::SLUG, $admin );
	}

	public function test_slug_request_resolves_to_serve(): void {
		$guard = $this->active_guard();

		$_SERVER['REQUEST_URI'] = '/' . self::SLUG;
		$this->assertFalse( $guard->is_safe_context(), 'A normal request is not a carve-out context.' );
		$this->assertSame( 'serve', $guard->resolve_action() );
	}

	public function test_raw_wp_login_request_resolves_to_deny(): void {
		$guard = $this->active_guard();

		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		unset( $_REQUEST['action'], $_GET['action'] );
		$this->assertSame( 'deny', $guard->resolve_action() );
	}

	public function test_preserved_action_flows_are_not_denied(): void {
		$guard = $this->active_guard();

		foreach ( [ 'logout', 'lostpassword', 'rp', 'resetpass', 'register', 'postpass' ] as $action ) {
			$_SERVER['REQUEST_URI'] = '/wp-login.php?action=' . $action;
			$_REQUEST['action']     = $action;
			$this->assertSame(
				'none',
				$guard->resolve_action(),
				"Raw wp-login.php with action={$action} must resolve (not 404)."
			);
		}
	}

	public function test_constant_overrides_stored_slug_end_to_end(): void {
		if ( ! defined( 'FX_CORE_LOGIN_SLUG' ) ) {
			define( 'FX_CORE_LOGIN_SLUG', 'constant-gate' );
		}
		$guard = $this->active_guard( [ 'slug' => 'stored-login' ] );

		$this->assertSame( 'constant-gate', $guard->effective_slug() );
		$this->assertStringContainsString( 'constant-gate', wp_login_url() );
	}

	public function test_unauthenticated_admin_request_is_redirected(): void {
		$guard = $this->active_guard();

		set_current_screen( 'dashboard' );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_URI'] = '/wp-admin/';

		$this->assertTrue( is_admin(), 'Precondition: request is in the admin context.' );
		$this->assertTrue( $guard->should_redirect_admin(), 'A logged-out admin page load must redirect.' );
	}

	public function test_authenticated_admin_request_passes_through(): void {
		$guard = $this->active_guard();

		set_current_screen( 'dashboard' );
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$_SERVER['REQUEST_URI'] = '/wp-admin/';

		$this->assertFalse( $guard->should_redirect_admin(), 'A logged-in user is never redirected.' );
	}

	public function test_admin_post_endpoint_is_never_redirected(): void {
		$guard = $this->active_guard();

		set_current_screen( 'dashboard' );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';

		$this->assertFalse(
			$guard->should_redirect_admin(),
			'admin-post.php serves nopriv form handlers and must stay reachable.'
		);
	}

	public function test_ajax_context_is_never_intercepted(): void {
		$guard = $this->active_guard();

		add_filter( 'wp_doing_ajax', '__return_true' );
		$_SERVER['REQUEST_URI'] = '/' . self::SLUG;

		$this->assertTrue( $guard->is_safe_context() );
		$this->assertSame( 'none', $guard->resolve_action(), 'admin-ajax must never be intercepted.' );

		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	public function test_cron_context_is_never_intercepted(): void {
		$guard = $this->active_guard();

		add_filter( 'wp_doing_cron', '__return_true' );
		$_SERVER['REQUEST_URI'] = '/wp-login.php';

		$this->assertTrue( $guard->is_safe_context() );
		$this->assertSame( 'none', $guard->resolve_action(), 'WP-Cron must never be intercepted.' );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	public function test_rest_api_path_is_a_carve_out_with_a_colliding_slug(): void {
		// `users` collides with a core REST route's trailing segment: a request to
		// `/wp-json/wp/v2/users` has trailing segment `users`, which matches the
		// slug and — absent the path-based REST carve-out — would be served the
		// login form. The carve-out must decide this at wp_loaded, using the real
		// `rest_get_url_prefix()` and `home_url()`, before core defines
		// `REST_REQUEST` on parse_request.
		$guard = $this->active_guard( [ 'slug' => 'users' ] );

		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wp/v2/users';
		$this->assertTrue(
			$guard->is_safe_context(),
			'A /wp-json/… request is a carve-out context even with a colliding slug.'
		);
		$this->assertSame( 'none', $guard->resolve_action(), 'The REST API must never be intercepted.' );
	}
}
