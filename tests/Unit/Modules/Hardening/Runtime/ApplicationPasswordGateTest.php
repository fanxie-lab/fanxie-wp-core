<?php
/**
 * Unit tests for ApplicationPasswordGate.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\Hardening\Runtime\ApplicationPasswordGate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies wp_is_application_passwords_available is disabled only on opt-in.
 */
final class ApplicationPasswordGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '__return_false' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_filters_availability_when_enabled(): void {
		$gate = new ApplicationPasswordGate( [ 'application_passwords' => [ 'disable' => true ] ] );
		$gate->register_hooks();

		$this->assertNotFalse( Filters\has( 'wp_is_application_passwords_available' ) );
	}

	public function test_register_hooks_noop_when_disabled(): void {
		$gate = new ApplicationPasswordGate( [ 'application_passwords' => [ 'disable' => false ] ] );
		$gate->register_hooks();

		$this->assertFalse( Filters\has( 'wp_is_application_passwords_available' ) );
	}
}
