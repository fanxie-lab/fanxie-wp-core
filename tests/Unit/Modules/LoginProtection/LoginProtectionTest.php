<?php
/**
 * Unit tests for the Login Protection module class.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\LoginProtection\LoginProtection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Login Protection module class.
 */
final class LoginProtectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module(): LoginProtection {
		// AjaxRouter is `final` (cannot be Mockery-mocked) and its constructor
		// self-registers a handler via sanitize_key(). Task 1 never touches the
		// router (register_hooks() is an empty stub), so a real, typed instance
		// built without its constructor is the faithful do-nothing stand-in.
		$router = ( new \ReflectionClass( AjaxRouter::class ) )->newInstanceWithoutConstructor();

		return new LoginProtection( $router );
	}

	public function test_identity(): void {
		$m = $this->module();
		$this->assertSame( 'login-protection', $m->id() );
		$this->assertSame( 'Login Protection', $m->name() );
	}

	public function test_default_config_shape(): void {
		$c = $this->module()->get_default_config();
		$this->assertTrue( $c['attempts']['enabled'] );    // Attempt limiting is ON by default.
		$this->assertFalse( $c['hide_login']['enabled'] );  // Hide-login is OFF.
		$this->assertFalse( $c['passwords']['enforce'] );   // Strong passwords are OFF.
		$this->assertFalse( $c['sessions']['enabled'] );    // Session timeout is OFF.
		$this->assertSame( [ 5, 10, 20 ], array_column( $c['attempts']['tiers'], 'threshold' ) );
	}

	public function test_config_sanitises_and_round_trips(): void {
		$stored = [];

		Functions\when( 'get_option' )->alias(
			function ( $k, $d = false ) use ( &$stored ) {
				return $stored[ $k ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$stored ) {
				$stored[ $k ] = $v;

				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $v ) {
				return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $v ) {
				return trim( (string) $v );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			function ( $v ) {
				return strtolower( trim( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $v ), '-' ) );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $v ) {
				return abs( (int) $v );
			}
		);

		$m = $this->module();
		$m->update_config( [ 'hide_login' => [ 'enabled' => true, 'slug' => 'My Portal!' ] ] );
		$c = $m->get_config();
		$this->assertTrue( $c['hide_login']['enabled'] );
		$this->assertSame( 'my-portal', $c['hide_login']['slug'] ); // The slug sanitizer_callback slugifies + guards reserved paths.
	}
}
