<?php
/**
 * Unit tests for HeaderEmitter.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\SecurityHeaders;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\SecurityHeaders\HeaderEmitter;
use FanxieLab\WPCore\Modules\SecurityHeaders\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HeaderEmitter.
 */
final class HeaderEmitterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn ( $v ) => is_string( $v ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $v ) ?? '' ) : '' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) abs( (int) $v ) );
		Functions\when( 'rest_url' )->alias( static fn ( $p = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a module whose `get_config()` returns the supplied payload by
	 * stubbing `get_option` against the settings option key.
	 *
	 * @param array<string, mixed> $settings Module settings payload.
	 * @param bool                 $is_ssl   Whether the request is HTTPS.
	 * @param array<int, string>   $existing Existing `headers_list()` entries.
	 */
	private function make_emitter( array $settings, bool $is_ssl = true, array $existing = [] ): HeaderEmitter {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $settings ) {
				if ( 'fanxie_wp_core_security-headers_settings' === $key ) {
					return $settings;
				}
				return $default_value;
			}
		);

		$module = new SecurityHeaders( new AjaxRouter() );

		return new HeaderEmitter(
			$module,
			static fn (): array => $existing,
			static fn (): bool => $is_ssl,
		);
	}

	public function test_build_headers_emits_configured_headers(): void {
		// Only the flags that HeaderEmitter reads — defaults fill the rest.
		$config = [
			'headers_hsts_enabled'            => true,
			'headers_hsts_max_age'            => 31536000,
			'headers_hsts_include_subdomains' => true,
			'headers_xfo_enabled'             => true,
			'headers_xfo_value'               => 'SAMEORIGIN',
			'headers_xcto_enabled'            => true,
			'headers_referrer_enabled'        => true,
			'headers_referrer_value'          => 'strict-origin-when-cross-origin',
			'headers_permissions_enabled'     => true,
			'headers_permissions_value'       => 'camera=()',
			'headers_cache_control_enabled'   => false,
			'csp_mode'                        => 'off',
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
			'headers_hsts_enabled' => true,
			'headers_hsts_max_age' => 31536000,
			'csp_mode'             => 'off',
		];

		$emitter = $this->make_emitter( $config, false );
		$headers = $emitter->build_headers( $config );

		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}

	public function test_csp_report_only_header_emitted(): void {
		$config = [
			'headers_hsts_enabled' => false,
			'csp_mode'             => 'report-only',
			'csp_learning_mode'    => true,
			'csp_directives'       => [ 'default-src' => [ "'self'" ] ],
			'csp_report_uri'       => 'https://example.test/report',
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
			'headers_hsts_enabled' => false,
			'csp_mode'             => 'enforce',
			'csp_learning_mode'    => true,
			'csp_directives'       => [ 'default-src' => [ "'self'" ] ],
			'csp_report_uri'       => 'https://example.test/report',
		];

		$emitter = $this->make_emitter( $config );
		$headers = $emitter->build_headers( $config );

		$this->assertArrayHasKey( 'Content-Security-Policy', $headers );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $headers );
	}

	public function test_idempotent_against_already_sent_headers(): void {
		$config = [
			'headers_hsts_enabled' => true,
			'headers_hsts_max_age' => 3600,
			'csp_mode'             => 'off',
		];

		// Upstream has already emitted HSTS — emit() must notice (via the
		// injected resolver) and not double-send.
		$emitter = $this->make_emitter( $config, true, [ 'Strict-Transport-Security: max-age=99' ] );

		Filters\expectApplied( 'fanxie_wp_core/security_headers/headers' )
			->once()
			->andReturnUsing( static fn ( array $h ): array => $h );

		$emitter->emit();
		$this->addToAssertionCount( 1 );
	}

	public function test_filter_can_mutate_headers(): void {
		$config = [
			'headers_xcto_enabled' => true,
			'csp_mode'             => 'off',
		];

		Filters\expectApplied( 'fanxie_wp_core/security_headers/headers' )
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
}
