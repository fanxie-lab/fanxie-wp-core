<?php
/**
 * Integration tests for the Login Protection strong-password policy.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\Runtime\PasswordPolicy;
use WP_Error;
use WP_UnitTestCase;

/**
 * Round-trip through the real `user_profile_update_errors` action and
 * `registration_errors` filter: a weak password accumulates errors (blocking
 * the write), a strong one passes cleanly, and an update that submits no
 * password field is never blocked — the "new/changed passwords only, never
 * force a reset" contract.
 */
final class PasswordPolicyIntegrationTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['pass1'], $_POST['user_pass'] );
		parent::tearDown();
	}

	/**
	 * Register a fully-enabled policy against the live WordPress hook system.
	 * `WP_UnitTestCase` restores the hook globals after each test, so the wiring
	 * is torn down automatically.
	 */
	private function enforce(): void {
		( new PasswordPolicy(
			[
				'enforce'            => true,
				'min_length'         => 12,
				'require_mixed_case' => true,
				'require_number'     => true,
				'require_symbol'     => true,
			]
		) )->register_hooks();
	}

	/**
	 * Fire the profile-update validation action with the given errors bag.
	 *
	 * @param WP_Error $errors Errors object to run through the hook.
	 */
	private function run_profile_update( WP_Error $errors ): void {
		$update = true;
		$user   = new \stdClass();
		do_action_ref_array( 'user_profile_update_errors', [ &$errors, $update, &$user ] );
	}

	public function test_weak_password_on_profile_update_produces_errors(): void {
		$this->enforce();
		$_POST['pass1'] = 'weak';

		$errors = new WP_Error();
		$this->run_profile_update( $errors );

		$this->assertNotEmpty( $errors->get_error_messages() );
		$this->assertContains( 'fanxie_wp_core_weak_password', $errors->get_error_codes() );
	}

	public function test_strong_password_on_profile_update_passes(): void {
		$this->enforce();
		$_POST['pass1'] = 'Str0ng#Passw0rd!';

		$errors = new WP_Error();
		$this->run_profile_update( $errors );

		$this->assertEmpty( $errors->get_error_messages() );
	}

	public function test_absent_password_field_never_forces_a_reset(): void {
		$this->enforce();
		// A profile save that changes something other than the password submits no
		// `pass1`; the gate must stand aside so the stored password is untouched.
		unset( $_POST['pass1'] );

		$errors = new WP_Error();
		$this->run_profile_update( $errors );

		$this->assertEmpty( $errors->get_error_messages() );
	}

	public function test_weak_password_on_reset_flow_produces_errors(): void {
		$this->enforce();
		$_POST['pass1'] = 'weak';

		$errors = new WP_Error();
		$user   = self::factory()->user->create_and_get();
		do_action( 'validate_password_reset', $errors, $user );

		$this->assertNotEmpty( $errors->get_error_messages() );
		$this->assertContains( 'fanxie_wp_core_weak_password', $errors->get_error_codes() );
	}

	public function test_registration_filter_validates_custom_password_field(): void {
		$this->enforce();
		// Custom registration flows post the password under `user_pass`.
		$_POST['user_pass'] = 'weak';

		$errors = apply_filters( 'registration_errors', new WP_Error(), 'someuser', 'user@example.com' );

		$this->assertInstanceOf( WP_Error::class, $errors );
		$this->assertNotEmpty( $errors->get_error_messages() );
	}
}
