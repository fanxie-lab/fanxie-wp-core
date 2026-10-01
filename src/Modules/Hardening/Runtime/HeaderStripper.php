<?php
/**
 * Strip the `X-Powered-By` response header.
 *
 * @package FanxieLab\Warden\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Removes `X-Powered-By` on every request.
 *
 * The header is typically injected by PHP itself (via `expose_php` in
 * `php.ini`) or by the web server. We call `header_remove()` late in the
 * `send_headers` cycle — if the web server or PHP config injects it at a
 * layer we can't touch, `StatusInspector` surfaces a warning in the admin UI
 * with copy-paste remediation snippets.
 */
final class HeaderStripper {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach hooks if the toggle is on.
	 */
	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Priority 1 so we run before other `send_headers` consumers — the
		// check is cheap (two function calls) and getting in early maximises
		// the chance that `header_remove()` completes before output starts.
		add_action( 'send_headers', [ $this, 'strip' ], 1 );
	}

	/**
	 * Remove the header if PHP has it in the outgoing buffer.
	 */
	public function strip(): void {
		if ( ! function_exists( 'header_remove' ) || headers_sent() ) {
			return;
		}

		header_remove( 'X-Powered-By' );
	}

	/**
	 * Is `version_hiding.remove_powered_by` on?
	 */
	private function is_enabled(): bool {
		return (bool) ( $this->config['version_hiding']['remove_powered_by'] ?? true );
	}
}
