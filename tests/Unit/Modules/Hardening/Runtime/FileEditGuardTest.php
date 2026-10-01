<?php
/**
 * Unit tests for FileEditGuard.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\Hardening\Runtime\FileEditGuard;
use PHPUnit\Framework\TestCase;

/**
 * Verifies file_mod_allowed filter behaviour.
 */
final class FileEditGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_blocks_the_editor_contexts_wordpress_actually_emits(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		// WordPress core gates BOTH editors via `capability_edit_themes`
		// (wp-includes/capabilities.php); `capability_edit_plugins` is defensive.
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'capability_edit_themes' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'capability_edit_plugins' ) );
	}

	public function test_does_not_block_installs_updates_or_unrelated_contexts(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		// Must NOT over-block: plugin/theme install/update flow through this one.
		$this->assertTrue( $guard->filter_file_mod_allowed( true, 'capability_update_core' ) );
		// The old, unprefixed strings are fictional — they pass through untouched now.
		$this->assertTrue( $guard->filter_file_mod_allowed( true, 'edit_themes' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( false, 'automatic_updater' ) );
	}

	public function test_register_hooks_is_noop_when_disabled(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => false ] ] );
		$guard->register_hooks();

		$this->assertFalse( Filters\has( 'file_mod_allowed', [ $guard, 'filter_file_mod_allowed' ] ) );
	}
}
