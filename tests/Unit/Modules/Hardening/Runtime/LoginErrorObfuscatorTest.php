<?php
/**
 * Unit tests for LoginErrorObfuscator.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\LoginErrorObfuscator;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in for WP's `WP_Error` for the purpose of inspecting error codes.
 */
final class FakeLoginErrors {
	/**
	 * Error codes carried by this bag.
	 *
	 * @var array<int, string>
	 */
	private array $codes;

	/**
	 * @param array<int, string> $codes Codes to report via `get_error_codes()`.
	 */
	public function __construct( array $codes ) {
		$this->codes = $codes;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_error_codes(): array {
		return $this->codes;
	}
}

/**
 * Covers the login_errors rewrite path.
 */
final class LoginErrorObfuscatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_replaces_message_when_target_code_present(): void {
		Filters\expectApplied( 'fanxie_wp_core/hardening/login_error_message' )->andReturnFirstArg();

		global $errors;
		$errors = new FakeLoginErrors( [ 'invalid_username' ] );

		$obfuscator = new LoginErrorObfuscator( [ 'login' => [ 'obfuscate_errors' => true ] ] );
		$this->assertSame(
			'Invalid username or password.',
			$obfuscator->filter_message( '<strong>ERROR</strong>: The username is not registered.' )
		);

		$errors = null;
	}

	public function test_passes_through_when_no_target_code(): void {
		global $errors;
		$errors = new FakeLoginErrors( [ 'expired_session' ] );

		$obfuscator = new LoginErrorObfuscator( [ 'login' => [ 'obfuscate_errors' => true ] ] );
		$this->assertSame(
			'Session expired.',
			$obfuscator->filter_message( 'Session expired.' )
		);

		$errors = null;
	}

	public function test_passes_through_when_global_errors_missing(): void {
		global $errors;
		$errors = null;

		$obfuscator = new LoginErrorObfuscator( [ 'login' => [ 'obfuscate_errors' => true ] ] );
		$this->assertSame( 'whatever', $obfuscator->filter_message( 'whatever' ) );
	}

	public function test_filter_can_customise_generic_message(): void {
		global $errors;
		$errors = new FakeLoginErrors( [ 'incorrect_password' ] );

		Filters\expectApplied( 'fanxie_wp_core/hardening/login_error_message' )
			->once()
			->andReturn( 'Nope.' );

		$obfuscator = new LoginErrorObfuscator( [ 'login' => [ 'obfuscate_errors' => true ] ] );
		$this->assertSame( 'Nope.', $obfuscator->filter_message( 'anything' ) );

		$errors = null;
	}
}
