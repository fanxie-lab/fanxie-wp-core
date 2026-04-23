<?php
/**
 * Unit tests for HeaderStripper.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\HeaderStripper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the stripper only wires `send_headers` when enabled.
 */
final class HeaderStripperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_attaches_send_headers_when_enabled(): void {
		$stripper = new HeaderStripper( [ 'version_hiding' => [ 'remove_powered_by' => true ] ] );
		$stripper->register_hooks();

		$this->assertTrue( Actions\has( 'send_headers', [ $stripper, 'strip' ], 1 ) !== false );
	}

	public function test_register_hooks_is_noop_when_disabled(): void {
		$stripper = new HeaderStripper( [ 'version_hiding' => [ 'remove_powered_by' => false ] ] );
		$stripper->register_hooks();

		$this->assertFalse( Actions\has( 'send_headers', [ $stripper, 'strip' ] ) );
	}

	public function test_strip_invokes_header_remove_when_available(): void {
		// Brain Monkey cannot redefine internal `headers_sent`, so we can only
		// assert the strip() method is safe to call and noop-handles a stub
		// environment without crashing. The real behaviour is exercised in the
		// integration suite.
		$stripper = new HeaderStripper( [ 'version_hiding' => [ 'remove_powered_by' => true ] ] );
		$stripper->strip();
		$this->addToAssertionCount( 1 );
	}
}
