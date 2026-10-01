<?php
/**
 * Unit tests for ModuleBase — focused on the nested-schema sanitiser.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\ModuleBase;
use PHPUnit\Framework\TestCase;

/**
 * Locally-scoped fixture exercising dot-path ids + `sanitizer_callback`.
 *
 * Kept inline (matches the `FanxieTestModule` pattern in ModuleRegistryTest).
 */
final class FanxieNestedTestModule extends ModuleBase {

	/**
	 * Optional override for the backing options store — lets individual tests
	 * pre-seed persisted state.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $seeded_settings = null;

	public function id(): string {
		return 'nested-test';
	}

	public function name(): string {
		return 'Nested Test Module';
	}

	public function register_hooks(): void {}

	public function get_default_config(): array {
		return [
			'headers' => [
				'hsts' => [
					'enabled' => true,
					'max_age' => 31536000,
				],
				'xfo'  => [
					'enabled' => true,
					'value'   => 'SAMEORIGIN',
				],
			],
			'csp'     => [
				'mode'       => 'report-only',
				'directives' => [
					'default-src' => [ "'self'" ],
				],
			],
		];
	}

	public function get_settings_fields(): array {
		return [
			[
				'id'        => 'headers.hsts.enabled',
				'label'     => 'HSTS on',
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers.hsts.max_age',
				'label'     => 'HSTS max-age',
				'type'      => 'int',
				'default'   => 31536000,
				'sanitizer' => 'absint',
			],
			[
				'id'        => 'headers.xfo.enabled',
				'label'     => 'XFO on',
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'headers.xfo.value',
				'label'     => 'XFO value',
				'type'      => 'select',
				'default'   => 'SAMEORIGIN',
				'sanitizer' => 'text',
			],
			[
				'id'        => 'csp.mode',
				'label'     => 'CSP mode',
				'type'      => 'select',
				'default'   => 'report-only',
				'sanitizer' => 'key',
			],
			[
				'id'                 => 'csp.directives',
				'label'              => 'CSP directives',
				'type'               => 'textarea',
				'default'            => [ 'default-src' => [ "'self'" ] ],
				'sanitizer_callback' => static function ( mixed $value ): array {
					if ( ! is_array( $value ) ) {
						return [];
					}
					$clean = [];
					foreach ( $value as $k => $v ) {
						if ( ! is_string( $k ) || ! is_array( $v ) ) {
							continue;
						}
						$items = [];
						foreach ( $v as $item ) {
							if ( is_string( $item ) && '' !== $item ) {
								$items[] = $item;
							}
						}
						$clean[ strtolower( $k ) ] = array_values( array_unique( $items ) );
					}
					return $clean;
				},
			],
		];
	}
}

/**
 * ModuleBase sanitiser test suite.
 */
final class ModuleBaseTest extends TestCase {

	/**
	 * Backing options store populated by the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options_store = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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

	public function test_get_config_returns_defaults_when_nothing_persisted(): void {
		$module = new FanxieNestedTestModule();
		$config = $module->get_config();

		$this->assertTrue( $config['headers']['hsts']['enabled'] );
		$this->assertSame( 31536000, $config['headers']['hsts']['max_age'] );
		$this->assertSame( 'SAMEORIGIN', $config['headers']['xfo']['value'] );
		$this->assertSame( 'report-only', $config['csp']['mode'] );
		$this->assertSame( [ 'default-src' => [ "'self'" ] ], $config['csp']['directives'] );
	}

	public function test_update_config_sanitises_and_persists_nested_values(): void {
		$module = new FanxieNestedTestModule();

		$ok = $module->update_config(
			[
				'headers' => [
					'hsts' => [
						'enabled' => 0,
						'max_age' => '-50', // absint → 50.
					],
					'xfo'  => [
						'value' => '  DENY  ',
					],
				],
				'csp'     => [
					'mode'       => 'ENFORCE ',
					'directives' => [
						'Script-Src' => [ "'self'", 42, '' ],
					],
				],
			]
		);

		$this->assertTrue( $ok );

		$stored = $module->get_config();

		$this->assertFalse( $stored['headers']['hsts']['enabled'] );
		$this->assertSame( 50, $stored['headers']['hsts']['max_age'] );
		// Sibling we didn't touch picks up the default after recursive merge.
		$this->assertTrue( $stored['headers']['xfo']['enabled'] );
		$this->assertSame( 'DENY', $stored['headers']['xfo']['value'] );
		$this->assertSame( 'enforce', $stored['csp']['mode'] );
		$this->assertSame( [ 'script-src' => [ "'self'" ] ], $stored['csp']['directives'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$module = new FanxieNestedTestModule();

		$module->update_config(
			[
				'headers'           => [
					'hsts'     => [
						'enabled'       => true,
						'not_in_schema' => 'should-be-dropped',
					],
					'surprise' => 'dropped',
				],
				'top_level_garbage' => 'dropped',
			]
		);

		$stored = $module->get_config();

		$this->assertArrayNotHasKey( 'not_in_schema', $stored['headers']['hsts'] );
		$this->assertArrayNotHasKey( 'surprise', $stored['headers'] );
		$this->assertArrayNotHasKey( 'top_level_garbage', $stored );
	}

	public function test_partial_payload_fills_missing_branches_from_defaults(): void {
		$module = new FanxieNestedTestModule();

		$module->update_config(
			[
				'headers' => [
					'hsts' => [ 'enabled' => false ],
				],
			]
		);

		$stored = $module->get_config();

		$this->assertFalse( $stored['headers']['hsts']['enabled'] );
		// max_age untouched by the payload — pulled from default.
		$this->assertSame( 31536000, $stored['headers']['hsts']['max_age'] );
		// Whole xfo branch pulled from default.
		$this->assertTrue( $stored['headers']['xfo']['enabled'] );
		$this->assertSame( 'SAMEORIGIN', $stored['headers']['xfo']['value'] );
		// Whole csp branch pulled from default.
		$this->assertSame( 'report-only', $stored['csp']['mode'] );
		$this->assertSame( [ 'default-src' => [ "'self'" ] ], $stored['csp']['directives'] );
	}

	public function test_sanitizer_callback_is_invoked_for_structured_fields(): void {
		$module = new FanxieNestedTestModule();

		// The directives callback should ignore non-string keys and empty values.
		$module->update_config(
			[
				'csp' => [
					'directives' => [
						'Default-Src' => [ "'self'", 'https://cdn.example.com' ],
						'img-src'     => [ "'self'", '', 'data:' ],
						42            => [ 'ignored' ],
						'mixed'       => 'not-an-array',
					],
				],
			]
		);

		$stored = $module->get_config();

		$this->assertSame(
			[
				'default-src' => [ "'self'", 'https://cdn.example.com' ],
				'img-src'     => [ "'self'", 'data:' ],
			],
			$stored['csp']['directives']
		);
	}

	public function test_invalid_stored_option_falls_back_to_defaults(): void {
		$module = new FanxieNestedTestModule();

		// Simulate a corrupted option (not an array).
		$this->options_store['fanxie_warden_nested-test_settings'] = 'corrupted';

		$stored = $module->get_config();

		$this->assertSame( 31536000, $stored['headers']['hsts']['max_age'] );
		$this->assertSame( 'report-only', $stored['csp']['mode'] );
	}
}
