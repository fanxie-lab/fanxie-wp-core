<?php
/**
 * WP-CLI recovery commands for the Login Protection module.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection\Cli
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection\Cli;

use FanxieLab\WPCore\Modules\LoginProtection\BanStore;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\LoginSlugGuard;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp fx-core login <subcommand>` — break-glass recovery from the shell.
 *
 * Two operator lifelines that never depend on being able to log in:
 *
 *   - `reveal` prints the effective hidden-login slug and where it comes from,
 *     so an administrator who forgot it (or set the `FX_CORE_LOGIN_SLUG` escape
 *     hatch in `wp-config.php`) can find their way back to the login screen.
 *   - `unlock <subject>` clears the transient lockouts and persistent bans for
 *     an IP address or username, freeing a locked-out account without touching
 *     the database by hand.
 *
 * Registration is deferred to {@see \FanxieLab\WPCore\Modules\LoginProtection\LoginProtection::register_hooks()}
 * (guarded by `defined( 'WP_CLI' ) && WP_CLI`), which constructs this class with
 * the live module config and ban repository — this class only defines behaviour.
 */
final class LoginCommand {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config The full Login Protection module config
	 *                                      (only the `hide_login` branch is read).
	 * @param BanStore             $bans   Persistent ban store used by `unlock`.
	 */
	public function __construct(
		private readonly array $config,
		private readonly BanStore $bans
	) {}

	/**
	 * Reveal the effective hidden-login slug and its source.
	 *
	 * Prints the slug in force, whether it comes from the `FX_CORE_LOGIN_SLUG`
	 * wp-config constant or the stored plugin settings, and the full login URL.
	 * When no slug resolves, reports that the default `wp-login.php` is in use.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show the hidden login slug and URL.
	 *     $ wp fx-core login reveal
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 */
	public function reveal( array $args = [], array $assoc_args = [] ): void {
		unset( $args, $assoc_args );

		$hide_login = isset( $this->config['hide_login'] ) && is_array( $this->config['hide_login'] )
			? $this->config['hide_login']
			: [];

		$guard = new LoginSlugGuard( $hide_login );
		$slug  = $guard->effective_slug();

		if ( '' === $slug ) {
			WP_CLI::log( __( 'Hide-login is not active: no custom login slug is configured.', 'fanxie-wp-core' ) );
			WP_CLI::log(
				sprintf(
					/* translators: %s: default login URL. */
					__( 'The default login URL is in use: %s', 'fanxie-wp-core' ),
					wp_login_url()
				)
			);
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: the effective login slug. */
				__( 'Effective login slug: %s', 'fanxie-wp-core' ),
				$slug
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %s: where the slug comes from. */
				__( 'Source: %s', 'fanxie-wp-core' ),
				$this->slug_source_label()
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %s: the full hidden-login URL. */
				__( 'Login URL: %s', 'fanxie-wp-core' ),
				$this->login_url( $slug )
			)
		);

		if ( ! $guard->is_active() ) {
			WP_CLI::warning(
				__(
					'Hide-login is disabled (the "Hide wp-login.php" toggle is off), so this slug is not being enforced.',
					'fanxie-wp-core'
				)
			);
		}

