<?php
/**
 * Login Protection module entry point.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\LoginProtection\Cli\LoginCommand;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\LoginSlugGuard;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\PasswordPolicy;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\SessionTimeout;
use FanxieLab\WPCore\Modules\ModuleBase;

defined( 'ABSPATH' ) || exit;

/**
 * Module #3 — Login Protection (PRD §5).
 *
 * Fine-grained toggles: attempt limiting (default on), hide-login, strong
 * passwords, session timeout (all default off). Runtime concerns live in
 * focused `Runtime/*` classes; this class is the wiring + config layer.
 */
final class LoginProtection extends ModuleBase {

	public const MODULE_ID = 'login-protection';

	/**
	 * Constructor.
	 *
	 * @param AjaxRouter $ajax_router Shared AJAX router.
	 */
	public function __construct( private readonly AjaxRouter $ajax_router ) {}

	/**
	 * Unique module slug.
	 */
	public function id(): string {
		return self::MODULE_ID;
	}

	/**
	 * Translatable display name.
	 */
	public function name(): string {
		return __( 'Login Protection', 'fanxie-wp-core' );
	}

	/**
	 * Default configuration — nested, one leaf per toggle.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return array(
			'attempts'   => array(
				'enabled'            => true,
				'lock_by_username'   => false,
				'trust_proxy'        => false,
				'proxy_header'       => 'HTTP_X_FORWARDED_FOR',
				'allowlist'          => array(),
				'tiers'              => array(
					array(
						'threshold'       => 5,
						'lockout_minutes' => 15,
					),
					array(
						'threshold'       => 10,
						'lockout_minutes' => 60,
					),
					array(
						'threshold'       => 20,
						'lockout_minutes' => 1440,
					),
				),
				'log_retention_days' => 30,
			),
			'hide_login' => array(
				'enabled' => false,
				'slug'    => '',
			),
			'passwords'  => array(
				'enforce'            => false,
				'min_length'         => 12,
				'require_mixed_case' => true,
				'require_number'     => true,
				'require_symbol'     => true,
			),
			'sessions'   => array(
				'enabled'  => false,
				'timeouts' => array(
					'administrator' => 30,
					'default'       => 120,
				),
			),
		);
	}

	/**
	 * Settings schema consumed by the admin UI and ModuleBase sanitiser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'        => 'attempts.enabled',
				'label'     => __( 'Limit login attempts', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			),
			array(
				'id'        => 'attempts.lock_by_username',
				'label'     => __( 'Also lock by username', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Off by default: automatic lockouts apply to the attacker IP only. Enabling this also locks the targeted username after repeated failures — which lets anyone who knows a username (for example "admin") lock that account out from rotating IP addresses, a targeted account-lockout denial of service. Manual username bans are always enforced regardless of this setting.', 'fanxie-wp-core' ),
			),
			array(
				'id'        => 'attempts.trust_proxy',
				'label'     => __( 'Trust reverse-proxy header for client IP', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			),
			array(
				'id'        => 'attempts.proxy_header',
				'label'     => __( 'Proxy IP header', 'fanxie-wp-core' ),
				'type'      => 'text',
				'default'   => 'HTTP_X_FORWARDED_FOR',
				'sanitizer' => 'text',
			),
			array(
				'id'                 => 'attempts.allowlist',
				'label'              => __( 'Trusted IP allowlist', 'fanxie-wp-core' ),
				'type'               => 'textarea',
				'default'            => array(),
				'sanitizer_callback' => array( $this, 'sanitize_ip_list' ),
			),
			array(
				'id'                 => 'attempts.tiers',
				'label'              => __( 'Lockout tiers', 'fanxie-wp-core' ),
				'type'               => 'textarea',
				'default'            => array(),
				'sanitizer_callback' => array( $this, 'sanitize_tiers' ),
			),
			array(
				'id'        => 'attempts.log_retention_days',
				'label'     => __( 'Log retention (days)', 'fanxie-wp-core' ),
				'type'      => 'number',
				'default'   => 30,
				'sanitizer' => 'absint',
			),

			array(
				'id'        => 'hide_login.enabled',
				'label'     => __( 'Hide wp-login.php', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			),
			array(
				'id'                 => 'hide_login.slug',
				'label'              => __( 'Custom login slug', 'fanxie-wp-core' ),
				'type'               => 'text',
				'default'            => '',
				'sanitizer_callback' => array( $this, 'sanitize_slug' ),
			),

			array(
				'id'        => 'passwords.enforce',
				'label'     => __( 'Force strong passwords', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			),
			array(
				'id'        => 'passwords.min_length',
				'label'     => __( 'Minimum length', 'fanxie-wp-core' ),
				'type'      => 'number',
				'default'   => 12,
				'sanitizer' => 'absint',
			),
			array(
				'id'        => 'passwords.require_mixed_case',
				'label'     => __( 'Require mixed case', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			),
			array(
				'id'        => 'passwords.require_number',
				'label'     => __( 'Require a number', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			),
			array(
				'id'        => 'passwords.require_symbol',
				'label'     => __( 'Require a symbol', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			),

			array(
				'id'        => 'sessions.enabled',
				'label'     => __( 'Enforce session timeout', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			),
			array(
				'id'                 => 'sessions.timeouts',
				'label'              => __( 'Per-role timeouts (minutes)', 'fanxie-wp-core' ),
				'type'               => 'textarea',
				'default'            => array(),
				'sanitizer_callback' => array( $this, 'sanitize_timeouts' ),
			),
		);
	}

	/**
	 * Clean + cap the trusted IP allowlist.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public function sanitize_ip_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/[\s,]+/', trim( $value ) );
			$value = is_array( $parts ) ? $parts : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$clean = array();
		foreach ( $value as $ip ) {
			$ip = is_string( $ip ) ? trim( $ip ) : '';
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $clean, true ) ) {
				$clean[] = $ip;
			}
			if ( count( $clean ) >= 100 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Clean + sort the lockout tiers, falling back to defaults when empty.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array{threshold: int, lockout_minutes: int}>
	 */
	public function sanitize_tiers( mixed $value ): array {
		if ( ! is_array( $value ) || array() === $value ) {
			return $this->get_default_config()['attempts']['tiers'];
		}
		$clean = array();
		foreach ( $value as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$threshold = absint( $tier['threshold'] ?? 0 );
			$lockout   = absint( $tier['lockout_minutes'] ?? 0 );
			if ( $threshold > 0 && $lockout > 0 ) {
				$clean[] = array(
					'threshold'       => $threshold,
					'lockout_minutes' => $lockout,
				);
			}
		}
		if ( array() === $clean ) {
			return $this->get_default_config()['attempts']['tiers'];
		}
		usort( $clean, static fn ( $a, $b ) => $a['threshold'] <=> $b['threshold'] );
		return $clean;
	}

