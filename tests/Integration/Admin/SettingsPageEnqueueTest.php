<?php
/**
 * Integration tests for SettingsPage asset enqueuing (production mode).
 *
 * Runs inside the @wordpress/env `tests` container with a real WordPress core,
 * exercising the real `wp_enqueue_script()` + `script_loader_tag` pipeline so
 * we assert on the actual `<script>` tag WordPress emits.
 *
 * @package FanxieLab\Warden\Tests\Integration\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Admin;

use FanxieLab\Warden\Admin\SettingsPage;
use FanxieLab\Warden\Plugin;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Guards the production regression where the Vite ESM bundle (`dist/admin.js`,
 * which ends with `export{...}` and uses dynamic `import()` for lazy routes)
 * was enqueued as a classic <script>, so the browser threw
 * `SyntaxError: Unexpected token 'export'` and the admin SPA never loaded.
 */
final class SettingsPageEnqueueTest extends WP_UnitTestCase {

	/**
	 * Admin user id created in set_up().
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	public function set_up(): void {
		parent::set_up();

		// Grant the custom capability so enqueue_assets()'s cap check passes.
		Plugin::activate();

		$this->admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// The `script_loader_tag` filter registration is guarded by a static
		// flag that persists across tests in the same PHP process. Reset it so
		// the prod branch always registers its filter fresh, independent of
		// test ordering.
		$this->reset_tag_filter_guard();
	}

	public function tear_down(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role ) {
			$administrator->remove_cap( Plugin::CAPABILITY );
		}

		parent::tear_down();
	}

	/**
	 * In production mode (no `.vite-hot` marker; committed `dist/admin.js`),
	 * the emitted admin bundle tag must be an ES module.
	 */
	public function test_prod_admin_bundle_is_emitted_as_module_script(): void {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'dashboard' );

		$settings_page = Plugin::instance()->get( SettingsPage::class );
		$this->assertInstanceOf( SettingsPage::class, $settings_page );

		// Fire admin_menu so the page learns its own hook suffix (register_menu()
		// runs on the same container-held instance and stores it).
		do_action( 'admin_menu' );

		$hook_suffix = 'toplevel_page_' . SettingsPage::MENU_SLUG;
		$settings_page->enqueue_assets( $hook_suffix );

		// Capture the real footer <script> output (the bundle enqueues with
		// `in_footer => true`).
		$footer = get_echo( 'wp_print_footer_scripts' );

		$tag = $this->extract_script_tag( $footer, SettingsPage::ASSET_HANDLE . '-js' );

		$this->assertNotNull(
			$tag,
			'The admin bundle <script> tag should be present in the footer output.'
		);
		$this->assertStringContainsString(
			'type="module"',
			(string) $tag,
			'The production admin bundle must load as an ES module (type="module").'
		);
		$this->assertStringNotContainsString(
			'crossorigin',
			(string) $tag,
			'The production bundle is served same-origin, so crossorigin must not be emitted.'
		);
	}

	/**
	 * Extract the opening `<script ...>` tag whose `id` attribute matches.
	 *
	 * @param string $html    Rendered HTML to search.
	 * @param string $id_attr The `id` attribute value to match (e.g. `foo-js`).
	 *
	 * @return string|null The opening tag, or null when not found.
	 */
	private function extract_script_tag( string $html, string $id_attr ): ?string {
		$pattern = '#<script\b[^>]*\bid=([\'"])' . preg_quote( $id_attr, '#' ) . '\1[^>]*>#';

		return 1 === preg_match( $pattern, $html, $matches ) ? $matches[0] : null;
	}

	/**
	 * Reset the private static tag-filter idempotency guard on SettingsPage.
	 */
	private function reset_tag_filter_guard(): void {
		$guard = new ReflectionProperty( SettingsPage::class, 'vite_tag_filter_registered' );
		$guard->setAccessible( true );
		$guard->setValue( null, false );
	}
}
