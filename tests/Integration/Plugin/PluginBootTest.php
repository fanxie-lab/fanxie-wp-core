<?php
/**
 * Integration tests for the Plugin bootstrap.
 *
 * Runs inside the @wordpress/env `tests` container with a real WordPress core.
 * Extends `WP_UnitTestCase` so each test gets a clean, transactional DB.
 *
 * @package FanxieLab\Warden\Tests\Integration\Plugin
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Plugin;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Admin\SettingsPage;
use FanxieLab\Warden\Modules\ModuleRegistry;
use FanxieLab\Warden\Plugin;
use WP_UnitTestCase;
use WPAjaxDieContinueException;

/**
 * End-to-end boot test for the Plugin container and its collaborators.
 */
final class PluginBootTest extends WP_UnitTestCase {

	/**
	 * Admin user id created in setUp(), used by the AJAX ping test.
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	public function set_up(): void {
		parent::set_up();

		// Activation must run once so the custom cap is granted; the boot
		// chain is already fired by the plugin's own `plugins_loaded` hook
		// inside the bootstrap file.
		Plugin::activate();

		$this->admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function tear_down(): void {
		// Drop the admin cap again so state does not bleed between test classes.
		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role ) {
			$administrator->remove_cap( Plugin::CAPABILITY );
		}

		parent::tear_down();
	}

	public function test_plugin_boots_and_registers_core_services(): void {
		$instance = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $instance, 'Plugin::boot() should have run on plugins_loaded.' );
		$this->assertInstanceOf( ModuleRegistry::class, $instance->get( ModuleRegistry::class ) );
		$this->assertInstanceOf( SettingsPage::class, $instance->get( SettingsPage::class ) );
		$this->assertInstanceOf( AjaxRouter::class, $instance->get( AjaxRouter::class ) );
	}

	public function test_settings_page_menu_is_registered_on_admin_menu(): void {
		// Log in as admin and fire admin_menu — WP only registers submenus when
		// the current user can see them.
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu' );

		// The plugin registers a top-level menu (add_menu_page) whose slug is
		// SettingsPage::MENU_SLUG, then relabels its first submenu row "Settings".
		global $menu, $submenu;

		$this->assertIsArray( $menu );
		$menu_slugs = array_map( static fn ( array $entry ): string => (string) ( $entry[2] ?? '' ), $menu );
		$this->assertContains( SettingsPage::MENU_SLUG, $menu_slugs, 'Top-level FX Core menu should be registered.' );

		$this->assertIsArray( $submenu );
		$this->assertArrayHasKey( SettingsPage::MENU_SLUG, $submenu );

		$submenu_slugs = array_map( static fn ( array $entry ): string => (string) ( $entry[2] ?? '' ), $submenu[ SettingsPage::MENU_SLUG ] );
		$this->assertContains( SettingsPage::MENU_SLUG, $submenu_slugs, 'Relabeled Settings row under the top-level FX Core menu should be registered.' );
	}

	public function test_activation_grants_capability_to_administrator_role(): void {
		$administrator = get_role( 'administrator' );

		$this->assertInstanceOf( \WP_Role::class, $administrator );
		$this->assertTrue( $administrator->has_cap( Plugin::CAPABILITY ) );

		// A user with the administrator role should inherit the cap.
		$user = get_userdata( $this->admin_user_id );
		$this->assertNotFalse( $user );
		$this->assertTrue( user_can( $user, Plugin::CAPABILITY ) );
	}

	public function test_ajax_ping_sub_action_returns_pong_for_authorised_admin(): void {
		wp_set_current_user( $this->admin_user_id );

		$_POST                = [];
		$_POST['action']      = AjaxRouter::AJAX_ACTION;
		$_POST['_action']     = 'ping';
		$_POST['_ajax_nonce'] = wp_create_nonce( AjaxRouter::NONCE_ACTION );

		$response = $this->dispatch_ajax( AjaxRouter::AJAX_ACTION );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] ?? false, 'AJAX response should be successful.' );
		$this->assertIsArray( $response['data'] ?? null );
		$this->assertTrue( $response['data']['pong'] ?? false, 'Ping handler should echo pong: true.' );
		$this->assertIsInt( $response['data']['time'] ?? null );
	}

	/**
	 * Dispatch a `wp_ajax_*` action and capture its JSON response.
	 *
	 * Mirrors the pattern WordPress core uses in `WP_Ajax_UnitTestCase`
	 * without forcing us to extend it (which would break the `WP_UnitTestCase`
	 * transaction model for the rest of the class).
	 *
	 * @param string $action AJAX action name (e.g. `fanxie_warden`).
	 *
	 * @return array<string, mixed> Decoded JSON response body.
	 */
	private function dispatch_ajax( string $action ): array {
		// Deliberately *not* `define( 'DOING_AJAX', true )`: a constant cannot be
		// unset, so it would put every later test in the run into an AJAX
		// context (Login Protection's slug guard, for one, treats that as a
		// carve-out). The filter gives the same signal for one dispatch only.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );

		ob_start();

		try {
			do_action( 'wp_ajax_' . $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected termination.
			// Expected — the handler called wp_die() to flush the JSON body.
		} finally {
			remove_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$raw = (string) ob_get_clean();
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Sink for `wp_die()` so a dispatched handler terminates cleanly mid-test.
	 *
	 * @return callable
	 */
	public function ajax_die_handler(): callable {
		return static function (): void {
			throw new WPAjaxDieContinueException( 'ajax-dispatched' );
		};
	}
}
