<?php
/**
 * Unit tests for SettingsPage menu registration.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\SettingsPage;
use FanxieLab\WPCore\Modules\ModuleRegistry;
use FanxieLab\WPCore\Plugin;
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

	public function test_register_menu_adds_top_level_fx_core_menu(): void {
		// ModuleRegistry is `final`, so Mockery cannot mock it; register_menu()
		// never touches the registry, so a real (empty) instance is sufficient.
		$page = new SettingsPage( new ModuleRegistry() );

		Functions\expect( 'add_menu_page' )
			->once()
			->with(
				'Fanxie WP Core',
				'FX Core',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' ),
				Mockery::type( 'string' ), // data: URI icon.
				Mockery::any()
			)
			->andReturn( 'toplevel_page_fanxie-wp-core' );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				SettingsPage::MENU_SLUG,
				'Fanxie WP Core',
				'Settings',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' )
			)
			->andReturn( 'fanxie-wp-core_page' );

		$page->register_menu();

		// The Brain Monkey Functions\expect() calls above are the assertions;
		// register their satisfaction so PHPUnit does not flag this as risky
		// (phpunit.xml.dist runs with failOnRisky="true").
		$this->addToAssertionCount( 1 );
	}
}
