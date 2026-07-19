<?php
/**
 * Hide-login slug guard: serves wp-login.php from a secret slug and 404s the
 * raw entry point.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection\Runtime;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the login screen behind an unguessable slug.
 *
 * This is the highest-risk feature in the module: a mistake can lock every
 * administrator out. The design is therefore built around fail-safe recovery
 * and never touching request types that must always resolve:
 *
 *   - The `FX_CORE_LOGIN_SLUG` wp-config constant is the escape hatch. It wins
 *     over the stored slug, so an administrator who forgets or loses the slug
 *     can drop one line in `wp-config.php` (or the CLI reveal in Task 9) and
 *     regain access without database surgery.
 *   - admin-ajax, WP-Cron and the REST API are hard-excluded up front
 *     ({@see self::is_safe_context()}) so background/AJAX/API traffic is never
 *     intercepted, whatever the request path looks like.
 *   - Only two request shapes are ever acted on: the literal `wp-login.php`
 *     entry point and HTML `/wp-admin` page loads. Everything else — front-end
 *     pages, feeds, uploads — passes straight through.
 *
 * Routing technique (the canonical "hide login" approach):
 *   - A request to `/{slug}` loads `wp-login.php`, so the real login form
 *     renders at the secret slug.
 *   - A request to the raw `wp-login.php` that did not arrive via the slug and
 *     is not one of the preserved `action=` flows (logout, password reset,
 *     …) is answered with a 404, so the endpoint looks like it does not exist.
 *   - Generated login URLs (`wp_login_url()`, the login form action, password
 *     reset e-mail links, logout links) are rewritten to the slug through the
 *     `site_url` / `network_site_url` / `login_url` filters, and runtime
 *     redirects that target `wp-login.php` (post-logout, check-your-e-mail) are
 *     rewritten through the `wp_redirect` filter.
 *
 * Hook timing note: the module registry calls {@see self::register_hooks()} on
 * `init` (priority 5), by which point `plugins_loaded` has already fired and —
 * more importantly — the pluggable functions and main query that the terminal
 * serve/deny/redirect need are only guaranteed after `wp_loaded`. All
 * interception therefore runs on `wp_loaded` (priority 1), the earliest point
 * that is both reachable from `init` and safe for the terminal work.
 */
final class LoginSlugGuard {

	/**
	 * Slugs that must never become the login path — they collide with core
	 * routes. Mirrors the reserved list enforced by
	 * `LoginProtection::sanitize_slug()` so the constant escape hatch is held to
	 * the same bar as stored input.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED = [ 'wp-admin', 'wp-login', 'admin', 'login', 'wp-content', 'wp-includes', 'wp-json' ];

	/**
	 * `action=` values whose flows must keep resolving at the raw endpoint —
	 * logout links and password-reset links land here and must not 404.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ACTIONS = [
		'logout',
		'lostpassword',
		'retrievepassword',
		'rp',
		'resetpass',
		'register',
		'postpass',
		'confirmaction',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config The module's `hide_login` sub-config:
	 *                                      `['enabled' => bool, 'slug' => string]`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * The active login slug, sanitised, with the wp-config constant winning.
	 *
	 * `FX_CORE_LOGIN_SLUG` (run through `sanitize_title()` and the reserved
	 * check) takes precedence over the stored slug whenever it yields a valid
	 * value; otherwise resolution falls back to the stored slug. Returns an
	 * empty string when neither source yields a usable slug.
	 *
	 * Kept public: the `wp fx-core login reveal` CLI (Task 9) reads it.
	 */
	public function effective_slug(): string {
		if ( defined( 'FX_CORE_LOGIN_SLUG' ) ) {
			$from_constant = $this->normalize_slug( (string) constant( 'FX_CORE_LOGIN_SLUG' ) );
			if ( '' !== $from_constant ) {
				return $from_constant;
			}
		}

		$stored = isset( $this->config['slug'] ) && is_string( $this->config['slug'] ) ? $this->config['slug'] : '';

		return $this->normalize_slug( $stored );
	}

	/**
	 * Whether hide-login is switched on and resolves to a usable slug.
	 */
	public function is_active(): bool {
		return ! empty( $this->config['enabled'] ) && '' !== $this->effective_slug();
	}

