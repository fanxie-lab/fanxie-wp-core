<?php
/**
 * Boot-wiring integration test for the Login Protection module.
 *
 * @package FanxieLab\Warden\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\LoginProtection;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\LoginProtection\AjaxController;
use FanxieLab\Warden\Modules\LoginProtection\LoginProtection;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\AttemptLimiter;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\LoginSlugGuard;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\PasswordPolicy;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\SessionTimeout;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Verifies {@see LoginProtection::register_hooks()} assembles every runtime
 * collaborator correctly when the module boots with its shipped default config
 * (attempt limiting ON; hide-login, strong-passwords, and session-timeout OFF).
 *
 * The plugin already booted the *real* module during the test-suite bootstrap,
 * so `authenticate` (and the prune action) may already carry one callback from
 * that boot. Every assertion is therefore expressed as a *delta* around an
 * explicit `register_hooks()` call on a freshly-built module + router, which
 * isolates what this wiring adds from whatever the real boot already did. The
 * fresh router is inspected directly, so its handler map reflects only this
 * module's registrations.
 *
 * `register_hooks()` never touches the Login Protection custom tables (it only
 * wires hooks, registers AJAX handlers, and schedules cron), so this case
 * extends `WP_UnitTestCase` directly rather than the table test-case base.
 */
final class LoginProtectionBootTest extends WP_UnitTestCase {

	/**
	 * Stored-settings option key — deleted per test so config resolves to defaults.
	 */
	private const SETTINGS_OPTION = 'fanxie_warden_login-protection_settings';

	private AjaxRouter $router;

	private LoginProtection $module;

	protected function setUp(): void {
		parent::setUp();

		// Guarantee the module boots against pristine defaults, order-independent.
		delete_option( self::SETTINGS_OPTION );

		$this->router = new AjaxRouter();
		$this->module = new LoginProtection( $this->router );
	}

	protected function tearDown(): void {
		delete_option( self::SETTINGS_OPTION );
		parent::tearDown();
	}

	public function test_attempt_limiter_authenticate_gate_is_attached_on_defaults(): void {
		$before = $this->count_method_callbacks( 'authenticate', AttemptLimiter::class, 'gate' );

		$this->module->register_hooks();

		$after = $this->count_method_callbacks( 'authenticate', AttemptLimiter::class, 'gate' );

		$this->assertSame(
			$before + 1,
			$after,
			'Attempt limiting is ON by default, so register_hooks() must attach the AttemptLimiter gate to the authenticate filter.'
		);
	}

	public function test_ajax_sub_actions_are_registered_on_the_router(): void {
		$this->module->register_hooks();

		$prop = new ReflectionProperty( AjaxRouter::class, 'handlers' );
		$prop->setAccessible( true );
		$handlers = $prop->getValue( $this->router );

		$this->assertIsArray( $handlers );

		$expected = array(
			'login_protection/get-config',
			'login_protection/save-config',
			'login_protection/get-log',
			'login_protection/add-ban',
			'login_protection/remove-ban',
			'login_protection/clear-lockout',
		);

		foreach ( $expected as $sub_action ) {
			// The router normalises keys through sanitize_key() on registration,
			// so assert against the same normalised form the contract stores under.
			$this->assertArrayHasKey(
				sanitize_key( $sub_action ),
				$handlers,
				"AJAX sub-action '{$sub_action}' should be registered on the shared router."
			);
		}
	}

	public function test_prune_cron_handler_is_wired_on_defaults(): void {
		$before = $this->count_hook_callbacks( AjaxController::PRUNE_HOOK );

		$this->module->register_hooks();

		$after = $this->count_hook_callbacks( AjaxController::PRUNE_HOOK );

		$this->assertSame(
			$before + 1,
			$after,
			'register_hooks() must attach exactly one prune handler to the daily cron event.'
		);
	}

	public function test_hide_login_hooks_are_not_attached_on_defaults(): void {
		$before = $this->count_method_callbacks( 'wp_loaded', LoginSlugGuard::class, 'dispatch' );

		$this->module->register_hooks();

		$after = $this->count_method_callbacks( 'wp_loaded', LoginSlugGuard::class, 'dispatch' );

		$this->assertSame( $before, $after, 'Hide-login is OFF by default, so the slug guard must not attach.' );
	}

	public function test_password_policy_hooks_are_not_attached_on_defaults(): void {
		$before = $this->count_method_callbacks( 'user_profile_update_errors', PasswordPolicy::class, 'validate_profile' );

		$this->module->register_hooks();

		$after = $this->count_method_callbacks( 'user_profile_update_errors', PasswordPolicy::class, 'validate_profile' );

		$this->assertSame( $before, $after, 'Strong-password enforcement is OFF by default, so the policy must not attach.' );
	}

	public function test_session_timeout_hooks_are_not_attached_on_defaults(): void {
		$before = $this->count_method_callbacks( 'auth_cookie_expiration', SessionTimeout::class, 'cookie_lifetime' );

		$this->module->register_hooks();

		$after = $this->count_method_callbacks( 'auth_cookie_expiration', SessionTimeout::class, 'cookie_lifetime' );

		$this->assertSame( $before, $after, 'Session timeout is OFF by default, so the timeout filter must not attach.' );
	}

	/**
	 * Count callbacks on a hook whose handler is a `[ instanceof $class_name, $method ]`
	 * array — used to attribute an attachment to a specific collaborator.
	 *
	 * @param string $hook       Hook name to inspect.
	 * @param string $class_name Fully-qualified class the callback object must be.
	 * @param string $method     Method name the callback must target.
	 */
	private function count_method_callbacks( string $hook, string $class_name, string $method ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $bucket ) {
			foreach ( $bucket as $registered ) {
				$fn = $registered['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && is_object( $fn[0] ) && $fn[0] instanceof $class_name && $method === $fn[1] ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Count every callback registered on a hook, regardless of callable shape —
	 * used for the prune handler, which is wired as a closure.
	 *
	 * @param string $hook Hook name to inspect.
	 */
	private function count_hook_callbacks( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $bucket ) {
			$count += count( $bucket );
		}

		return $count;
	}
}
