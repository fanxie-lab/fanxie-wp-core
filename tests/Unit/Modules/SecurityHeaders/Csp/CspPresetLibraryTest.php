<?php
/**
 * Unit tests for CspPresetLibrary.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders\Csp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPresetLibrary;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\Preset;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CspPresetLibrary.
 */
final class CspPresetLibraryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_returns_the_three_documented_presets(): void {
		$library = new CspPresetLibrary();
		$all     = $library->all();

		$this->assertArrayHasKey( 'woocommerce', $all );
		$this->assertArrayHasKey( 'ga-gtm', $all );
		$this->assertArrayHasKey( 'meta-pixel', $all );

		foreach ( $all as $preset ) {
			$this->assertInstanceOf( Preset::class, $preset );
		}
	}

	public function test_woocommerce_preset_includes_stripe_paypal_and_square(): void {
		$preset = ( new CspPresetLibrary() )->get( 'woocommerce' );

		$this->assertNotNull( $preset );
		$script = $preset->directives['script-src'] ?? [];
		$this->assertContains( 'https://js.stripe.com', $script );
		$this->assertContains( 'https://*.paypal.com', $script );
		$this->assertContains( 'https://*.squareup.com', $script );
	}

	public function test_ga_gtm_preset_exposes_google_domains(): void {
		$preset = ( new CspPresetLibrary() )->get( 'ga-gtm' );

		$this->assertNotNull( $preset );
		$this->assertContains( 'https://*.google-analytics.com', $preset->directives['script-src'] );
		$this->assertContains( 'https://*.googletagmanager.com', $preset->directives['script-src'] );
	}

	public function test_meta_pixel_preset_has_facebook_connect_domain(): void {
		$preset = ( new CspPresetLibrary() )->get( 'meta-pixel' );

		$this->assertNotNull( $preset );
		$this->assertContains( 'https://connect.facebook.net', $preset->directives['script-src'] );
	}

	public function test_get_returns_null_for_unknown_id(): void {
		$this->assertNull( ( new CspPresetLibrary() )->get( 'does-not-exist' ) );
	}

	public function test_filter_can_add_a_preset(): void {
		$custom = new Preset( 'custom', 'Custom', [ 'connect-src' => [ 'https://c.test' ] ] );

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( $custom ) {
				if ( 'fanxie_warden/security_headers/csp_presets' === $hook && is_array( $value ) ) {
					$value['custom']    = $custom;
					$value['dropped']   = [ 'not a preset' ]; // wrong type, should be discarded.
				}
				return $value;
			}
		);

		$all = ( new CspPresetLibrary() )->all();

		$this->assertArrayHasKey( 'custom', $all );
		$this->assertSame( $custom, $all['custom'] );
		$this->assertArrayNotHasKey( 'dropped', $all );
	}
}
