<?php
/**
 * Unit tests for HeaderEmitter.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\SecurityHeaders\HeaderEmitter;
use FanxieLab\Warden\Modules\SecurityHeaders\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HeaderEmitter.
 *
 * Config fixtures use the nested shape declared by `SecurityHeadersConfig`
 * in `assets/admin/src/modules/SecurityHeaders/types.ts`.
 */
final class HeaderEmitterTest extends TestCase {

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
				// Mirror WordPress core: lowercase first, then strip non-[a-z0-9_-].
				return (string) ( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) ?? '' );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) abs( (int) $v ) );
		Functions\when( 'rest_url' )->alias( static fn ( $p = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ) );

		// Context guards for CSP emission — individual tests override as needed.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a module whose `get_config()` returns the supplied payload by
	 * stubbing `get_option` against the settings option key.
	 *
	 * @param array<string, mixed>       $settings Module settings payload (nested).
	 * @param bool                       $is_ssl   Whether the request is HTTPS.
	 * @param array<int, string>         $existing Existing `headers_list()` entries.
	 * @param array<string, string>|null $sink     Optional by-ref map that captures emitted headers.
	 */
	private function make_emitter( array $settings, bool $is_ssl = true, array $existing = [], ?array &$sink = null ): HeaderEmitter {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $settings ) {
				if ( 'fanxie_warden_security-headers_settings' === $key ) {
					return $settings;
				}
				return $default_value;
			}
		);

		$module = new SecurityHeaders( new AjaxRouter() );

		$writer = null;
		if ( null !== $sink ) {
			$writer = static function ( string $name, string $value ) use ( &$sink ): void {
				$sink[ $name ] = $value;
			};
		}

		return new HeaderEmitter(
			$module,
			static fn (): array => $existing,
			static fn (): bool => $is_ssl,
			$writer,
		);
	}

	public function test_build_headers_emits_configured_headers(): void {
		$config = [
			'headers' => [
				'hsts'          => [
					'enabled'            => true,
					'max_age'            => 31536000,
					'include_subdomains' => true,
				],
				'xfo'           => [
					'enabled' => true,
					'value'   => 'SAMEORIGIN',
				],
				'xcto'          => [ 'enabled' => true ],
				'referrer'      => [
					'enabled' => true,
					'value'   => 'strict-origin-when-cross-origin',
				],
				'permissions'   => [
					'enabled' => true,
					'value'   => 'camera=()',
				],
				'cache_control' => [
					'enabled' => false,
					'value'   => 'public, max-age=3600',
				],
			],
			'csp'     => [ 'mode' => 'off' ],
		];

		$emitter = $this->make_emitter( $config );
		$headers = $emitter->build_headers( $config );

		$this->assertSame( 'max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
		$this->assertSame( 'camera=()', $headers['Permissions-Policy'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $headers );
	}

	public function test_hsts_suppressed_on_non_https(): void {
		$config = [
			'headers' => [
				'hsts' => [
					'enabled' => true,
					'max_age' => 31536000,
				],
			],
			'csp'     => [ 'mode' => 'off' ],
		];

		$emitter = $this->make_emitter( $config, false );
		$headers = $emitter->build_headers( $config );

		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}

	public function test_csp_report_only_header_emitted(): void {
		$config = [
			'headers' => [
				'hsts' => [ 'enabled' => false ],
			],
			'csp'     => [
				'mode'          => 'report-only',
				'learning_mode' => true,
				'directives'    => [ 'default-src' => [ "'self'" ] ],
				'report_uri'    => 'https://example.test/report',
			],
		];

		$emitter = $this->make_emitter( $config );
		$headers = $emitter->build_headers( $config );

		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $headers );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers );
		$this->assertStringContainsString( "default-src 'self'", $headers['Content-Security-Policy-Report-Only'] );
		$this->assertStringContainsString( 'report-uri https://example.test/report', $headers['Content-Security-Policy-Report-Only'] );
	}

	public function test_csp_enforce_with_learning_mode_emits_both_headers(): void {
		$config = [
			'headers' => [
				'hsts' => [ 'enabled' => false ],
			],
			'csp'     => [
				'mode'          => 'enforce',
				'learning_mode' => true,
				'directives'    => [ 'default-src' => [ "'self'" ] ],
				'report_uri'    => 'https://example.test/report',
			],
		];

		$emitter = $this->make_emitter( $config );
		$headers = $emitter->build_headers( $config );

		$this->assertArrayHasKey( 'Content-Security-Policy', $headers );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $headers );
	}

	public function test_idempotent_against_already_sent_headers(): void {
		$config = [
			'headers' => [
				'hsts' => [
					'enabled' => true,
					'max_age' => 3600,
				],
			],
			'csp'     => [ 'mode' => 'off' ],
		];

		// Upstream has already emitted HSTS — emit() must notice (via the
		// injected resolver) and not double-send.
		$emitter = $this->make_emitter( $config, true, [ 'Strict-Transport-Security: max-age=99' ] );

		Filters\expectApplied( 'fanxie_warden/security_headers/headers' )
			->once()
			->andReturnUsing( static fn ( array $h ): array => $h );

		$emitter->emit();
		$this->addToAssertionCount( 1 );
	}

	public function test_filter_can_mutate_headers(): void {
		$config = [
			'headers' => [
				'xcto' => [ 'enabled' => true ],
			],
			'csp'     => [ 'mode' => 'off' ],
		];

		Filters\expectApplied( 'fanxie_warden/security_headers/headers' )
			->once()
			->andReturnUsing(
				static function ( array $headers ): array {
					$headers['X-Custom'] = 'yes';
					return $headers;
				}
			);

		$emitter = $this->make_emitter( $config );
		$headers = $emitter->build_headers( $config );

		$this->assertSame( 'yes', $headers['X-Custom'] );
	}

	public function test_csp_header_skipped_on_admin_request(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$config = [
			'headers' => [
				'hsts' => [
					'enabled' => true,
					'max_age' => 3600,
				],
				'xcto' => [ 'enabled' => true ],
			],
			'csp'     => [
				'mode'       => 'report-only',
				'directives' => [ 'default-src' => [ "'self'" ] ],
			],
		];

		Filters\expectApplied( 'fanxie_warden/security_headers/csp_emit_context' )
			->atLeast()->once()
			->andReturnFirstArg();
		Filters\expectApplied( 'fanxie_warden/security_headers/headers' )
			->andReturnFirstArg();

		$sink    = [];
		$emitter = $this->make_emitter( $config, true, [], $sink );
		$emitter->emit();

		$this->assertArrayNotHasKey( 'Content-Security-Policy', $sink );
		$this->assertArrayNotHasKey( 'Content-Security-Policy-Report-Only', $sink );
		$this->assertArrayHasKey( 'Strict-Transport-Security', $sink );
		$this->assertArrayHasKey( 'X-Content-Type-Options', $sink );
	}

	public function test_csp_header_skipped_on_rest_request(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$config = [
			'headers' => [
				'xcto' => [ 'enabled' => true ],
			],
			'csp'     => [
				'mode'       => 'report-only',
				'directives' => [ 'default-src' => [ "'self'" ] ],
			],
		];

		Filters\expectApplied( 'fanxie_warden/security_headers/csp_emit_context' )
			->atLeast()->once()
			->andReturnFirstArg();
		Filters\expectApplied( 'fanxie_warden/security_headers/headers' )
			->andReturnFirstArg();

		$sink    = [];
		$emitter = $this->make_emitter( $config, true, [], $sink );
		$emitter->emit();

		$this->assertArrayNotHasKey( 'Content-Security-Policy-Report-Only', $sink );
		$this->assertArrayHasKey( 'X-Content-Type-Options', $sink );
	}

	public function test_csp_emit_context_filter_overrides_default(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$config = [
			'headers' => [],
			'csp'     => [
				'mode'       => 'report-only',
				'directives' => [ 'default-src' => [ "'self'" ] ],
			],
		];

		Filters\expectApplied( 'fanxie_warden/security_headers/csp_emit_context' )
			->atLeast()->once()
			->andReturn( true );
		Filters\expectApplied( 'fanxie_warden/security_headers/headers' )
			->andReturnFirstArg();

		$sink    = [];
		$emitter = $this->make_emitter( $config, true, [], $sink );
		$emitter->emit();

		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $sink );
	}
}
