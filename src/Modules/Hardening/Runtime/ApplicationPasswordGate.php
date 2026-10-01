<?php
/**
 * Optionally disable WordPress 5.6+ Application Passwords.
 *
 * @package FanxieLab\Warden\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Application Passwords are an authentication vector most sites don't use;
 * flipping them off via `wp_is_application_passwords_available` removes
 * an entire credential surface when nobody relies on it.
 *
 * The UI gates the toggle behind "no AP exist" — see `StatusInspector` —
 * but even if a stale setting slips through we honour the config here:
 * operators can temporarily re-enable by flipping the toggle off.
 */
final class ApplicationPasswordGate {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach the filter when disable is on.
	 */
	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	}

	/**
	 * Is `application_passwords.disable` on?
	 */
	private function is_enabled(): bool {
		return (bool) ( $this->config['application_passwords']['disable'] ?? false );
	}
}