		WP_CLI::success( __( 'Login slug revealed.', 'fanxie-wp-core' ) );
	}

	/**
	 * Clear lockouts and bans for an IP address or username.
	 *
	 * Clears the transient lockout for the subject in both dimensions (IP and
	 * username — clearing the one that does not apply is a harmless no-op) and
	 * removes any persistent IP and username bans.
	 *
	 * ## OPTIONS
	 *
	 * <subject>
	 * : The IP address or username to unlock.
	 *
	 * [--dry-run]
	 * : Report what is currently locked/banned and what would be cleared, without
	 * changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Free a locked-out IP address.
	 *     $ wp fx-core login unlock 203.0.113.7
	 *
	 *     # Preview what unlocking a username would clear.
	 *     $ wp fx-core login unlock admin --dry-run
	 *
	 * @param array<int, string>    $args       Positional arguments: `[ 0 => subject ]`.
	 * @param array<string, string> $assoc_args Associative arguments: supports `dry-run`.
	 */
	public function unlock( array $args, array $assoc_args = [] ): void {
		$subject = isset( $args[0] ) ? trim( (string) $args[0] ) : '';

		if ( '' === $subject ) {
			WP_CLI::error( __( 'A subject (IP address or username) is required.', 'fanxie-wp-core' ) );
			return;
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$this->report_dry_run( $subject );
			return;
		}

		// Clear the transient lockout in both dimensions. `clear_subject()` derives
		// its keys from the subject alone, so clearing the dimension that does not
		// apply (e.g. the username dimension for an IP subject) simply deletes a
		// transient that was never set.
		AttemptLimiter::clear_subject( 'ip', $subject );
		AttemptLimiter::clear_subject( 'user', $subject );

		$removed = $this->bans->remove( 'ip', $subject ) + $this->bans->remove( 'username', $subject );

		WP_CLI::log(
			sprintf(
				/* translators: %s: the subject (IP address or username). */
				__( 'Cleared lockout transients for "%s" (IP and username dimensions).', 'fanxie-wp-core' ),
				$subject
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %d: number of ban rows removed. */
				_n( 'Removed %d persistent ban.', 'Removed %d persistent bans.', $removed, 'fanxie-wp-core' ),
				$removed
			)
		);

		WP_CLI::success(
			sprintf(
				/* translators: %s: the subject (IP address or username). */
				__( 'Unlocked "%s".', 'fanxie-wp-core' ),
				$subject
			)
		);
	}

	/**
	 * Print the dry-run plan for `unlock` without changing any state.
	 *
	 * @param string $subject The IP address or username being inspected.
	 */
	private function report_dry_run( string $subject ): void {
		WP_CLI::log(
			sprintf(
				/* translators: %s: the subject (IP address or username). */
				__( 'Dry run for "%s" — nothing will be changed.', 'fanxie-wp-core' ),
				$subject
			)
		);

		$ip_locked   = AttemptLimiter::is_locked( 'ip', $subject );
		$user_locked = AttemptLimiter::is_locked( 'user', $subject );
		$ip_banned   = $this->bans->is_banned( 'ip', $subject );
		$user_banned = $this->bans->is_banned( 'username', $subject );

		WP_CLI::log( $this->dry_run_line( __( 'IP lockout transient', 'fanxie-wp-core' ), $ip_locked ) );
		WP_CLI::log( $this->dry_run_line( __( 'Username lockout transient', 'fanxie-wp-core' ), $user_locked ) );
		WP_CLI::log( $this->dry_run_line( __( 'IP ban', 'fanxie-wp-core' ), $ip_banned ) );
		WP_CLI::log( $this->dry_run_line( __( 'Username ban', 'fanxie-wp-core' ), $user_banned ) );

		if ( $ip_locked || $user_locked || $ip_banned || $user_banned ) {
			WP_CLI::log( __( 'Run without --dry-run to clear the items marked present.', 'fanxie-wp-core' ) );
		} else {
			WP_CLI::log( __( 'Nothing is currently locked or banned for this subject.', 'fanxie-wp-core' ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: the subject (IP address or username). */
				__( 'Dry run complete for "%s".', 'fanxie-wp-core' ),
				$subject
			)
		);
	}

	/**
	 * Format one dry-run status line, e.g. `IP ban: present (would be cleared)`.
	 *
	 * @param string $label   Human label for the item.
	 * @param bool   $present Whether the item currently exists.
	 */
	private function dry_run_line( string $label, bool $present ): string {
		$status = $present
			? __( 'present (would be cleared)', 'fanxie-wp-core' )
			: __( 'none', 'fanxie-wp-core' );

		// The `label: status` join is presentational, not linguistic — no `__()`.
		return $label . ': ' . $status;
	}

	/**
	 * Human-readable label for where the effective slug comes from.
	 *
	 * Mirrors the constant-wins heuristic of
	 * {@see \FanxieLab\WPCore\Modules\LoginProtection\AjaxController} so the CLI
	 * and the admin UI agree on the reported source.
	 */
	private function slug_source_label(): string {
		if ( defined( 'FX_CORE_LOGIN_SLUG' ) && '' !== (string) constant( 'FX_CORE_LOGIN_SLUG' ) ) {
			return __( 'FX_CORE_LOGIN_SLUG constant (wp-config.php)', 'fanxie-wp-core' );
		}

		return __( 'stored plugin settings', 'fanxie-wp-core' );
	}

	/**
	 * Build the public URL of the login slug for the current permalink mode.
	 *
	 * Mirrors {@see LoginSlugGuard}'s own URL construction: pretty permalinks
	 * serve the slug as a path segment, plain permalinks as a `?slug` query key.
	 *
	 * @param string $slug The effective login slug (already non-empty).
	 */
	private function login_url( string $slug ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( $slug ) );
		}

		return add_query_arg( $slug, '', home_url( '/' ) );
	}
}
