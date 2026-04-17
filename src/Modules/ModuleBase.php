<?php
/**
 * Abstract base class for every Fanxie WP Core module.
 *
 * @package FanxieLab\WPCore\Modules
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every module must implement.
 *
 * A "module" is a self-contained feature (Security Headers, Hardening, Media
 * Optimizer, …) whose runtime cost is zero when disabled — meaning no hooks
 * registered and nothing but the descriptor class loaded. The registry calls
 * `register_hooks()` only when `is_enabled()` returns true.
 *
 * Options layout per module:
 *   - `fanxie_wp_core_<id>_enabled`  — bool-ish string ('yes' / absent).
 *   - `fanxie_wp_core_<id>_settings` — array of module-specific config.
 *
 * Settings schema is declared via `get_settings_fields()`. Each field carries
 * a `sanitizer` key identifying a sanitizer callback the registry understands,
 * keeping sanitisation declarative and consistent across modules.
 */
abstract class ModuleBase {

	/**
	 * Unique module slug used in option keys, hook names, and Vue stores.
	 *
	 * Must be kebab-case, e.g. `security-headers`.
	 */
	abstract public function id(): string;

	/**
	 * Translatable display name for the module.
	 */
	abstract public function name(): string;

	/**
	 * Register WordPress hooks. Called only when the module is enabled.
	 */
	abstract public function register_hooks(): void;

	/**
	 * Default configuration returned as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_default_config(): array;

	/**
	 * Settings schema consumed by the admin UI and `update_config()`.
	 *
	 * Each entry is shaped:
	 *   [
	 *     'id'        => string,   // matches a key in get_default_config()
	 *     'label'     => string,   // translated, human-readable
	 *     'type'      => string,   // 'toggle' | 'text' | 'textarea' | 'select' | 'number'
	 *     'default'   => mixed,    // mirrors get_default_config() value
	 *     'sanitizer' => string,   // one of: 'bool', 'key', 'text', 'textarea', 'url', 'int', 'absint'
	 *     'options'?  => array,    // for 'select' — [value => label]
	 *     'help'?     => string,   // optional translator-aware help text
	 *   ]
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function get_settings_fields(): array;

	/**
	 * Whether the module is currently enabled.
	 *
	 * Default: false — every module opts in explicitly.
	 */
	public function is_enabled(): bool {
		return 'yes' === get_option( $this->enabled_option_key(), 'no' );
	}

	/**
	 * Retrieve the stored config merged with defaults, sanitised per schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		$defaults = $this->get_default_config();
		$stored   = get_option( $this->settings_option_key(), [] );
		$stored   = is_array( $stored ) ? $stored : [];

		$merged = array_replace( $defaults, $stored );

		return $this->sanitize_config( $merged );
	}

	/**
	 * Persist a new configuration after schema-driven sanitisation.
	 *
	 * @param array<string, mixed> $config Raw config payload.
	 * @return bool True on success, false when nothing changed.
	 */
	public function update_config( array $config ): bool {
		$sanitised = $this->sanitize_config( array_replace( $this->get_default_config(), $config ) );
		return (bool) update_option( $this->settings_option_key(), $sanitised );
	}

	/**
	 * Option name storing the enable flag.
	 */
	protected function enabled_option_key(): string {
		return 'fanxie_wp_core_' . $this->id() . '_enabled';
	}

	/**
	 * Option name storing the module's settings array.
	 */
	protected function settings_option_key(): string {
		return 'fanxie_wp_core_' . $this->id() . '_settings';
	}

	/**
	 * Sanitise a config array against the schema declared in get_settings_fields().
	 *
	 * Unknown keys are dropped. Missing keys fall back to schema defaults.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	protected function sanitize_config( array $config ): array {
		$clean  = [];
		$fields = $this->get_settings_fields();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['sanitizer'] ) ) {
				continue;
			}

			$id        = (string) $field['id'];
			$sanitizer = (string) $field['sanitizer'];
			$default   = $field['default'] ?? null;
			$value     = array_key_exists( $id, $config ) ? $config[ $id ] : $default;

			$clean[ $id ] = $this->apply_sanitizer( $sanitizer, $value );
		}

		return $clean;
	}

	/**
	 * Resolve a sanitizer identifier to an actual value.
	 *
	 * @param string $sanitizer Sanitizer identifier.
	 * @param mixed  $value     Raw value.
	 * @return mixed
	 */
	protected function apply_sanitizer( string $sanitizer, mixed $value ): mixed {
		return match ( $sanitizer ) {
			'bool'     => (bool) $value,
			'key'      => sanitize_key( (string) $value ),
			'text'     => sanitize_text_field( (string) $value ),
			'textarea' => sanitize_textarea_field( (string) $value ),
			'url'      => esc_url_raw( (string) $value ),
			'int'      => (int) $value,
			'absint'   => absint( $value ),
			default    => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
		};
	}
}