	/**
	 * Sanitise the custom login slug, rejecting reserved WordPress paths.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_slug( mixed $value ): string {
		$slug     = sanitize_title( (string) $value );
		$reserved = array( 'wp-admin', 'wp-login', 'admin', 'login', 'wp-content', 'wp-includes', 'wp-json' );
		return in_array( $slug, $reserved, true ) ? '' : $slug;
	}

	/**
	 * Clean the per-role session timeouts, falling back to defaults when empty.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, int>
	 */
	public function sanitize_timeouts( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return $this->get_default_config()['sessions']['timeouts'];
		}
		$clean = array();
		foreach ( $value as $role => $minutes ) {
			$role = sanitize_key( (string) $role );
			$min  = absint( $minutes );
			if ( '' !== $role && $min > 0 ) {
				$clean[ $role ] = $min;
			}
		}
		return array() === $clean ? $this->get_default_config()['sessions']['timeouts'] : $clean;
	}

	/**
	 * Assemble and wire the module's runtime collaborators.
	 *
	 * Builds the shared IP resolver + persistence repositories, mounts the admin
	 * AJAX surface on the shared router, then hands each fine-grained feature its
	 * own config slice and lets it decide whether to attach. Every emitter gates
	 * itself on its settings, so at the shipped defaults only attempt limiting
	 * (on by default) hooks `authenticate`; hide-login, strong passwords, and
	 * session timeout stand down until switched on. The daily prune handler is
	 * wired unconditionally, and the break-glass WP-CLI commands are registered
	 * only under a live WP-CLI runtime.
	 */
	public function register_hooks(): void {
		$config = $this->get_config();

		// Narrow each `mixed` sub-tree to an array before handing it to a
		// collaborator that expects one — the config is schema-sanitised, but
		// `get_config()` is typed loosely as `array<string, mixed>`.
		$attempts   = isset( $config['attempts'] ) && is_array( $config['attempts'] ) ? $config['attempts'] : array();
		$hide_login = isset( $config['hide_login'] ) && is_array( $config['hide_login'] ) ? $config['hide_login'] : array();
		$passwords  = isset( $config['passwords'] ) && is_array( $config['passwords'] ) ? $config['passwords'] : array();
		$sessions   = isset( $config['sessions'] ) && is_array( $config['sessions'] ) ? $config['sessions'] : array();

		$ip   = new IpResolver(
			(bool) ( $attempts['trust_proxy'] ?? false ),
			(string) ( $attempts['proxy_header'] ?? 'HTTP_X_FORWARDED_FOR' )
		);
		$log  = new LoginLogRepository();
		$bans = new BanRepository();

		// The AJAX controller registers its sub-actions on the shared router and
		// schedules the daily prune event; this is the module's first real read
		// of the injected router.
		( new AjaxController( $this, $log, $bans ) )->register( $this->ajax_router );

		( new AttemptLimiter( $attempts, $ip, $log, $bans ) )->register_hooks();
		( new LoginSlugGuard( $hide_login ) )->register_hooks();
		( new PasswordPolicy( $passwords ) )->register_hooks();
		( new SessionTimeout( $sessions ) )->register_hooks();

		// Cron handler for the event the AJAX controller scheduled: trim stale log
		// rows and expired bans on the module's retention window.
		$retention_days = (int) ( $attempts['log_retention_days'] ?? 30 );
		add_action(
			AjaxController::PRUNE_HOOK,
			static function () use ( $log, $bans, $retention_days ): void {
				$log->prune( $retention_days );
				$bans->prune_expired();
			}
		);

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'fx-core login', new LoginCommand( $config, $bans ) );
		}
	}
}
