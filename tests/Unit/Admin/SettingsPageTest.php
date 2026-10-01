<?php
/**
 * Unit tests for SettingsPage menu registration.
 *
 * @package FanxieLab\Warden\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\SettingsPage;
use FanxieLab\Warden\Modules\ModuleRegistry;
use FanxieLab\Warden\Plugin;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin registers a TOP-LEVEL "FX Core" menu (not a Settings
 * submenu) with a relabeled first submenu row, keeping the slug stable.
 */
final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Regression: the production build (`dist/admin.js`) is emitted by Vite as
	 * a native ES module (`export{...}` + dynamic `import()` for lazy routes).
	 * The prod enqueue branch therefore MUST register a `script_loader_tag`
	 * filter that rewrites the `ASSET_HANDLE` tag to `type="module"`, or the
	 * browser hits the top-level `export` and throws
	 * `SyntaxError: Unexpected token 'export'`.
	 *
	 * Unlike dev, prod is served same-origin from the site's own wp-content,
	 * so the tag must NOT carry `crossorigin`.
	 *
	 * This drives the prod branch of enqueue_assets() and captures the filter
	 * callback it registers, then asserts the callback turns a classic tag for
	 * the admin handle into a module script.
	 */
	public function test_prod_enqueue_rewrites_admin_tag_to_module_script(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		// Capture the callback registered for `script_loader_tag`.
		$captured = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$captured ) {
				if ( 'script_loader_tag' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		$page = new SettingsPage( new ModuleRegistry() );

		// The prod branch of enqueue_assets() only runs for the plugin's own
		// screen. Populate the private hook suffix directly (register_menu()
		// is exercised separately) and reset the static idempotency guard so
		// the filter registers fresh regardless of test ordering.
		$hook = 'toplevel_page_' . SettingsPage::MENU_SLUG;
		$this->set_private_property( $page, 'hook_suffix', $hook );
		$this->reset_tag_filter_guard();

		// prod mode is inferred from the filesystem: no `assets/admin/.vite-hot`
		// marker exists in the repo, and `assets/admin/dist/admin.js` (the
		// committed build) does — so hot_server_url() is null and build_exists()
		// is true, sending execution down the prod branch.
		$page->enqueue_assets( $hook );

		$this->assertIsCallable(
			$captured,
			'Prod enqueue must register a script_loader_tag filter so the ES-module bundle loads as type="module".'
		);

		$src = 'http://example.test/wp-content/plugins/fanxie-warden/assets/admin/dist/admin.js';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- test fixture: a sample classic tag fed into the captured filter, not a real enqueue.
		$classic_tag = sprintf( "<script src='%s' id='%s-js'></script>\n", $src, SettingsPage::ASSET_HANDLE );

		$rewritten = $captured( $classic_tag, SettingsPage::ASSET_HANDLE, $src );

		$this->assertStringContainsString(
			'type="module"',
			$rewritten,
			'The admin bundle tag must be a module script in production.'
		);
		$this->assertStringNotContainsString(
			'crossorigin',
			$rewritten,
			'The prod bundle is same-origin, so crossorigin must not be emitted.'
		);
	}

	/**
	 * An unrelated handle must be left untouched by the prod filter.
	 */
	public function test_prod_tag_filter_ignores_other_handles(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$captured = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$captured ) {
				if ( 'script_loader_tag' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		$page = new SettingsPage( new ModuleRegistry() );
		$hook = 'toplevel_page_' . SettingsPage::MENU_SLUG;
		$this->set_private_property( $page, 'hook_suffix', $hook );
		$this->reset_tag_filter_guard();

		$page->enqueue_assets( $hook );

		$this->assertIsCallable( $captured );

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- test fixture: a foreign handle's tag fed into the captured filter, not a real enqueue.
		$other_tag = "<script src='https://example.test/jquery.js' id='jquery-core-js'></script>\n";

		$this->assertSame(
			$other_tag,
			$captured( $other_tag, 'jquery-core', 'https://example.test/jquery.js' ),
			'The filter must return foreign handles unchanged.'
		);
	}

	/**
	 * Dev mode must keep emitting `crossorigin` (the Vite dev server serves the
	 * TS entry + HMR client cross-origin), for both dev handles. This locks the
	 * intentional dev/prod difference in the shared tag-filter helper.
	 */
	public function test_dev_config_emits_module_with_crossorigin_for_both_handles(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$captured = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$captured ) {
				if ( 'script_loader_tag' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		$page = new SettingsPage( new ModuleRegistry() );
		$this->reset_tag_filter_guard();

		// Invoke the shared helper with the dev configuration directly (the dev
		// branch requires a live Vite hot-file on disk, which a unit test should
		// not fabricate). `true` => crossorigin.
		$method = new \ReflectionMethod( SettingsPage::class, 'register_module_tag_filter' );
		$method->setAccessible( true );
		$method->invoke( $page, [ SettingsPage::VITE_CLIENT_HANDLE, SettingsPage::ASSET_HANDLE ], true );

		$this->assertIsCallable( $captured );

		foreach ( [ SettingsPage::VITE_CLIENT_HANDLE, SettingsPage::ASSET_HANDLE ] as $handle ) {
			$src = 'http://localhost:5173/src/main.ts';
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- test fixture: a sample classic tag fed into the captured filter, not a real enqueue.
			$classic_tag = sprintf( "<script src='%s' id='%s-js'></script>\n", $src, $handle );

			$rewritten = $captured( $classic_tag, $handle, $src );

			$this->assertStringContainsString( 'type="module"', $rewritten, "Dev tag for {$handle} must be a module." );
			$this->assertStringContainsString( 'crossorigin', $rewritten, "Dev tag for {$handle} must keep crossorigin." );
		}
	}

	/**
	 * Set a private/protected instance property via reflection.
	 *
	 * @param object $target   Object whose property should be set.
	 * @param string $property Property name.
	 * @param mixed  $value    New value.
	 */
	private function set_private_property( object $target, string $property, mixed $value ): void {
		$reflection = new \ReflectionProperty( $target, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( $target, $value );
	}

	/**
	 * Reset the static `script_loader_tag` idempotency guard so each test can
	 * observe a fresh filter registration.
	 */
	private function reset_tag_filter_guard(): void {
		$guard = new \ReflectionProperty( SettingsPage::class, 'vite_tag_filter_registered' );
		$guard->setAccessible( true );
		$guard->setValue( null, false );
	}

	public function test_register_menu_adds_top_level_fx_core_menu(): void {
		// ModuleRegistry is `final`, so Mockery cannot mock it; register_menu()
		// never touches the registry, so a real (empty) instance is sufficient.
		$page = new SettingsPage( new ModuleRegistry() );

		Functions\expect( 'add_menu_page' )
			->once()
			->with(
				'Fanxie Warden',
				'FX Core',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' ),
				Mockery::type( 'string' ), // data: URI icon.
				Mockery::any()
			)
			->andReturn( 'toplevel_page_fanxie-warden' );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				SettingsPage::MENU_SLUG,
				'Fanxie Warden',
				'Settings',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' )
			)
			->andReturn( 'fanxie-warden_page' );

		$page->register_menu();

		// The Brain Monkey Functions\expect() calls above are the assertions;
		// register their satisfaction so PHPUnit does not flag this as risky
		// (phpunit.xml.dist runs with failOnRisky="true").
		$this->addToAssertionCount( 1 );
	}
}
