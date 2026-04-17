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
	 * Terminates via `wp_send_json_success` / `wp_send_json_error`.
	 */
	public function dispatch(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error(
				[
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to perform this action.', 'fanxie-wp-core' ),
				],
				403
			);
		}

		$raw_sub_action = isset( $_POST['_action'] ) ? wp_unslash( $_POST['_action'] ) : '';
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

		$payload = wp_unslash( $_POST );

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
}
