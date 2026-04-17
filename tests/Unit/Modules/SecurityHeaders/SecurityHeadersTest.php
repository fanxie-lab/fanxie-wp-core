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
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository;
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
		Functions\when( 'sanitize_key' )->alias(
			static function ( $v ) {
				if ( ! is_string( $v ) ) {
					return '';
				}
				// Mirror WordPress core: lowercase first, then strip non-[a-z0-9_-].
				return (string) ( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) ?? '' );
			}
		);
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

	public function test_default_config_is_nested_per_vue_contract(): void {
		$defaults = $this->make_module()->get_default_config();

		// Top-level: `headers` + `csp` — mirrors SecurityHeadersConfig in types.ts.
		$this->assertArrayHasKey( 'headers', $defaults );
		$this->assertArrayHasKey( 'csp', $defaults );
		$this->assertIsArray( $defaults['headers'] );
		$this->assertIsArray( $defaults['csp'] );

		// Non-CSP headers branch.
		foreach ( [ 'hsts', 'xfo', 'xcto', 'referrer', 'permissions', 'cache_control' ] as $section ) {
			$this->assertArrayHasKey( $section, $defaults['headers'], "headers.{$section} missing" );
			$this->assertIsArray( $defaults['headers'][ $section ] );
			$this->assertArrayHasKey( 'enabled', $defaults['headers'][ $section ] );
		}

		// HSTS specifics. HSTS is OFF by default — once cached, a browser
		// will refuse plain HTTP for `max_age` seconds, so operators must
		// opt in explicitly. `includeSubDomains` is likewise off by default.
		$this->assertFalse( $defaults['headers']['hsts']['enabled'] );
		$this->assertSame( 31536000, $defaults['headers']['hsts']['max_age'] );
		$this->assertFalse( $defaults['headers']['hsts']['include_subdomains'] );

		// XFO value.
		$this->assertSame( 'SAMEORIGIN', $defaults['headers']['xfo']['value'] );

		// Cache-Control off by default.
		$this->assertFalse( $defaults['headers']['cache_control']['enabled'] );

		// CSP branch.
		foreach ( [ 'mode', 'learning_mode', 'directives', 'report_uri' ] as $key ) {
			$this->assertArrayHasKey( $key, $defaults['csp'], "csp.{$key} missing" );
		}
		$this->assertSame( CspPolicy::MODE_REPORT_ONLY, $defaults['csp']['mode'] );
		$this->assertTrue( $defaults['csp']['learning_mode'] );
		$this->assertIsArray( $defaults['csp']['directives'] );
	}

	public function test_settings_fields_use_dot_path_ids(): void {
		$module = $this->make_module();
		$fields = $module->get_settings_fields();

		$ids = array_column( $fields, 'id' );

		$expected = [
			'headers.hsts.enabled',
			'headers.hsts.max_age',
			'headers.hsts.include_subdomains',
			'headers.xfo.enabled',
			'headers.xfo.value',
			'headers.xcto.enabled',
			'headers.referrer.enabled',
			'headers.referrer.value',
			'headers.permissions.enabled',
			'headers.permissions.value',
			'headers.cache_control.enabled',
			'headers.cache_control.value',
			'csp.mode',
			'csp.learning_mode',
			'csp.directives',
			'csp.report_uri',
		];

		foreach ( $expected as $id ) {
			$this->assertContains( $id, $ids, "settings schema missing dot-path id `{$id}`" );
		}

		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'id', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'type', $field );
			$this->assertArrayHasKey( 'default', $field );
			// Exactly one of `sanitizer` / `sanitizer_callback` must be present.
			$has_scalar   = isset( $field['sanitizer'] );
			$has_callback = isset( $field['sanitizer_callback'] );
			$this->assertTrue(
				$has_scalar xor $has_callback,
				"field `{$field['id']}` must declare either `sanitizer` or `sanitizer_callback`"
			);
			if ( $has_callback ) {
				$this->assertIsCallable( $field['sanitizer_callback'] );
			}
		}
	}

	public function test_update_config_persists_and_sanitises_nested_payload(): void {
		$module = $this->make_module();

		$updated = $module->update_config(
			[
				'headers'       => [
					'hsts' => [
						'enabled' => 1,     // truthy — should round-trip to bool.
						'max_age' => '90',  // string — should coerce via absint.
					],
				],
				'csp'           => [
					'mode'       => 'enforce',
					'directives' => [
						'Script-Src' => [ "'self'", '' ],
						42           => [ 'ignored' ],
					],
				],
				'not_in_schema' => 'dropped',
			]
		);

		$this->assertTrue( $updated );

		$stored = $module->get_config();

		$this->assertTrue( $stored['headers']['hsts']['enabled'] );
		$this->assertSame( 90, $stored['headers']['hsts']['max_age'] );
		$this->assertSame( 'enforce', $stored['csp']['mode'] );
		$this->assertSame( [ 'script-src' => [ "'self'" ] ], $stored['csp']['directives'] );
		$this->assertArrayNotHasKey( 'not_in_schema', $stored );
	}

	public function test_get_config_fills_missing_nested_branches_from_defaults(): void {
		$module = $this->make_module();

		// Persist only a partial payload — everything else must come from defaults.
		$module->update_config(
			[
				'headers' => [
					'hsts' => [ 'enabled' => false ],
				],
			]
		);

		$stored = $module->get_config();

		// Overridden value is honoured.
		$this->assertFalse( $stored['headers']['hsts']['enabled'] );
		// Sibling defaults still fill in.
		$this->assertSame( 31536000, $stored['headers']['hsts']['max_age'] );
		$this->assertFalse( $stored['headers']['hsts']['include_subdomains'] );
		// Other branches untouched.
		$this->assertSame( 'SAMEORIGIN', $stored['headers']['xfo']['value'] );
		$this->assertSame( CspPolicy::MODE_REPORT_ONLY, $stored['csp']['mode'] );
	}

	/**
	 * Regression: ModuleRegistry runs `register_hooks()` on `init:5`, so any
	 * attempt to hook install() onto an earlier `init` priority silently
	 * no-ops — WordPress never re-enters earlier priorities of an already-
	 * firing action. The module must detect that `init` has already fired and
	 * invoke install() directly instead of queuing a stillborn callback.
	 *
	 * Before the fix, the violations table was never created and CSP reports
	 * were accepted (204) but never persisted.
	 */
	public function test_register_hooks_installs_table_directly_when_init_already_fired(): void {
		// Mark install as already done so the no-op early return in
		// ViolationRepository::install() kicks in and we don't touch $wpdb.
		$this->options_store[ ViolationRepository::SCHEMA_VERSION_OPTION ] = ViolationRepository::SCHEMA_VERSION;

		// Simulate being called after `init` has fired (the real bug surface).
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 3600 );
		Functions\when( 'add_filter' )->justReturn( true );

		// Assert: the install closure is NOT queued on init when init has
		// already fired (it would never run if it were).
		$this->make_module()->register_hooks();

		// Before the fix this callback was queued at priority -1 and silently
		// dropped (init was already past -1), so the table was never created.
		$this->assertFalse(
			Monkey\Actions\has( 'init', null, -1 ),
			'install() must not be queued on init:-1 when init has already fired'
		);
	}

	public function test_register_hooks_queues_install_on_init_when_init_has_not_fired(): void {
		$this->options_store[ ViolationRepository::SCHEMA_VERSION_OPTION ] = ViolationRepository::SCHEMA_VERSION;

		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 3600 );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->make_module()->register_hooks();

		// Before `init` fires, install() must be scheduled for init:-1 so the
		// table is created before the first request touches the repository.
		$this->assertNotFalse(
			Monkey\Actions\has( 'init', null, -1 ),
			'install() must be queued on init:-1 when init has not yet fired'
		);
	}
}
