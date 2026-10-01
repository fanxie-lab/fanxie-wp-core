<?php
/**
 * Hide-login slug guard: serves wp-login.php from a secret slug and 404s the
 * raw entry point.
 *
 * @package FanxieLab\Warden\Modules\LoginProtection\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\LoginProtection\Runtime;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the login screen behind an unguessable slug.
 *
 * This is the highest-risk feature in the module: a mistake can lock every
 * administrator out. The design is therefore built around fail-safe recovery
 * and never touching request types that must always resolve:
 *
 *   - The `FX_WARDEN_LOGIN_SLUG` wp-config constant is the escape hatch. It wins
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
	 * `FX_WARDEN_LOGIN_SLUG` (run through `sanitize_title()` and the reserved
	 * check) takes precedence over the stored slug whenever it yields a valid
	 * value; otherwise resolution falls back to the stored slug. Returns an
	 * empty string when neither source yields a usable slug.
	 *
	 * Kept public: the `wp fx-warden login reveal` CLI (Task 9) reads it.
	 */
	public function effective_slug(): string {
		if ( defined( 'FX_WARDEN_LOGIN_SLUG' ) ) {
			$from_constant = $this->normalize_slug( (string) constant( 'FX_WARDEN_LOGIN_SLUG' ) );
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
	 * raw constant read); those constants are set before `wp-load.php`, so the
	 * helpers are authoritative at `wp_loaded`.
	 *
	 * REST needs a path check, not a constant check. The guard runs on
	 * `wp_loaded` (priority 1), but core only defines `REST_REQUEST` inside
	 * `rest_api_loaded()`, which is hooked on `parse_request` — that fires
	 * *after* `wp_loaded`. So for a genuine `/wp-json/…` request `REST_REQUEST`
	 * is not yet defined when the guard evaluates, and a `defined('REST_REQUEST')`
	 * gate could never fire in time. Matching the request path against
	 * {@see self::is_rest_path()} (which reads the filterable
	 * `rest_get_url_prefix()`) is what actually keeps the REST API reachable
	 * here; the `REST_REQUEST` check is retained only as belt-and-suspenders for
	 * any later re-evaluation once core has defined it.
	 */
	public function is_safe_context(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( $this->is_rest_path() ) {
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
		// sanitize_key() already returns '' for a non-scalar (e.g. `?action[]=x`),
		// so it doubles as the type guard the previous is_string() check provided.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision; no state change.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

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
	 * Whether the current request targets the REST API, decided by path alone.
	 *
	 * This is the REST carve-out that actually works at `wp_loaded`: it never
	 * consults `REST_REQUEST` (undefined until `parse_request`) and instead asks
	 * whether the request path begins with the site's REST prefix. The prefix is
	 * read from `rest_get_url_prefix()` (default `wp-json`) so a filtered prefix
	 * is honoured, and the site's home path is stripped first so the check holds
	 * on subdirectory installs (`/blog/wp-json/…`) as well as root installs.
	 */
	private function is_rest_path(): bool {
		$prefix = trim( rest_get_url_prefix(), '/' );
		if ( '' === $prefix ) {
			return false;
		}

		$path = trim( $this->request_path(), '/' );

		// Reduce to a site-relative path so a subdirectory install still matches.
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
		if ( '' !== $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}

		return $path === $prefix || str_starts_with( $path, $prefix . '/' );
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
	 * Swap a `wp-login.php` URL for the slug, preserving any query string verbatim.
	 *
	 * Idempotent: a URL that does not contain `wp-login.php` (including one
	 * already rewritten to the slug) is returned untouched.
	 *
	 * The original query string is re-attached byte-for-byte rather than decoded
	 * and rebuilt. Decoding then rebuilding (`wp_parse_str()` → `add_query_arg()`)
	 * is unsafe here: `add_query_arg()` does not re-encode values, so a
	 * percent-encoded `&`/`=` inside a value would first decode and then splice in
	 * an unintended parameter. Keeping the raw query verbatim closes that gap and
	 * leaves normal login/reset URLs byte-identical in practice — the only query
	 * strings this ever sees are core's own (`action=logout`, `action=rp&key=…`,
	 * `loggedout=true`), which carry no encoded separators.
	 *
	 * @param string $url Candidate URL.
	 */
	private function maybe_rewrite_login_url( string $url ): string {
		if ( ! str_contains( $url, 'wp-login.php' ) ) {
			return $url;
		}

		$base  = $this->new_login_url();
		$parts = explode( '?', $url, 2 );
		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			return $base;
		}

		// The plain-permalink base already carries the slug as `?slug`, so a
		// second parameter group must join with `&`; the pretty-permalink base is
		// query-less and opens the string with `?`.
		$separator = str_contains( $base, '?' ) ? '&' : '?';

		return $base . $separator . $parts[1];
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

		// wp-login.php assigns these at file scope; because the require runs
		// inside this method they must be declared `global` or they become
		// method-local and the values other hooks read (notably `$errors`, the
		// login WP_Error bag the Hardening error-obfuscator inspects) never reach
		// the global scope. Declaring the full set mirrors the native page so
		// slug-served logins behave identically.
		global $error, $errors, $interim_login, $action, $user_login, $redirect_to, $rp_key, $rp_login, $user, $wp_error;

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

		wp_die( esc_html__( 'Not Found', 'fanxie-warden' ), '', [ 'response' => 404 ] );
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
