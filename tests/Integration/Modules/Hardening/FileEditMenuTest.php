<?php
/**
 * Integration test — `file_mod_allowed` removes the theme/plugin editors from
 * the WordPress admin menu when runtime enforcement is on.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\Runtime\FileEditGuard;
use WP_UnitTestCase;

/**
 * Asserts the core admin menu no longer exposes `theme-editor.php` /
 * `plugin-editor.php` once the Hardening runtime enforce toggle is on.
 *
 * This guards against WordPress core moving the editor items between parent
 * menus in future releases — the guard operates on `file_mod_allowed` contexts
 * rather than hard-coded menu slugs, but the "no editor entry anywhere" claim
 * is what actually matters to users.
 */
final class FileEditMenuTest extends WP_UnitTestCase {

	protected function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Put WordPress in admin context — `_wp_menu_output` et al require it.
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
		set_current_screen( 'dashboard' );

		( new FileEditGuard(
			[
				'file_editing' => [ 'runtime_enforce' => true ],
			]
		) )->register_hooks();
	}

	public function test_theme_and_plugin_editor_submenus_are_absent(): void {
		global $menu, $submenu;
		$menu    = [];
		$submenu = [];

		// Bring the full admin menu into memory. This file defines the default
		// `$menu` / `$submenu` globals using current capabilities (which now
		// filter through `file_mod_allowed`).
		require ABSPATH . 'wp-admin/includes/menu.php';

		foreach ( [ 'tools.php', 'themes.php', 'plugins.php' ] as $parent ) {
			$entries = $submenu[ $parent ] ?? [];

			foreach ( (array) $entries as $entry ) {
				$slug = isset( $entry[2] ) ? (string) $entry[2] : '';
				$this->assertNotSame(
					'theme-editor.php',
					$slug,
					"theme-editor.php leaked under {$parent}"
				);
				$this->assertNotSame(
					'plugin-editor.php',
					$slug,
					"plugin-editor.php leaked under {$parent}"
				);
			}
		}
	}

	public function test_runtime_enforce_false_leaves_editors_reachable(): void {
		// Rewire without the guard — `file_mod_allowed` goes back to default.
		remove_all_filters( 'file_mod_allowed' );

		global $menu, $submenu;
		$menu    = [];
		$submenu = [];

		require ABSPATH . 'wp-admin/includes/menu.php';

		$slugs = [];
		foreach ( [ 'tools.php', 'themes.php', 'plugins.php' ] as $parent ) {
			foreach ( (array) ( $submenu[ $parent ] ?? [] ) as $entry ) {
				$slugs[] = isset( $entry[2] ) ? (string) $entry[2] : '';
			}
		}

		// At least one of the two editor entries should exist when the guard
		// is off — unless WordPress itself elided them for a reason unrelated
		// to our code (e.g. `DISALLOW_FILE_EDIT` already defined).
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$this->markTestSkipped( 'DISALLOW_FILE_EDIT active — editors already blocked at core layer.' );
		}

		$this->assertTrue(
			in_array( 'theme-editor.php', $slugs, true )
				|| in_array( 'plugin-editor.php', $slugs, true ),
			'Expected at least one file editor entry when runtime enforce is off.'
		);
	}
}
