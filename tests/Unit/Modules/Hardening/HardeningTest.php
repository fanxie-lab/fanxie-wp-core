<?php
/**
 * Unit tests for the Hardening module class.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\Hardening\Hardening;
use FanxieLab\Warden\Modules\Hardening\Runtime\XmlRpcGate;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Hardening module class.
 */
final class HardeningTest extends TestCase {

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

	private function make_module(): Hardening {
		return new Hardening( new AjaxRouter() );
	}

	public function test_id_and_name_are_stable(): void {
		$this->assertSame( 'hardening', $this->make_module()->id() );
		$this->assertSame( 'hardening', Hardening::MODULE_ID );
		$this->assertSame( 'Hardening', $this->make_module()->name() );
	}

	public function test_default_config_covers_every_subtree(): void {
		$defaults = $this->make_module()->get_default_config();

		foreach (
			[
				'user_enumeration',
				'xmlrpc',
				'version_hiding',
				'uploads',
				'login',
				'file_editing',
				'application_passwords',
			] as $section
		) {
			$this->assertArrayHasKey( $section, $defaults, "missing `{$section}` subtree" );
			$this->assertIsArray( $defaults[ $section ] );
		}

		$this->assertTrue( $defaults['user_enumeration']['block_author_archive'] );
		$this->assertSame( XmlRpcGate::MODE_DISABLED, $defaults['xmlrpc']['mode'] );
		$this->assertSame( [], $defaults['xmlrpc']['allowed_ips'] );
		$this->assertTrue( $defaults['uploads']['drop_index'] );
		$this->assertFalse( $defaults['file_editing']['runtime_enforce'] );
		$this->assertFalse( $defaults['application_passwords']['disable'] );
	}

	public function test_settings_schema_is_well_formed(): void {
		$fields = $this->make_module()->get_settings_fields();

		$ids = array_column( $fields, 'id' );

		$expected = [
			'user_enumeration.block_author_archive',
			'user_enumeration.block_rest_users_endpoint',
			'xmlrpc.mode',
			'xmlrpc.allowed_ips',
			'version_hiding.remove_powered_by',
			'version_hiding.remove_wp_generator',
			'version_hiding.remove_rss_generator',
			'version_hiding.strip_version_query',
			'version_hiding.block_readme_license',
			'uploads.drop_index',
			'uploads.block_php_execution',
			'login.obfuscate_errors',
			'file_editing.runtime_enforce',
			'application_passwords.disable',
		];

		foreach ( $expected as $id ) {
			$this->assertContains( $id, $ids, "schema missing `{$id}`" );
		}

		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'id', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'type', $field );
			$has_scalar   = isset( $field['sanitizer'] );
			$has_callback = isset( $field['sanitizer_callback'] );
			$this->assertTrue(
				$has_scalar xor $has_callback,
				"field `{$field['id']}` must declare `sanitizer` XOR `sanitizer_callback`"
			);
		}
	}

	public function test_update_config_persists_and_sanitises_payload(): void {
		$module = $this->make_module();

		$module->update_config(
			[
				'xmlrpc'        => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => [ '10.0.0.1', 'not-an-ip', '  192.168.1.5  ', '' ],
				],
				'uploads'       => [
					'drop_index'          => 0,
					'block_php_execution' => '1',
				],
				'not_in_schema' => 'dropped',
			]
		);

		$stored = $module->get_config();

		$this->assertSame( 'restrict_ips', $stored['xmlrpc']['mode'] );
		$this->assertSame( [ '10.0.0.1', '192.168.1.5' ], $stored['xmlrpc']['allowed_ips'] );
		$this->assertFalse( $stored['uploads']['drop_index'] );
		$this->assertTrue( $stored['uploads']['block_php_execution'] );
		$this->assertArrayNotHasKey( 'not_in_schema', $stored );
	}

	public function test_unknown_xmlrpc_mode_falls_back_to_disabled(): void {
		$module = $this->make_module();

		$module->update_config(
			[
				'xmlrpc' => [ 'mode' => 'PURGE-EVERYTHING' ],
			]
		);

		$this->assertSame( XmlRpcGate::MODE_DISABLED, $module->get_config()['xmlrpc']['mode'] );
	}

	public function test_allowed_ips_accepts_string_payload(): void {
		$module = $this->make_module();

		$module->update_config(
			[
				'xmlrpc' => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => "10.0.0.1\n 10.0.0.2 ,junk, 2001:db8::1",
				],
			]
		);

		$stored = $module->get_config()['xmlrpc']['allowed_ips'];

		$this->assertSame( [ '10.0.0.1', '10.0.0.2', '2001:db8::1' ], $stored );
	}

	public function test_allowed_ips_caps_at_50(): void {
		$module = $this->make_module();

		$ips = [];
		for ( $i = 1; $i <= 60; $i++ ) {
			$ips[] = '10.0.0.' . $i;
		}

		$module->update_config(
			[
				'xmlrpc' => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => $ips,
				],
			]
		);

		$this->assertCount( 50, $module->get_config()['xmlrpc']['allowed_ips'] );
	}

	public function test_allowed_ips_sanitizer_replaces_defaults_wholesale(): void {
		$module = $this->make_module();

		// Missing ips in payload → defaults to the schema default ([]).
		$module->update_config( [ 'xmlrpc' => [ 'mode' => 'disabled' ] ] );

		$this->assertSame( [], $module->get_config()['xmlrpc']['allowed_ips'] );
	}
}
