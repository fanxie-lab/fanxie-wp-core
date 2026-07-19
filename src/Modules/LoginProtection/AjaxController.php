<?php
/**
 * Admin AJAX sub-actions for the Login Protection module.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\LoginSlugGuard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX surface consumed by the Login Protection Vue admin UI.
 *
 * Nonce + the `manage_fanxie_wp_core` capability are enforced centrally by the
 * {@see AjaxRouter} dispatcher; each handler here validates its own payload,
 * delegates to the module / repositories / runtime helpers, and returns either
 * an array (success envelope) or a {@see WP_Error} (surfaced to the client as a
 * field-aware error).
 *
 * The sub-action names registered below are a stable contract with the Vue
 * store — do not rename them without updating the store in lockstep.
 */
final class AjaxController {

	/**
	 * Cron hook fired daily to prune expired bans + stale log rows.
	 *
	 * The handler that runs on this hook is wired by the module's
	 * `register_hooks()`; this controller only guarantees the event is scheduled.
	 *
	 * @var string
	 */
	public const PRUNE_HOOK = 'fanxie_wp_core_login_protection_prune';

	/**
	 * Subject dimensions a ban / lockout action may target.
	 *
	 * @var array<int, string>
	 */
	private const SUBJECT_TYPES = [ 'ip', 'username' ];

	/**
	 * Constructor.
	 *
	 * @param LoginProtection    $module Module instance (source of truth for config).
	 * @param LoginLogRepository $log    Login-event history store.
	 * @param BanRepository      $bans   Persistent ban store.
	 */
	public function __construct(
		private readonly LoginProtection $module,
		private readonly LoginLogRepository $log,
		private readonly BanRepository $bans,
	) {}

