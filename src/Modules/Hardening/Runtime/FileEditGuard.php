<?php
/**
 * Runtime `file_mod_allowed` guard for theme + plugin editing.
 *
 * @package FanxieLab\WPCore\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime fallback when `DISALLOW_FILE_EDIT` is not defined in `wp-config.php`.
 *
 * The constant is the preferred path (earlier lifecycle, broader coverage).
 * This filter plugs the theme / plugin editors the same way WP's own
 * `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` handling does, without the
 * user having to touch `wp-config.php`.
 */
final class FileEditGuard {

	/**
	 * Contexts we lock down. WordPress gates BOTH the theme editor and the
	 * plugin editor through a single `file_mod_allowed` call with the context
	 * `capability_edit_themes` (see wp-includes/capabilities.php, the
	 * edit_files / edit_plugins / edit_themes meta-cap branch).
	 * `capability_edit_plugins` is included defensively in case a plugin or a
	 * future core version gates the plugin editor separately. We deliberately do
	 * NOT block `capability_update_core` so installs/updates keep working — this
	 * mirrors DISALLOW_FILE_EDIT, not the broader DISALLOW_FILE_MODS.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_CONTEXTS = [
		'capability_edit_themes',
		'capability_edit_plugins',
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config ) {}

	/**
	 * Attach the filter when the runtime-enforce toggle is on.
	 */
	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'file_mod_allowed', [ $this, 'filter_file_mod_allowed' ], 10, 2 );
	}

	/**
	 * Return `false` for the theme/plugin edit contexts.
	 *
	 * @param mixed  $allowed Current decision.
	 * @param string $context Context slug from WordPress core.
	 */
	public function filter_file_mod_allowed( mixed $allowed, string $context ): bool {
		if ( in_array( $context, self::BLOCKED_CONTEXTS, true ) ) {
			return false;
		}

		return (bool) $allowed;
	}

	/**
	 * Is `file_editing.runtime_enforce` on?
	 */
	private function is_enabled(): bool {
		return (bool) ( $this->config['file_editing']['runtime_enforce'] ?? false );
	}
}