	/**
	 * Attach the routing hooks — nothing at all unless the feature is active.
	 */
	public function register_hooks(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// Terminal routing runs as early as `init` allows: `wp_loaded` fires once
		// per request, after pluggable functions and the main query exist, and
		// before `wp-login.php`'s own logic (it is reached via `wp-load.php`) and
		// before `wp-admin/admin.php`'s `auth_redirect()`.
		add_action( 'wp_loaded', [ $this, 'dispatch' ], 1 );

		// Rewrite every generated login URL to the slug. `site_url()` backs
		// `wp_login_url()` and the login form action; `network_site_url()` backs
		// password-reset e-mail links; `login_url` is the belt-and-braces final
		// filter. All three funnel through the same idempotent rewrite.
		add_filter( 'site_url', [ $this, 'filter_site_url' ], 10, 1 );
		add_filter( 'network_site_url', [ $this, 'filter_network_site_url' ], 10, 1 );
		add_filter( 'login_url', [ $this, 'filter_login_url' ], 20, 1 );

		// Catch runtime redirects that core builds from the hard-coded
		// `wp-login.php` string (post-logout, "check your e-mail"), which never
		// pass through `site_url()`.
		add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect' ], 10, 1 );
	}

	/**
	 * The `wp_loaded` handler: perform whatever the request resolves to.
	 *
	 * Order matters — the `/wp-admin` guard runs first so an unauthenticated
	 * admin page load is redirected to the slug before the login routing (which
	 * only concerns the login endpoint itself) is considered.
	 */
	public function dispatch(): void {
		if ( $this->is_safe_context() ) {
			return;
		}

		if ( $this->should_redirect_admin() ) {
			$this->redirect_to_login();
			return;
		}

		switch ( $this->resolve_action() ) {
			case 'serve':
				$this->serve_login();
				break;
			case 'deny':
				$this->deny();
				break;
		}
	}

	/**
	 * Classify the login endpoint request: `serve`, `deny`, or `none`.
	 *
	 * The slug is checked first, so a slug request is served even if some other
	 * signal (a rewritten `$pagenow`, say) also looks login-ish. A raw
	 * `wp-login.php` hit is denied only when it did not arrive via the slug and
	 * is not one of the preserved `action=` flows.
	 */
	public function resolve_action(): string {
		if ( $this->is_safe_context() ) {
			return 'none';
		}

		if ( $this->is_slug_request() ) {
			return 'serve';
		}

		if ( $this->is_wp_login_request() && ! $this->is_allowed_action() ) {
			return 'deny';
		}

		return 'none';
	}