	/**
	 * Attach every sub-action to the shared router and ensure the daily prune
	 * event is scheduled.
	 *
	 * @param AjaxRouter $router Shared AJAX router.
	 */
	public function register( AjaxRouter $router ): void {
		$router->register( 'login_protection/get-config', [ $this, 'handle_get_config' ] );
		$router->register( 'login_protection/save-config', [ $this, 'handle_save_config' ] );
		$router->register( 'login_protection/get-log', [ $this, 'handle_get_log' ] );
		$router->register( 'login_protection/add-ban', [ $this, 'handle_add_ban' ] );
		$router->register( 'login_protection/remove-ban', [ $this, 'handle_remove_ban' ] );
		$router->register( 'login_protection/clear-lockout', [ $this, 'handle_clear_lockout' ] );

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Handler: `login_protection/get-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_config( array $payload = [] ): array {
		unset( $payload );
		return $this->config_envelope();
	}

	/**
	 * Handler: `login_protection/save-config`.
	 *
	 * Validates the hide-login slug (a slug that sanitises to empty while
	 * hide-login is enabled is rejected before anything persists), stores the
	 * config, and — when the resulting effective login slug changes — e-mails the
	 * site admin the new address as a lock-out safety net.
	 *
	 * @param array<string, mixed> $payload Expects `{ config: array }`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_save_config( array $payload ): array|WP_Error {
		if ( ! isset( $payload['config'] ) || ! is_array( $payload['config'] ) ) {
			return new WP_Error( 'invalid_config', __( 'Configuration payload must be an object.', 'fanxie-wp-core' ) );
		}

		$incoming   = $payload['config'];
		$hide_login = isset( $incoming['hide_login'] ) && is_array( $incoming['hide_login'] ) ? $incoming['hide_login'] : [];

		if ( ! empty( $hide_login['enabled'] ) ) {
			$raw_slug = isset( $hide_login['slug'] ) && is_scalar( $hide_login['slug'] ) ? (string) $hide_login['slug'] : '';
			if ( '' === $this->module->sanitize_slug( $raw_slug ) ) {
				return new WP_Error(
					'invalid_slug',
					__( 'Choose a custom login slug that is not a reserved WordPress path.', 'fanxie-wp-core' ),
					[ 'field' => 'hide_login.slug' ]
				);
			}
		}

		// Capture the effective slug BEFORE persisting so we can detect a change.
		$old_slug = $this->effective_slug( $this->module->get_config() );

		$this->module->update_config( $incoming );

		$new_config = $this->module->get_config();
		$new_slug   = $this->effective_slug( $new_config );

		if ( $new_slug !== $old_slug ) {
			$this->notify_slug_change( $new_slug );
		}

		return $this->config_envelope( $new_config );
	}

	/**
	 * Handler: `login_protection/get-log`.
	 *
	 * @param array<string, mixed> $payload Filters (`event_type`, `ip`, `username`,
	 *                                       `since`, `until`) + `page` / `per_page`.
	 * @return array<string, mixed>
	 */
	public function handle_get_log( array $payload ): array {
		$filters = [];
		foreach ( [ 'event_type', 'ip', 'username', 'since', 'until' ] as $key ) {
			$raw = $payload[ $key ] ?? '';
			if ( is_scalar( $raw ) && '' !== (string) $raw ) {
				$filters[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		$page     = isset( $payload['page'] ) ? max( 1, absint( $payload['page'] ) ) : 1;
		$per_page = isset( $payload['per_page'] ) ? absint( $payload['per_page'] ) : 25;
		$per_page = 0 === $per_page ? 25 : min( 200, $per_page );

		$result = $this->log->query( $filters, $page, $per_page );

		return [
			'rows'     => $result['rows'],
			'total'    => $result['total'],
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Handler: `login_protection/add-ban`.
	 *
	 * @param array<string, mixed> $payload Expects `subject_type`, `subject_value`,
	 *                                       optional `reason` + `ttl_minutes`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_add_ban( array $payload ): array|WP_Error {
		$type = $this->read_subject_type( $payload );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$value = $this->read_subject_value( $payload );
		if ( $value instanceof WP_Error ) {
			return $value;
		}

		if ( 'ip' === $type && false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return new WP_Error(
				'invalid_ip',
				__( 'Enter a valid IP address to ban.', 'fanxie-wp-core' ),
				[ 'field' => 'subject_value' ]
			);
		}

		$raw_reason  = $payload['reason'] ?? '';
		$reason      = is_scalar( $raw_reason ) ? sanitize_text_field( (string) $raw_reason ) : '';
		$reason      = '' === $reason ? null : $reason;
		$ttl_minutes = isset( $payload['ttl_minutes'] ) ? absint( $payload['ttl_minutes'] ) : 0;
		$ttl_minutes = $ttl_minutes > 0 ? $ttl_minutes : null;

		$this->bans->add( $type, $value, $reason, $ttl_minutes );
		$this->log->record(
			'ban_added',
			'ip' === $type ? $value : '',
			'username' === $type ? $value : '',
			null,
			[ 'reason' => $reason ]
		);

		return $this->bans->query();
	}

	/**
	 * Handler: `login_protection/remove-ban`.
	 *
	 * @param array<string, mixed> $payload Expects `subject_type`, `subject_value`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_remove_ban( array $payload ): array|WP_Error {
		$type = $this->read_subject_type( $payload );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$value = $this->read_subject_value( $payload );
		if ( $value instanceof WP_Error ) {
			return $value;
		}

		$this->bans->remove( $type, $value );
		$this->log->record(
			'ban_removed',
			'ip' === $type ? $value : '',
			'username' === $type ? $value : '',
			null,
			[]
		);

		return $this->bans->query();
	}

	/**
	 * Handler: `login_protection/clear-lockout`.
	 *
	 * Releases the live transient lockout for a subject by delegating to
	 * {@see AttemptLimiter::clear_subject()} — the single owner of the transient
	 * key format — rather than duplicating it here.
	 *
	 * @param array<string, mixed> $payload Expects `subject_type`, `subject_value`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_clear_lockout( array $payload ): array|WP_Error {
		$type = $this->read_subject_type( $payload );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$value = $this->read_subject_value( $payload );
		if ( $value instanceof WP_Error ) {
			return $value;
		}

		AttemptLimiter::clear_subject( $type, $value );

		return [ 'cleared' => true ];
	}

	/**
	 * Validate + normalise the `subject_type` payload field.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return string|WP_Error `ip` / `username` on success.
	 */
	private function read_subject_type( array $payload ): string|WP_Error {
		$raw  = $payload['subject_type'] ?? '';
		$type = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
		if ( ! in_array( $type, self::SUBJECT_TYPES, true ) ) {
			return new WP_Error(
				'invalid_subject_type',
				__( 'The ban subject must be an IP address or a username.', 'fanxie-wp-core' ),
				[ 'field' => 'subject_type' ]
			);
		}
		return $type;
	}

	/**
	 * Validate + sanitise the `subject_value` payload field.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return string|WP_Error Non-empty sanitised value on success.
	 */
	private function read_subject_value( array $payload ): string|WP_Error {
		$raw   = $payload['subject_value'] ?? '';
		$value = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		if ( '' === $value ) {
			return new WP_Error(
				'invalid_subject_value',
				__( 'A subject value is required.', 'fanxie-wp-core' ),
				[ 'field' => 'subject_value' ]
			);
		}
		return $value;
	}

	/**
	 * Build the shared config envelope returned by get-config / save-config.
	 *
	 * @param array<string, mixed>|null $config Pre-resolved config, or null to read fresh.
	 * @return array<string, mixed>
	 */
	private function config_envelope( ?array $config = null ): array {
		$config = null === $config ? $this->module->get_config() : $config;

		return [
			'config'         => $config,
			'slug_source'    => $this->slug_source(),
			'effective_slug' => $this->effective_slug( $config ),
		];
	}

	/**
	 * Where the active login slug comes from: the `FX_CORE_LOGIN_SLUG` wp-config
	 * constant (when defined and non-empty) or the stored setting.
	 */
	private function slug_source(): string {
		if ( defined( 'FX_CORE_LOGIN_SLUG' ) && '' !== (string) constant( 'FX_CORE_LOGIN_SLUG' ) ) {
			return 'constant';
		}
		return 'stored';
	}

	/**
	 * Resolve the effective login slug for a config snapshot (constant wins).
	 *
	 * @param array<string, mixed> $config Full module config.
	 */
	private function effective_slug( array $config ): string {
		$hide_login = isset( $config['hide_login'] ) && is_array( $config['hide_login'] ) ? $config['hide_login'] : [];

		return ( new LoginSlugGuard( $hide_login ) )->effective_slug();
	}

	/**
	 * E-mail the site admin the new login address after an effective-slug change.
	 *
	 * A best-effort safety net so an administrator who changes (or clears) the
	 * hidden login slug is not locked out. Failures are swallowed — the config
	 * has already been saved and the response must still succeed.
	 *
	 * @param string $new_slug The new effective slug (empty when hide-login is off).
	 */
	private function notify_slug_change( string $new_slug ): void {
		$admin_email = get_option( 'admin_email' );
		if ( ! is_string( $admin_email ) || '' === $admin_email ) {
			return;
		}

		$subject = __( 'Your WordPress login URL has changed', 'fanxie-wp-core' );

		if ( '' === $new_slug ) {
			$message = __( 'The custom login URL for your site has been removed. You can sign in at the default WordPress login screen.', 'fanxie-wp-core' );
		} else {
			$message = sprintf(
				/* translators: %s: the new secret login URL. */
				__( 'The custom login URL for your site has changed. Bookmark this address to sign in: %s', 'fanxie-wp-core' ),
				home_url( '/' . $new_slug )
			);
		}

		wp_mail( $admin_email, $subject, $message );
	}
}
