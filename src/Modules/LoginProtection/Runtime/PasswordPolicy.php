<?php
/**
 * Strong-password policy: enforce complexity rules on new / changed passwords.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection\Runtime;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces a configurable strong-password policy at the three points where a
 * password is created or changed: profile updates, self-registration, and the
 * password-reset flow.
 *
 * Scope is deliberately narrow. The policy only ever inspects a password that is
 * actually being submitted — it never reads, rewrites, or invalidates a user's
 * stored password, and it never forces an existing user to reset. When no
 * password field is present in the request (the default WordPress registration
 * form, for instance, ships no password input and mails a generated one), the
 * gate simply stands aside. This keeps day-one enforcement from locking out the
 * very accounts it protects.
 *
 * Each gate reads the candidate password straight from the request. The value is
 * a raw secret: it is unslashed (WordPress magic-quotes every superglobal) but
 * deliberately never run through a sanitiser, because sanitising would silently
 * corrupt legitimate passwords that contain markup-like or whitespace
 * characters. The forms that trigger these hooks each verify their own nonce in
 * WordPress core before the hook fires, so no additional nonce check is added
 * here (the `phpcs:ignore` pragmas on the read document exactly that).
 */
final class PasswordPolicy {

	/**
	 * WP_Error code shared by every unmet-requirement message this policy adds.
	 *
	 * @var string
	 */
	private const ERROR_CODE = 'fanxie_wp_core_weak_password';

	/**
	 * Fallback config so a partial `passwords` sub-config still behaves sanely —
	 * most importantly the PRD's default minimum length of 12.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'enforce'            => false,
		'min_length'         => 12,
		'require_mixed_case' => false,
		'require_number'     => false,
		'require_symbol'     => false,
	];

	/**
	 * Resolved policy config (module defaults merged with supplied overrides).
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $config;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config The module's `passwords` sub-config:
	 *                                      `enforce`, `min_length`,
	 *                                      `require_mixed_case`, `require_number`,
	 *                                      `require_symbol`.
	 */
	public function __construct( array $config ) {
		$this->config = array_merge( self::DEFAULTS, $config );
	}

	/**
	 * Attach the enforcement hooks — nothing at all unless enforcement is on.
	 *
	 * Priorities are late-ish defaults (10): the policy stacks additively with any
	 * other validators on the same hooks rather than pre-empting them.
	 */
	public function register_hooks(): void {
		if ( empty( $this->config['enforce'] ) ) {
			return;
		}

		add_action( 'user_profile_update_errors', [ $this, 'validate_profile' ], 10, 3 );
		add_filter( 'registration_errors', [ $this, 'validate_registration' ], 10, 3 );
		add_action( 'validate_password_reset', [ $this, 'validate_reset' ], 10, 2 );
	}

	/**
	 * Validate a password submitted through the profile / user-edit screen.
	 *
	 * Fires on `user_profile_update_errors` before the user is saved, so any error
	 * added here blocks the write. The password field is only submitted with a
	 * value when the account holder is actually setting a new password; an empty
	 * field means "no change", so validation stands aside and the existing
	 * password is left untouched.
	 *
	 * @param WP_Error  $errors Accumulating profile-update errors (mutated in place).
	 * @param bool      $update Whether this is an update to an existing user (unused).
	 * @param \stdClass $user   The user object being built (unused).
	 */
	public function validate_profile( WP_Error $errors, bool $update, \stdClass $user ): void {
		unset( $update, $user );

		$password = $this->submitted_password( [ 'pass1' ] );
		if ( '' === $password ) {
			return;
		}

		$this->add_failures( $errors, $password );
	}

