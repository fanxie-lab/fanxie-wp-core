<?php
/**
 * Replace informative WP login errors with a generic message.
 *
 * @package FanxieLab\Warden\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the WordPress `wp-login.php` error messages that distinguish
 * between "invalid username", "incorrect password", and "invalid email"
 * with a single generic "Invalid username or password" — so attackers
 * cannot iterate through usernames to discover which accounts exist.
 */
final class LoginErrorObfuscator {

	/**
	 * Error codes WordPress sets for username/password/email mismatches.
	 *
	 * Matched as substrings of the error code string that `login_errors`
	 * sees — WordPress sometimes wraps them in `<strong>` / translation —
	 * so we inspect the global error bag directly.
	 *
	 * @var array<int, string>
	 */
	private const TARGET_CODES = [
		'invalid_username',
		'incorrect_password',
		'invalid_email',
		'invalidcombo',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach the filter if the toggle is on.
	 */
	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'login_errors', [ $this, 'filter_message' ] );
	}

	/**
	 * Replace the message when the active login error bag holds a target code.
	 *
	 * @param mixed $message Current error markup.
	 */
	public function filter_message( mixed $message ): string {
		$message = is_string( $message ) ? $message : '';

		// The `$errors` global carries the underlying error codes before
		// WordPress renders them into `$message`. Inspecting it avoids
		// brittle string matching across locales.
		global $errors;

		$codes = [];
		if ( is_object( $errors ) && method_exists( $errors, 'get_error_codes' ) ) {
			$raw = $errors->get_error_codes();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $code ) {
					if ( is_string( $code ) ) {
						$codes[] = $code;
					}
				}
			}
		}

		$has_target = false;
		foreach ( self::TARGET_CODES as $code ) {
			if ( in_array( $code, $codes, true ) ) {
				$has_target = true;
				break;
			}
		}

		if ( ! $has_target ) {
			return $message;
		}

		$generic = __( 'Invalid username or password.', 'fanxie-warden' );

		/**
		 * Filter: fanxie_warden/hardening/login_error_message
		 *
		 * Customise the generic login error message surfaced in place of the
		 * informative WordPress defaults.
		 *
		 * @since 0.2.0-dev
		 *
		 * @param string             $generic Generic error message.
		 * @param array<int, string> $codes   Underlying WP error codes.
		 */
		$filtered = apply_filters( 'fanxie_warden/hardening/login_error_message', $generic, $codes );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $generic;
	}

	/**
	 * Is `login.obfuscate_errors` on?
	 */
	private function is_enabled(): bool {
		return (bool) ( $this->config['login']['obfuscate_errors'] ?? true );
	}
}
