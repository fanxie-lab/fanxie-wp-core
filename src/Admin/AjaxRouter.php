<?php
/**
 * AJAX dispatcher for the Fanxie WP Core admin SPA.
 *
 * @package FanxieLab\WPCore\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Admin;

use FanxieLab\WPCore\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Single-entry AJAX router.
 *
 * The frontend posts every admin request to `admin-ajax.php?action=fanxie_wp_core`
 * with the following body shape:
 *
 *   {
 *     action:        'fanxie_wp_core',
 *     _action:       '<sub_action>',
 *     _ajax_nonce:   '<nonce>',    // action: 'fanxie_wp_core_admin'
 *     ...payload
 *   }
 *
 * Dispatcher guarantees:
 *   - Nonce verified (`fanxie_wp_core_admin`).
 *   - Capability `manage_fanxie_wp_core` required.
 *   - Only logged-in users (no `wp_ajax_nopriv_*` binding).
 *   - Sub-actions resolve from an internal map (filterable via
 *     `fanxie_wp_core/ajax/sub_actions`).
 *   - Handlers return either an array (→ `wp_send_json_success`) or a WP_Error
 *     (→ `wp_send_json_error` with the error's first code/message).
 *
 * Built-in sub-actions:
 *   - `ping` → `{ pong: true, time: <unix> }` (used by the frontend to smoke-test
 *     the wiring).
 */
final class AjaxRouter {

	/**
	 * Nonce action used by every admin AJAX request.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'fanxie_wp_core_admin';

	/**
	 * WordPress-side AJAX action name ("action" POST field).
	 *
	 * @var string
	 */
	public const AJAX_ACTION = 'fanxie_wp_core';

	/**
	 * Sub-action → { callback, cap } map.
	 *
	 * @var array<string, array{callback: callable, cap: ?string}>
	 */
	private array $handlers = [];

	/**
	 * Constructor — wires the built-in `ping` sub-action.
	 */
	public function __construct() {
		$this->register( 'ping', [ $this, 'handle_ping' ] );
	}

	/**
	 * Register an AJAX handler.
	 *
	 * @param string      $sub_action Sub-action slug (sanitised via `sanitize_key`).
	 * @param callable    $handler    Receives the raw `$_POST` array; returns array|WP_Error.
	 * @param string|null $cap        Optional extra capability required in addition
	 *                                to `manage_fanxie_wp_core`.
	 */
	public function register( string $sub_action, callable $handler, ?string $cap = null ): void {
		$sub_action = sanitize_key( $sub_action );
		if ( '' === $sub_action ) {
			return;
		}

		$this->handlers[ $sub_action ] = [
			'callback' => $handler,
			'cap'      => $cap,
		];
	}

	/**
	 * Attach the dispatcher to WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'dispatch' ] );
	}

	/**
	 * Dispatch an incoming AJAX request.
	 *
	 * Accepts either form-encoded `$_POST` bodies (for simple flat payloads)
	 * or `application/json` bodies read from `php://input` (required for
	 * nested payloads, which URL-encoded forms flatten into JSON strings).
	 * JSON wins when both are present — the client uses JSON by default.
	 *
	 * Terminates via `wp_send_json_success` / `wp_send_json_error`.
	 */
	public function dispatch(): void {
		$payload = $this->read_payload();

		// `check_ajax_referer` reads `$_REQUEST['_ajax_nonce']` by default — that
		// field is absent for JSON bodies, so verify the nonce directly from
		// the decoded payload.
		$nonce = isset( $payload['_ajax_nonce'] ) ? (string) $payload['_ajax_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_nonce',
					'message' => __( 'Nonce verification failed.', 'fanxie-wp-core' ),
				],
				403
			);
		}

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error(
				[
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to perform this action.', 'fanxie-wp-core' ),
				],
				403
			);
		}

		$raw_sub_action = $payload['_action'] ?? '';
		$sub_action     = is_string( $raw_sub_action ) ? sanitize_key( $raw_sub_action ) : '';

		if ( '' === $sub_action ) {
			wp_send_json_error(
				[
					'code'    => 'missing_action',
					'message' => __( 'Missing sub-action.', 'fanxie-wp-core' ),
				],
				400
			);
		}

		/**
		 * Filter: fanxie_wp_core/ajax/sub_actions
		 *
		 * Modify the sub-action handler map before dispatch. Useful for tests
		 * and third-party extensions.
		 *
		 * @param array<string, array{callback: callable, cap: ?string}> $map
		 */
		$handlers = apply_filters( 'fanxie_wp_core/ajax/sub_actions', $this->handlers );
		$handlers = is_array( $handlers ) ? $handlers : $this->handlers;

		if ( ! isset( $handlers[ $sub_action ] ) ) {
			wp_send_json_error(
				[
					'code'    => 'unknown_action',
					'message' => __( 'Unknown sub-action.', 'fanxie-wp-core' ),
				],
				404
			);
		}

		$entry = $handlers[ $sub_action ];
		$cap   = $entry['cap'] ?? null;
		if ( is_string( $cap ) && '' !== $cap && ! current_user_can( $cap ) ) {
			wp_send_json_error(
				[
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to perform this action.', 'fanxie-wp-core' ),
				],
				403
			);
		}

		try {
			$result = call_user_func( $entry['callback'], $payload );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				[
					'code'    => 'handler_exception',
					'message' => __( 'An unexpected error occurred.', 'fanxie-wp-core' ),
				],
				500
			);
		}

		if ( $result instanceof WP_Error ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				],
				400
			);
		}

		if ( ! is_array( $result ) ) {
			$result = [];
		}

		wp_send_json_success( $result );
	}

	/**
	 * Built-in proof-of-life handler used by the frontend to smoke-test the wiring.
	 *
	 * @return array<string, mixed>
	 */
	public function handle_ping(): array {
		return [
			'pong' => true,
			'time' => time(),
		];
	}

	/**
	 * Read the request payload, preferring a JSON body over form-encoded POST.
	 *
	 * When the request content type is `application/json`, the raw body is
	 * decoded and returned as the payload. Otherwise the unslashed `$_POST`
	 * is used — preserving the legacy form-encoded path for third-party
	 * integrations that haven't migrated.
	 *
	 * @return array<string, mixed>
	 */
	private function read_payload(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) )
			: '';
		$is_json      = false !== stripos( $content_type, 'application/json' );

		if ( $is_json ) {
			$raw = file_get_contents( 'php://input' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified downstream in dispatch().
		return wp_unslash( $_POST );
	}
}