	/**
	 * Validate a password submitted through the self-registration flow.
	 *
	 * `registration_errors` is a filter, so the (mutated) `WP_Error` must be
	 * returned. The default WordPress registration form carries no password field
	 * — it mails a generated password — so the gate only acts when a custom
	 * registration flow (WooCommerce, membership plugins, …) actually posts one,
	 * under either the `pass1` or `user_pass` field name.
	 *
	 * @param WP_Error $errors               Accumulating registration errors.
	 * @param string   $sanitized_user_login The prospective login name (unused).
	 * @param string   $user_email           The prospective e-mail (unused).
	 * @return WP_Error The (possibly augmented) errors object.
	 */
	public function validate_registration( WP_Error $errors, string $sanitized_user_login = '', string $user_email = '' ): WP_Error {
		unset( $sanitized_user_login, $user_email );

		$password = $this->submitted_password( [ 'pass1', 'user_pass' ] );
		if ( '' === $password ) {
			return $errors;
		}

		$this->add_failures( $errors, $password );

		return $errors;
	}

	/**
	 * Validate the new password submitted through the reset-password flow.
	 *
	 * Fires on `validate_password_reset`; an error added here keeps the reset form
	 * on screen instead of persisting the new password.
	 *
	 * @param WP_Error $errors Accumulating reset errors (mutated in place).
	 * @param mixed    $user   The user resetting their password (unused).
	 */
	public function validate_reset( WP_Error $errors, mixed $user = null ): void {
		unset( $user );

		$password = $this->submitted_password( [ 'pass1' ] );
		if ( '' === $password ) {
			return;
		}

		$this->add_failures( $errors, $password );
	}

	/**
	 * Evaluate a password against the enabled rules.
	 *
	 * Pure and side-effect-free: returns the list of human-readable messages for
	 * each unmet requirement, or an empty list when the password satisfies every
	 * enabled rule. Each character rule is gated by its own config toggle, so a
	 * disabled rule contributes nothing.
	 *
	 * @param string $password The candidate password.
	 * @return list<string> Unmet-requirement messages; empty when the password is valid.
	 */
	public function check( string $password ): array {
		$errors = [];
		$min    = (int) $this->config['min_length'];

		if ( strlen( $password ) < $min ) {
			/* translators: %d: minimum length */
			$errors[] = sprintf( __( 'Password must be at least %d characters.', 'fanxie-wp-core' ), $min );
		}

		if ( ! empty( $this->config['require_mixed_case'] ) && ( ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/[A-Z]/', $password ) ) ) {
			$errors[] = __( 'Password must include both uppercase and lowercase letters.', 'fanxie-wp-core' );
		}

		if ( ! empty( $this->config['require_number'] ) && ! preg_match( '/\d/', $password ) ) {
			$errors[] = __( 'Password must include at least one number.', 'fanxie-wp-core' );
		}

		if ( ! empty( $this->config['require_symbol'] ) && ! preg_match( '/[^A-Za-z0-9]/', $password ) ) {
			$errors[] = __( 'Password must include at least one symbol.', 'fanxie-wp-core' );
		}

		return $errors;
	}

	/**
	 * Append one `WP_Error` entry per unmet requirement.
	 *
	 * @param WP_Error $errors   The errors object to augment.
	 * @param string   $password The candidate password.
	 */
	private function add_failures( WP_Error $errors, string $password ): void {
		foreach ( $this->check( $password ) as $message ) {
			$errors->add( self::ERROR_CODE, $message );
		}
	}

	/**
	 * Read the first non-empty candidate password from the request.
	 *
	 * Returns the raw submitted secret: unslashed (WordPress magic-quotes the
	 * superglobals) but never sanitised, so passwords containing quotes, angle
	 * brackets, or other markup-like characters are validated verbatim. An empty
	 * string means no password field was submitted.
	 *
	 * @param string[] $fields Ordered `$_POST` field names to probe.
	 * @return string The submitted password, or an empty string when absent.
	 */
	private function submitted_password( array $fields ): string {
		foreach ( $fields as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password secret: unslashed but deliberately NOT sanitised (sanitising would corrupt valid passwords). The profile / registration / reset forms each verify their own nonce in WordPress core before these hooks fire.
			$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			if ( is_string( $raw ) && '' !== $raw ) {
				return $raw;
			}
		}

		return '';
	}
}
