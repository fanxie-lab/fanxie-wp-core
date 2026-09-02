<?php
/**
 * Runtime guard against WordPress user enumeration vectors.
 *
 * @package FanxieLab\WPCore\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening\Runtime;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks the two canonical user-enumeration paths:
 *
 *   1. `/?author=N` — WordPress rewrites this to `/author/{slug}/`, which
 *      leaks usernames. We block the redirect for unauthenticated requests.
 *   2. `GET /wp-json/wp/v2/users(/\d+)?` — returns a JSON list of authors
 *      with login slugs. We 401 unauthenticated callers before the core
 *      controller runs.
 *
 * Both behaviors are independently toggleable via the module config.
 */
final class UserEnumerationGuard {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach hooks consulted by WordPress core.
	 */
	public function register_hooks(): void {
		if ( $this->should_block_author_archive() ) {
			add_filter( 'redirect_canonical', [ $this, 'filter_redirect_canonical' ], 10, 2 );
			add_action( 'template_redirect', [ $this, 'maybe_block_author_query' ], 0 );
		}

		if ( $this->should_block_rest_users_endpoint() ) {
			add_filter( 'rest_request_before_callbacks', [ $this, 'filter_rest_request' ], 10, 3 );
		}
	}

	/**
	 * Short-circuit `redirect_canonical` for `?author=N` probes.
	 *
	 * @param string|false $redirect_url  Canonical redirect URL.
	 * @param string       $requested_url Originally requested URL.
	 * @return string|false
	 */
	public function filter_redirect_canonical( $redirect_url, string $requested_url ) {
		unset( $requested_url );

		if ( is_user_logged_in() ) {
			return $redirect_url;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query inspection.
		if ( isset( $_GET['author'] ) && '' !== (string) $_GET['author'] ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Respond 404 on `?author=N` archive requests for guests.
	 *
	 * `redirect_canonical` only covers the initial redirect; when pretty
	 * permalinks are disabled the request lands directly on the author
	 * archive. Catch that case here and bail before the template loads.
	 */
	public function maybe_block_author_query(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query inspection.
		$has_author = isset( $_GET['author'] ) && '' !== (string) $_GET['author'];
		if ( ! $has_author && ! is_author() ) {
			return;
		}

		status_header( 404 );
		nocache_headers();

		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'Not Found', 'fanxie-wp-core' ), '', [ 'response' => 404 ] );
		}
	}

	/**
	 * 401 unauthenticated callers hitting `/wp-json/wp/v2/users`.
	 *
	 * `$response` arrives as either `WP_REST_Response`, `WP_Error`, or `null`
	 * depending on upstream filters — any short-circuit short-circuits us too,
	 * and we only intervene on the users endpoint when the caller is a guest.
	 *
	 * @param WP_REST_Response|WP_Error|null $response Upstream response.
	 * @param array<string, mixed>           $handler  Handler spec.
	 * @param WP_REST_Request                $request  Incoming request.
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function filter_rest_request( $response, array $handler, WP_REST_Request $request ) {
		unset( $handler );

		if ( is_user_logged_in() ) {
			return $response;
		}

		$method = (string) $request->get_method();
		if ( 'GET' !== $method ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( 1 !== preg_match( '#^/wp/v2/users(?:/|$)#', $route ) ) {
			return $response;
		}

		return new WP_Error(
			'rest_user_cannot_view',
			__( 'Sorry, you are not allowed to list users.', 'fanxie-wp-core' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * Is the author-archive block enabled, with filter opt-out?
	 */
	private function should_block_author_archive(): bool {
		$value = (bool) ( $this->config['user_enumeration']['block_author_archive'] ?? true );

		/**
		 * Filter: fanxie_wp_core/hardening/should_block_author_enum
		 *
		 * Per-request override for the author-enumeration block.
		 *
		 * @since 0.2.0-dev
		 *
		 * @param bool $value Current decision (`true` = block).
		 */
		return (bool) apply_filters( 'fanxie_wp_core/hardening/should_block_author_enum', $value );
	}

	/**
	 * Is the REST users endpoint block enabled?
	 */
	private function should_block_rest_users_endpoint(): bool {
		return (bool) ( $this->config['user_enumeration']['block_rest_users_endpoint'] ?? true );
	}
}
