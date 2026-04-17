<?php
/**
 * Unit tests for the SecurityHeaders module class.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\SecurityHeaders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspPolicy;
use FanxieLab\WPCore\Modules\SecurityHeaders\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SecurityHeaders module class.
 */
final class SecurityHeadersTest extends TestCase {

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
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'sanitize_key' )->alias( static fn ( $v ) => is_string( $v ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $v ) ?? '' ) : '' );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) abs( (int) $v ) );
		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ) );

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

	private function make_module(): SecurityHeaders {
		$router = new AjaxRouter();
		return new SecurityHeaders( $router );
	}

	public function test_id_is_kebab_case_and_stable(): void {
		$this->assertSame( 'security-headers', $this->make_module()->id() );
		$this->assertSame( 'security-headers', SecurityHeaders::MODULE_ID );
	}

	public function test_name_is_translated_string(): void {
		$this->assertSame( 'Security Headers', $this->make_module()->name() );
	}

	public function test_default_config_exposes_every_documented_key(): void {
		$defaults = $this->make_module()->get_default_config();

		$expected_keys = [
			'headers_hsts_enabled',
			'headers_hsts_max_age',
			'headers_hsts_include_subdomains',
			'headers_xfo_enabled',
			'headers_xfo_value',
			'headers_xcto_enabled',
			'headers_referrer_enabled',
			'headers_referrer_value',
			'headers_permissions_enabled',
			'headers_permissions_value',
			'headers_cache_control_enabled',
			'headers_cache_control_value',
			'csp_mode',
			'csp_learning_mode',
			'csp_directives',
			'csp_report_uri',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "default config missing `{$key}`" );
		}

		$this->assertTrue( $defaults['headers_hsts_enabled'] );
		$this->assertSame( 31536000, $defaults['headers_hsts_max_age'] );
		$this->assertSame( 'SAMEORIGIN', $defaults['headers_xfo_value'] );
		$this->assertSame( CspPolicy::MODE_REPORT_ONLY, $defaults['csp_mode'] );
		$this->assertTrue( $defaults['csp_learning_mode'] );
		$this->assertFalse( $defaults['headers_cache_control_enabled'] );
		$this->assertIsArray( $defaults['csp_directives'] );
	}

	public function test_settings_fields_cover_every_default_key(): void {
		$module   = $this->make_module();
		$defaults = $module->get_default_config();
		$fields   = $module->get_settings_fields();

		$field_ids = array_column( $fields, 'id' );

		foreach ( array_keys( $defaults ) as $key ) {
			$this->assertContains( $key, $field_ids, "settings schema missing `{$key}`" );
		}

		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'id', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'type', $field );
			$this->assertArrayHasKey( 'sanitizer', $field );
			$this->assertArrayHasKey( 'default', $field );
		}
	}

	public function test_is_enabled_round_trips_through_set_enabled(): void {
		$module = $this->make_module();

		$this->assertFalse( $module->is_enabled() );

		$module->set_enabled( true );
		$this->assertTrue( $module->is_enabled() );

		$module->set_enabled( false );
		$this->assertFalse( $module->is_enabled() );
	}

	public function test_update_config_persists_and_sanitises(): void {
		$module = $this->make_module();

		$updated = $module->update_config(
			[
				'headers_hsts_enabled' => 1,   // truthy — should round-trip to bool.
				'headers_hsts_max_age' => '90',
				'csp_mode'             => 'enforce',
				'csp_directives'       => [
					'Script-Src' => [ "'self'", '' ],
					42           => [ 'ignored' ],
				],
				'not_in_schema'        => 'dropped',
			]
		);

		$this->assertTrue( $updated );

		$stored = $module->get_config();

		$this->assertTrue( $stored['headers_hsts_enabled'] );
		$this->assertSame( 90, $stored['headers_hsts_max_age'] );
		$this->assertSame( 'enforce', $stored['csp_mode'] );
		$this->assertSame( [ 'script-src' => [ "'self'" ] ], $stored['csp_directives'] );
		$this->assertArrayNotHasKey( 'not_in_schema', $stored );
	}
}
