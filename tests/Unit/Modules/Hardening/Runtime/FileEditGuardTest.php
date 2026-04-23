<?php
/**
 * Unit tests for FileEditGuard.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\FileEditGuard;
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

	public function test_blocks_edit_themes_and_edit_plugins(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'edit_themes' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'edit_plugins' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'edit_theme' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'edit_plugin' ) );
	}

	public function test_passes_through_other_contexts(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		$this->assertTrue( $guard->filter_file_mod_allowed( true, 'automatic_updater_disabled' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( false, 'download_url' ) );
	}

	public function test_register_hooks_is_noop_when_disabled(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => false ] ] );
		$guard->register_hooks();

		$this->assertFalse( Filters\has( 'file_mod_allowed', [ $guard, 'filter_file_mod_allowed' ] ) );
	}
}