	/**
	 * Request types that must never be intercepted.
	 *
	 * Traffic for admin-ajax, WP-Cron and the REST API stays reachable no matter
	 * what the request path looks like. `wp_doing_ajax()` and `wp_doing_cron()`
	 * are the canonical, filterable expressions of `defined('DOING_AJAX')` /
	 * `defined('DOING_CRON')` (and are what the coding standards mandate over a
	 * raw constant read); REST is gated on `REST_REQUEST`, which has no core
	 * helper on the minimum supported WordPress version.
	 */
	public function is_safe_context(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Whether the current request targets the secret login slug.
	 *
	 * Primary signal is the trailing path segment (works on root and
	 * subdirectory installs). On sites without pretty permalinks the login URL
	 * is `?slug`, so a matching query key is honoured as a fallback.
	 */
	public function is_slug_request(): bool {
		$slug = $this->effective_slug();
		if ( '' === $slug ) {
			return false;
		}

		if ( $this->trailing_segment( $this->request_path() ) === $slug ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check; no state change.
		return ! get_option( 'permalink_structure' ) && isset( $_GET[ $slug ] );
	}

	/**
	 * Whether the current request is the literal `wp-login.php` entry point.
	 *
	 * Path-based only: the `wp-login.php` filename appears in the trailing
	 * segment exactly when the raw endpoint is being hit, and (unlike a rewritten
	 * `$pagenow`) it can never be spoofed onto an ordinary front-end page.
	 */
	public function is_wp_login_request(): bool {
		return 'wp-login.php' === $this->trailing_segment( $this->request_path() );
	}

	/**
	 * Whether the request carries a preserved `action=` flow.
	 *
	 * These flows (logout, password reset, registration, post-password) must
	 * resolve at the raw endpoint because logout links and password-reset
	 * e-mails can legitimately land there. None of them expose a brute-forceable
	 * login form, so allowing them does not weaken the hidden login.
	 */
	public function is_allowed_action(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision; no state change.
		$raw    = isset( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : '';
		$action = sanitize_key( is_string( $raw ) ? $raw : '' );

		return in_array( $action, self::ALLOWED_ACTIONS, true );
	}

	/**
	 * Whether an unauthenticated HTML `/wp-admin` request should be redirected.
	 *
	 * `admin-ajax.php` is excluded by {@see self::is_safe_context()}; `admin-post.php`
	 * is excluded here because logged-out visitors legitimately POST to it for
	 * `admin_post_nopriv_*` handlers (public forms).
	 */
	public function should_redirect_admin(): bool {
		if ( ! is_admin() || $this->is_safe_context() ) {
			return false;
		}

		if ( in_array( $this->trailing_segment( $this->request_path() ), [ 'admin-ajax.php', 'admin-post.php' ], true ) ) {
			return false;
		}

		return ! is_user_logged_in();
	}

	/**
	 * Rewrite a `site_url()` result that points at `wp-login.php` to the slug.
	 *
	 * @param string $url The generated URL.
	 */
	public function filter_site_url( string $url ): string {
		return $this->maybe_rewrite_login_url( $url );
	}

	/**
	 * Rewrite a `network_site_url()` result that points at `wp-login.php`.
	 *
	 * @param string $url The generated URL.
	 */
	public function filter_network_site_url( string $url ): string {
		return $this->maybe_rewrite_login_url( $url );
	}

	/**
	 * Final rewrite of `wp_login_url()` output (idempotent after `site_url`).
	 *
	 * @param string $login_url The login URL.
	 */
	public function filter_login_url( string $login_url ): string {
		return $this->maybe_rewrite_login_url( $login_url );
	}

	/**
	 * Rewrite a redirect `Location` that targets `wp-login.php` to the slug.
	 *
	 * Catches core's hard-coded post-flow redirects — `wp-login.php?loggedout=true`,
	 * `wp-login.php?checkemail=confirm` — which never pass through `site_url()`.
	 *
	 * @param string $location The redirect target.
	 */
	public function filter_wp_redirect( string $location ): string {
		return $this->maybe_rewrite_login_url( $location );
	}

	/**
	 * Sanitise a candidate slug and reject reserved core paths.
	 *
	 * @param string $value Raw slug candidate.
	 */
	private function normalize_slug( string $value ): string {
		$slug = sanitize_title( $value );

		return in_array( $slug, self::RESERVED, true ) ? '' : $slug;
	}

	/**
	 * The decoded path of the current request (query string stripped).
	 */
	private function request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- decoded and parsed to a path below; never echoed.
		$raw  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( rawurldecode( $raw ), PHP_URL_PATH );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * The last non-empty segment of a path (`/wp/mylogin/` → `mylogin`).
	 *
	 * @param string $path A URL path.
	 */
	private function trailing_segment( string $path ): string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );

		return (string) end( $segments );
	}

	/**
	 * Swap a `wp-login.php` URL for the slug, preserving any query args.
	 *
	 * Idempotent: a URL that does not contain `wp-login.php` (including one
	 * already rewritten to the slug) is returned untouched.
	 *
	 * @param string $url Candidate URL.
	 */
	private function maybe_rewrite_login_url( string $url ): string {
		if ( ! str_contains( $url, 'wp-login.php' ) ) {
			return $url;
		}

		$base  = $this->new_login_url();
		$parts = explode( '?', $url, 2 );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$args = [];
			wp_parse_str( $parts[1], $args );

			return add_query_arg( $args, $base );
		}

		return $base;
	}

	/**
	 * The public URL of the login slug for the current permalink mode.
	 */
	private function new_login_url(): string {
		$slug = $this->effective_slug();
		if ( '' === $slug ) {
			return home_url( '/' );
		}

		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( $slug ) );
		}

		return add_query_arg( $slug, '', home_url( '/' ) );
	}

	/**
	 * Load `wp-login.php` so the real form renders at the slug, then stop.
	 *
	 * Deferred to `wp_loaded` on purpose: by now pluggable functions and the
	 * environment `wp-login.php` relies on are fully loaded, so requiring it is
	 * safe. Its own top-level `require wp-load.php` is a no-op (already loaded).
	 */
	private function serve_login(): void {
		// Present the request to core as the login screen. The referenced globals
		// are the ones `wp-login.php` reads/writes at file scope.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately routing this request to the login handler.
		$GLOBALS['pagenow'] = 'wp-login.php';
		global $error, $interim_login, $action, $user_login;

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Answer the raw `wp-login.php` request with a 404, then stop.
	 *
	 * Prefers the active theme's 404 template so the endpoint is indistinguishable
	 * from any missing page; falls back to a bare 404 when no template resolves.
	 */
	private function deny(): void {
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$template = get_404_template();
		if ( '' !== $template && file_exists( $template ) ) {
			require $template;
			exit;
		}

		wp_die( esc_html__( 'Not Found', 'fanxie-wp-core' ), '', [ 'response' => 404 ] );
	}

	/**
	 * Redirect an unauthenticated `/wp-admin` request to the slug login screen.
	 *
	 * Runs before core's own `auth_redirect()`, and preserves the requested
	 * admin URL as `redirect_to` so the user lands back where they intended.
	 */
	private function redirect_to_login(): void {
		$requested = home_url( $this->request_path() );
		$login_url = add_query_arg( 'redirect_to', rawurlencode( $requested ), $this->new_login_url() );

		wp_safe_redirect( $login_url );
		exit;
	}
}
