<?php
/**
 * Per-role session timeout: shorten auth-cookie lifetimes + idle-logout on admin.
 *
 * @package FanxieLab\Warden\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\LoginProtection\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces a configurable, per-role session length in two coordinated ways:
 *
 *   1. Server-side — filters `auth_cookie_expiration` so a role's auth cookie is
 *      minted with a shorter maximum age. This is the authoritative control: once
 *      the cookie expires WordPress requires a fresh login regardless of the tab.
 *   2. Client-side — enqueues a tiny, dependency-free script on wp-admin that logs
 *      an idle user out after the same per-role window, so an unattended dashboard
 *      does not sit logged-in until the cookie itself lapses.
 *
 * The server-side filter only ever *shortens*: it returns `min( $length, cap )`,
 * so it can tighten what WordPress already grants but never extend a session
 * beyond it (a security control must not weaken the platform default). When the
 * user or their role cannot be resolved, the incoming length is returned
 * untouched — the filter fails open to WordPress's own decision.
 */
final class SessionTimeout {

	/**
	 * Enqueue handle for the idle-logout script.
	 *
	 * @var string
	 */
	private const SCRIPT_HANDLE = 'fanxie-warden-idle-logout';

	/**
	 * Plugin-relative path to the checked-in idle-logout script.
	 *
	 * @var string
	 */
	private const SCRIPT_PATH = 'assets/login-protection/idle-logout.js';

	/**
	 * JS global the script reads its runtime config from.
	 *
	 * @var string
	 */
	private const SCRIPT_OBJECT = 'fanxieWardenIdle';

	/**
	 * Fallback timeout (minutes) when a role has no explicit entry and the config
	 * carries no `default` — mirrors the module's shipped default.
	 *
	 * @var int
	 */
	private const FALLBACK_MINUTES = 120;

	/**
	 * Fallback config so a partial `sessions` sub-config still behaves sanely.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'enabled'  => false,
		'timeouts' => [
			'administrator' => 30,
			'default'       => 120,
		],
	];

	/**
	 * Resolved sessions config (module defaults merged with supplied overrides).
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $config;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config The module's `sessions` sub-config:
	 *                                      `enabled` (bool) and `timeouts`
	 *                                      (`role => minutes`, including a
	 *                                      `default` fallback).
	 */
	public function __construct( array $config ) {
		$this->config = array_merge( self::DEFAULTS, $config );
	}

	/**
	 * Attach the enforcement hooks — nothing at all unless sessions are enabled.
	 */
	public function register_hooks(): void {
		if ( empty( $this->config['enabled'] ) ) {
			return;
		}

		add_filter( 'auth_cookie_expiration', [ $this, 'cookie_lifetime' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_idle_script' ] );
	}

	/**
	 * Cap the auth-cookie lifetime for the authenticating user's primary role.
	 *
	 * Resolves the user's first role, converts its configured minutes to seconds,
	 * and returns `min( $length, cap )` so the session is only ever *shortened*.
	 * If the user or role cannot be resolved, the incoming length is returned
	 * unchanged — the filter never lengthens a session.
	 *
	 * @param int  $length   The lifetime (seconds) WordPress would otherwise use.
	 * @param int  $user_id  The authenticating user's id.
	 * @param bool $remember Whether "remember me" was checked (unused — the cap
	 *                       applies to both remembered and single-session cookies).
	 * @return int The (possibly shortened) cookie lifetime in seconds.
	 */
	public function cookie_lifetime( int $length, int $user_id, bool $remember ): int {
		unset( $remember );

		$cap = $this->role_seconds_for_user( $user_id );
		if ( null === $cap ) {
			return $length;
		}

		return min( $length, $cap );
	}

	/**
	 * Enqueue the dependency-free idle-logout script on wp-admin.
	 *
	 * Only enqueues for a signed-in user whose role resolves to a positive
	 * timeout; the script's inactivity window matches the current user's per-role
	 * cap and, on expiry, redirects to the WordPress logout URL. The script is a
	 * plain checked-in file (not part of the Vue/Vite admin bundle) and is
	 * versioned by its own mtime so a redeploy busts the browser cache.
	 */
	public function enqueue_idle_script(): void {
		$current = wp_get_current_user();
		$user_id = (int) $current->ID;
		if ( $user_id <= 0 ) {
			return;
		}

		$roles = is_array( $current->roles ) ? $current->roles : [];
		$cap   = $this->role_seconds_from_roles( $roles );
		if ( null === $cap || $cap <= 0 ) {
			return;
		}

		$path    = FANXIE_WARDEN_PATH . self::SCRIPT_PATH;
		$version = file_exists( $path ) ? (string) filemtime( $path ) : FANXIE_WARDEN_VERSION;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			FANXIE_WARDEN_URL . self::SCRIPT_PATH,
			[],
			$version,
			[ 'in_footer' => true ]
		);

		/*
		 * Inject the runtime config as a JSON literal via wp_add_inline_script()
		 * rather than wp_localize_script(). `WP_Scripts::localize()` casts every
		 * scalar to a string before JSON-encoding, so `timeoutMs` would reach the
		 * browser as the STRING "1800000"; wp_json_encode() preserves the integer
		 * type so the script receives a real number. This mirrors how the admin
		 * SPA hydrates `window.fanxieWarden` (see Admin\SettingsPage).
		 */
		$config = wp_json_encode(
			[
				'timeoutMs' => $cap * 1000,
				'logoutUrl' => wp_logout_url(),
			]
		);

		if ( false === $config ) {
			return;
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.' . self::SCRIPT_OBJECT . ' = ' . $config . ';',
			'before'
		);
	}

	/**
	 * Resolve a user's per-role cap in seconds, or null when it can't be resolved.
	 *
	 * @param int $user_id The user id to look up.
	 * @return int|null Cap in seconds, or null (user/role unresolved).
	 */
	private function role_seconds_for_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$roles = is_array( $user->roles ) ? $user->roles : [];

		return $this->role_seconds_from_roles( $roles );
	}

	/**
	 * Convert a user's role list to a cap in seconds using the primary (first)
	 * role, or null when the list is empty.
	 *
	 * @param array<int|string, mixed> $roles The user's roles (first is primary).
	 * @return int|null Cap in seconds, or null when no role is present.
	 */
	private function role_seconds_from_roles( array $roles ): ?int {
		if ( [] === $roles ) {
			return null;
		}

		$primary = (string) reset( $roles );
		if ( '' === $primary ) {
			return null;
		}

		return $this->minutes_for_role( $primary ) * 60;
	}

	/**
	 * Look up the timeout (minutes) for a role, falling back to the config's
	 * `default` entry and, failing that, the shipped fallback.
	 *
	 * @param string $role The role slug.
	 * @return int Timeout in minutes (always positive).
	 */
	private function minutes_for_role( string $role ): int {
		$timeouts = is_array( $this->config['timeouts'] ) ? $this->config['timeouts'] : [];

		if ( isset( $timeouts[ $role ] ) ) {
			return max( 1, (int) $timeouts[ $role ] );
		}

		return max( 1, (int) ( $timeouts['default'] ?? self::FALLBACK_MINUTES ) );
	}
}
