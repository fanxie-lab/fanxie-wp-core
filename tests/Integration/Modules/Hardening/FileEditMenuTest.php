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
 * Asserts the Hardening runtime enforce toggle turns off `file_mod_allowed`
 * for the editor contexts WordPress uses to decide whether to expose the
 * theme-editor / plugin-editor menu entries.
 *
 * We assert at the filter layer, not the admin-menu layer: the admin menu is
 * populated by `wp-admin/includes/menu.php` (a procedural script that depends
 * on the full admin request machinery and is not safe to include more than
 * once per process). Since WordPress gates the editor entries on
 * `wp_is_file_mod_allowed( 'capability_edit_themes' )` and
 * `wp_is_file_mod_allowed( 'capability_edit_plugins' )` respectively, asserting
 * those return `false` (or `true` with the guard off) is equivalent to
 * asserting the menu entries are absent (or present).
 */
final class FileEditMenuTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		// `wp_is_file_mod_allowed()` lives in `wp-admin/includes/file.php` — load it
		// once so both tests can call it without pulling in the whole admin
		// bootstrap stack.
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	public function test_runtime_enforce_blocks_editor_file_mod_contexts(): void {
		( new FileEditGuard(
			[
				'file_editing' => [ 'runtime_enforce' => true ],
			]
		) )->register_hooks();

		foreach ( [ 'capability_edit_themes', 'capability_edit_plugins' ] as $context ) {
			$this->assertFalse(
				wp_is_file_mod_allowed( $context ),
				"wp_is_file_mod_allowed({$context}) should be false when runtime enforce is on."
			);
		}
	}

	public function test_runtime_enforce_false_leaves_editor_contexts_reachable(): void {
		// No guard registered. WordPress's default for `file_mod_allowed` is true
		// (unless DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS is set at config level).
		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			|| ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
		) {
			$this->markTestSkipped( 'Core-level file edit block active — guard is redundant here.' );
		}

		foreach ( [ 'capability_edit_themes', 'capability_edit_plugins' ] as $context ) {
			$this->assertTrue(
				wp_is_file_mod_allowed( $context ),
				"wp_is_file_mod_allowed({$context}) should be true when the Hardening guard is not registered."
			);
		}
	}
}
