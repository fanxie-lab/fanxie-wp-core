<?php
/**
 * Unit tests for the Login Protection strong-password policy.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Pure `check()` rule logic (each requirement individually + config toggles) and
 * the hook-wiring gate (`register_hooks()` attaches nothing unless enforcement
 * is on). The WP_Error-touching handlers run through the real profile-update
 * hook in the integration suite.
 */
final class PasswordPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `check()` builds messages via `__()`; return the untranslated string so
		// `sprintf` can render the length message deterministically.
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A fully-enabled policy config, with selective overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function config( array $overrides = [] ): array {
		return array_merge(
			[
				'enforce'            => true,
				'min_length'         => 12,
				'require_mixed_case' => true,
				'require_number'     => true,
				'require_symbol'     => true,
			],
			$overrides
		);
	}

	public function test_strong_password_passes_all_rules(): void {
		$policy = new PasswordPolicy( $this->config() );
		$this->assertSame( [], $policy->check( 'Str0ng#Passw0rd!' ) );
	}

	public function test_too_short_password_is_rejected(): void {
		$policy   = new PasswordPolicy( $this->config( [ 'min_length' => 12 ] ) );
		$expected = sprintf( 'Password must be at least %d characters.', 12 );
		// `Ab1!x` clears every character rule; only the length rule can fire.
		$this->assertSame( [ $expected ], $policy->check( 'Ab1!x' ) );
	}

	public function test_missing_uppercase_is_rejected(): void {
		$policy = new PasswordPolicy( $this->config() );
		$this->assertContains(
			'Password must include both uppercase and lowercase letters.',
			$policy->check( 'longlower0#word' )
		);
	}

	public function test_missing_lowercase_is_rejected(): void {
		$policy = new PasswordPolicy( $this->config() );
		$this->assertContains(
			'Password must include both uppercase and lowercase letters.',
			$policy->check( 'LONGUPPER0#WORD' )
		);
	}

	public function test_missing_number_is_rejected(): void {
		$policy = new PasswordPolicy( $this->config() );
		$this->assertContains(
			'Password must include at least one number.',
			$policy->check( 'LongPassword#word' )
		);
	}

	public function test_missing_symbol_is_rejected(): void {
		$policy = new PasswordPolicy( $this->config() );
		$this->assertContains(
			'Password must include at least one symbol.',
			$policy->check( 'LongPassword0word' )
		);
	}

	public function test_disabling_the_number_rule_skips_it(): void {
		// No digit present, but `require_number` is off -> otherwise strong.
		$policy = new PasswordPolicy( $this->config( [ 'require_number' => false ] ) );
		$this->assertSame( [], $policy->check( 'LongPassword#word' ) );
	}

	public function test_disabling_all_character_rules_leaves_only_length(): void {
		$policy = new PasswordPolicy(
			$this->config(
				[
					'require_mixed_case' => false,
					'require_number'     => false,
					'require_symbol'     => false,
				]
			)
		);
		$this->assertSame( [], $policy->check( 'alllowercaselongenough' ) );
		$this->assertNotSame( [], $policy->check( 'short' ) );
	}

	public function test_length_message_includes_the_configured_minimum(): void {
		$policy = new PasswordPolicy( $this->config( [ 'min_length' => 16 ] ) );
		$errors = $policy->check( 'Ab1!x' );

		$this->assertContains( sprintf( 'Password must be at least %d characters.', 16 ), $errors );
		$this->assertStringContainsString( '16', implode( ' ', $errors ) );
	}

	public function test_register_hooks_attaches_nothing_when_enforcement_is_off(): void {
		$added = $this->capture_hook_registrations();

		$policy = new PasswordPolicy( $this->config( [ 'enforce' => false ] ) );
		$policy->register_hooks();

		$this->assertSame( [], $added->getArrayCopy(), 'No hooks may be attached while enforcement is off.' );
	}

	public function test_register_hooks_wires_all_three_gates_when_enforced(): void {
		$added = $this->capture_hook_registrations();

		$policy = new PasswordPolicy( $this->config( [ 'enforce' => true ] ) );
		$policy->register_hooks();

		$this->assertSame(
			[
				[ 'action', 'user_profile_update_errors', 'validate_profile', 10, 3 ],
				[ 'filter', 'registration_errors', 'validate_registration', 10, 3 ],
				[ 'action', 'validate_password_reset', 'validate_reset', 10, 2 ],
			],
			$added->getArrayCopy(),
			'Enforcement wires exactly the three gates, each with the specified priority and arg count.'
		);
	}

	/**
	 * Capture every `add_action` / `add_filter` call as a flat, assertable tuple:
	 * `[ kind, hook, handler-method, priority, accepted_args ]`.
	 *
	 * Returns an `ArrayObject` (a handle, not a value copy) so registrations the
	 * stubbed hook functions append are visible to the caller after
	 * `register_hooks()` runs.
	 *
	 * @return \ArrayObject<int, array{0: string, 1: string, 2: string, 3: int, 4: int}> Captured registrations, in call order.
	 */
	private function capture_hook_registrations(): \ArrayObject {
		$added = new \ArrayObject();

		$recorder = static function ( string $kind ) use ( $added ): callable {
			return static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( $kind, $added ): bool {
				$method    = is_array( $callback ) ? (string) $callback[1] : (string) $callback;
				$added[]   = [ $kind, (string) $hook, $method, (int) $priority, (int) $accepted_args ];
				return true;
			};
		};

		Functions\when( 'add_action' )->alias( $recorder( 'action' ) );
		Functions\when( 'add_filter' )->alias( $recorder( 'filter' ) );

		return $added;
	}
}
