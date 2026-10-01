<?php
/**
 * Unit tests for the SecurityHeaders AjaxController — focused on the status
 * derivation logic exposed through `handle_get_config()` / `handle_save_config()`.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\SecurityHeaders\AjaxController;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPolicy;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPresetLibrary;
use FanxieLab\Warden\Modules\SecurityHeaders\SecurityHeaders;
use FanxieLab\Warden\Modules\SecurityHeaders\ViolationRepository;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * AjaxController status + save-config tests.
 *
 * The `WP_Error` shim is registered from the unit-mode bootstrap
 * (`tests/bootstrap.php`), so `WP_Error::class` resolves to our minimal
 * stand-in when the real WordPress class isn't loaded.
 */
final class AjaxControllerTest extends TestCase {

	/**
	 * In-memory option storage used by the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options_store = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $v ) {
				if ( ! is_string( $v ) ) {
					return '';
				}
				return (string) ( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) ?? '' );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) abs( (int) $v ) );
		Functions\when( 'rest_url' )->alias( static fn ( $p = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->options_store = [];
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return array_key_exists( $key, $this->options_store ) ? $this->options_store[ $key ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options_store[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Spin up a fresh module + controller pair backed by the in-memory option store.
	 *
	 * @return array{0:SecurityHeaders,1:AjaxController}
	 */
	private function make_controller(): array {
		$module     = new SecurityHeaders( new AjaxRouter() );
		$controller = new AjaxController( $module, new ViolationRepository(), new CspPresetLibrary() );
		return [ $module, $controller ];
	}

	public function test_get_config_omits_enabled_key_and_returns_status(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_get_config();

		$this->assertArrayNotHasKey( 'enabled', $result );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'active', $result['status'] );
		$this->assertArrayHasKey( 'active_header_count', $result['status'] );
		$this->assertArrayHasKey( 'csp_active', $result['status'] );
		$this->assertArrayHasKey( 'summary', $result['status'] );
	}

	public function test_defaults_report_four_headers_and_csp_report_only_summary(): void {
		[ , $controller ] = $this->make_controller();

		$status = $controller->handle_get_config()['status'];

		// Defaults: XFO, XCTO, Referrer, Permissions are on; HSTS and
		// cache_control are off (HSTS is opt-in because of its lock-in
		// behaviour on plain HTTP). → 4.
		$this->assertSame( 4, $status['active_header_count'] );
		$this->assertTrue( $status['csp_active'] );
		$this->assertTrue( $status['active'] );
		$this->assertSame( '4 headers · CSP Report-Only', $status['summary'] );
	}

	public function test_save_config_requires_settings_array(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_save_config( [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_settings', $err->get_error_code() );

		$err = $controller->handle_save_config( [ 'settings' => 'not-an-array' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_save_config_persists_and_returns_fresh_status(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_save_config(
			[
				'settings' => [
					'headers' => [
						'hsts' => [ 'enabled' => false ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'enabled', $result );
		$this->assertFalse( $result['settings']['headers']['hsts']['enabled'] );
		// Lost one header (HSTS). Four left.
		$this->assertSame( 4, $result['status']['active_header_count'] );
		$this->assertSame( '4 headers · CSP Report-Only', $result['status']['summary'] );
	}

	public function test_all_off_summary_is_inactive(): void {
		[ $module, $controller ] = $this->make_controller();

		$module->update_config(
			[
				'headers' => [
					'hsts'          => [ 'enabled' => false ],
					'xfo'           => [ 'enabled' => false ],
					'xcto'          => [ 'enabled' => false ],
					'referrer'      => [ 'enabled' => false ],
					'permissions'   => [ 'enabled' => false ],
					'cache_control' => [ 'enabled' => false ],
				],
				'csp'     => [ 'mode' => CspPolicy::MODE_OFF ],
			]
		);

		$status = $controller->handle_get_config()['status'];

		$this->assertFalse( $status['active'] );
		$this->assertFalse( $status['csp_active'] );
		$this->assertSame( 0, $status['active_header_count'] );
		$this->assertSame( 'Inactive', $status['summary'] );
	}

	public function test_headers_only_summary_uses_headers_active_template(): void {
		[ $module, $controller ] = $this->make_controller();

		$module->update_config(
			[
				'headers' => [
					'hsts'          => [ 'enabled' => true ],
					'xfo'           => [ 'enabled' => true ],
					'xcto'          => [ 'enabled' => true ],
					'referrer'      => [ 'enabled' => false ],
					'permissions'   => [ 'enabled' => false ],
					'cache_control' => [ 'enabled' => false ],
				],
				'csp'     => [ 'mode' => CspPolicy::MODE_OFF ],
			]
		);

		$status = $controller->handle_get_config()['status'];

		$this->assertTrue( $status['active'] );
		$this->assertFalse( $status['csp_active'] );
		$this->assertSame( 3, $status['active_header_count'] );
		$this->assertSame( '3 headers active', $status['summary'] );
	}

	public function test_csp_only_summary_uses_csp_only_template(): void {
		[ $module, $controller ] = $this->make_controller();

		$module->update_config(
			[
				'headers' => [
					'hsts'          => [ 'enabled' => false ],
					'xfo'           => [ 'enabled' => false ],
					'xcto'          => [ 'enabled' => false ],
					'referrer'      => [ 'enabled' => false ],
					'permissions'   => [ 'enabled' => false ],
					'cache_control' => [ 'enabled' => false ],
				],
				'csp'     => [ 'mode' => CspPolicy::MODE_ENFORCE ],
			]
		);

		$status = $controller->handle_get_config()['status'];

		$this->assertSame( 0, $status['active_header_count'] );
		$this->assertTrue( $status['csp_active'] );
		$this->assertSame( 'CSP Enforce only', $status['summary'] );
	}

	public function test_single_header_summary_uses_singular(): void {
		[ $module, $controller ] = $this->make_controller();

		$module->update_config(
			[
				'headers' => [
					'hsts'          => [ 'enabled' => true ],
					'xfo'           => [ 'enabled' => false ],
					'xcto'          => [ 'enabled' => false ],
					'referrer'      => [ 'enabled' => false ],
					'permissions'   => [ 'enabled' => false ],
					'cache_control' => [ 'enabled' => false ],
				],
				'csp'     => [ 'mode' => CspPolicy::MODE_REPORT_ONLY ],
			]
		);

		$status = $controller->handle_get_config()['status'];

		$this->assertSame( '1 header · CSP Report-Only', $status['summary'] );
	}
}
