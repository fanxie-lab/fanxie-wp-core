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
 * Optimizer, …). Fine-grained feature toggles live in each module's own
 * settings — there is no module-level "enabled" flag. The registry calls
 * `register_hooks()` on every registered module and runtime emitters consult
 * individual settings to decide what, if anything, to do.
 *
 * Options layout per module:
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
	 * Register WordPress hooks for the module.
	 *
	 * Called once per module at boot time. Implementations wire both the
	 * admin surface (AJAX / REST endpoints, custom-table install, etc.) and
	 * the runtime-effect hooks (`send_headers`, cron schedules, report
	 * collectors, …). Runtime emitters are themselves responsible for
	 * consulting module settings to determine whether they should do any
	 * work — there is no module-level enabled gate.
	 */
	abstract public function register_hooks(): void;

	/**
	 * Default configuration returned as an associative array.
	 *
	 * May be arbitrarily nested — the schema declared in `get_settings_fields()`
	 * uses dot-path ids to address nested values.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_default_config(): array;

	/**
	 * Settings schema consumed by the admin UI and `update_config()`.
	 *
	 * Each entry is shaped:
	 *   [
	 *     'id'                 => string,   // dot-path into the nested config, e.g. 'headers.hsts.enabled'
	 *     'label'              => string,   // translated, human-readable
	 *     'type'               => string,   // 'toggle' | 'text' | 'textarea' | 'select' | 'number'
	 *     'default'            => mixed,    // mirrors the value at that path in get_default_config()
	 *     'sanitizer'          => string,   // one of: 'bool', 'key', 'text', 'textarea', 'url', 'int', 'absint'
	 *     'sanitizer_callback'?=> callable, // optional — `fn( mixed $value ): mixed`
	 *                                       //   When present, takes precedence over `sanitizer`.
	 *                                       //   Use this for structured (array-of-arrays) values
	 *                                       //   that scalar sanitisers can't express.
	 *     'options'?           => array,    // for 'select' — [value => label]
	 *     'help'?              => string,   // optional translator-aware help text
	 *   ]
	 *
	 * Keys are dot-paths: e.g. `'headers.hsts.enabled'` addresses
	 * `$config['headers']['hsts']['enabled']`. Single-segment ids remain flat
	 * and behave exactly as before.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function get_settings_fields(): array;

	/**
	 * Retrieve the stored config merged with defaults, sanitised per schema.
	 *
	 * Uses `array_replace_recursive` so nested sub-keys absent from storage
	 * fall back to their defaults rather than losing the whole parent branch.
	 *
	 * Fields that declare a `sanitizer_callback` own their whole structured
	 * value (arrays of arrays, etc.) — recursive merging would silently
	 * re-introduce default entries the user deliberately removed, so we skip
	 * those paths when priming the merge baseline.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		$stored = get_option( $this->settings_option_key(), [] );
		$stored = is_array( $stored ) ? $stored : [];

		return $this->sanitize_config( $this->merge_with_defaults( $stored ) );
	}

	/**
	 * Persist a new configuration after schema-driven sanitisation.
	 *
	 * @param array<string, mixed> $config Raw config payload.
	 * @return bool True on success, false when nothing changed.
	 */
	public function update_config( array $config ): bool {
		$sanitised = $this->sanitize_config( $this->merge_with_defaults( $config ) );

		return (bool) update_option( $this->settings_option_key(), $sanitised );
	}

	/**
	 * Merge a config payload over defaults recursively, but stop recursion at
	 * paths that are owned by a `sanitizer_callback` so structured values are
	 * replaced wholesale (no default leakage into array-shaped fields).
	 *
	 * @param array<string, mixed> $config Caller payload.
	 * @return array<string, mixed>
	 */
	private function merge_with_defaults( array $config ): array {
		$defaults = $this->get_default_config();

		// Strip sanitizer_callback paths from defaults before the recursive
		// merge — the caller fully owns those sub-trees. If they omit the
		// path, `sanitize_config()` still falls back to the schema default.
		foreach ( $this->get_settings_fields() as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['sanitizer_callback'] ) ) {
				continue;
			}
			$this->unset_at_path( $defaults, (string) $field['id'] );
		}

		return array_replace_recursive( $defaults, $config );
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
	 * Walks the schema, reads each field from `$config` at its dot-path id
	 * (falling back to the schema default), applies the declared sanitiser
	 * (or `sanitizer_callback` when present), then writes the clean value
	 * back at the same path in a fresh structure. Unknown keys are dropped;
	 * paths missing from the input inherit their schema default.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	protected function sanitize_config( array $config ): array {
		$clean  = [];
		$fields = $this->get_settings_fields();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}

			$path    = (string) $field['id'];
			$default = $field['default'] ?? null;
			$raw     = $this->get_at_path( $config, $path, $default );

			if ( isset( $field['sanitizer_callback'] ) && is_callable( $field['sanitizer_callback'] ) ) {
				$value = ( $field['sanitizer_callback'] )( $raw );
			} elseif ( isset( $field['sanitizer'] ) ) {
				$value = $this->apply_sanitizer( (string) $field['sanitizer'], $raw );
			} else {
				continue;
			}

			$this->set_at_path( $clean, $path, $value );
		}

		return $clean;
	}

	/**
	 * Resolve a sanitizer identifier to an actual value.
	 *
	 * Structured values (arrays of arrays, etc.) should use a schema
	 * `sanitizer_callback` rather than a string identifier — this method is
	 * scalar-only by contract.
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

	/**
	 * Read a value at a dot-path in a nested array, falling back to a default.
	 *
	 * @param array<string, mixed> $source        Source array to read from.
	 * @param string               $path          Dot-separated key path (e.g. 'headers.hsts.enabled').
	 * @param mixed                $fallback      Value returned when any segment is missing.
	 * @return mixed
	 */
	private function get_at_path( array $source, string $path, mixed $fallback ): mixed {
		$current = $source;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $fallback;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * Write a value at a dot-path, creating intermediate arrays as needed.
	 *
	 * @param array<string, mixed> $target Target array, mutated in place.
	 * @param string               $path   Dot-separated key path.
	 * @param mixed                $value  Value to place at the path.
	 */
	private function set_at_path( array &$target, string $path, mixed $value ): void {
		$segments = explode( '.', $path );
		$cursor   = &$target;
		$last     = count( $segments ) - 1;

		foreach ( $segments as $i => $segment ) {
			if ( $i === $last ) {
				$cursor[ $segment ] = $value;
				return;
			}
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor = &$cursor[ $segment ];
		}
	}

	/**
	 * Remove the leaf value at a dot-path, if present. Intermediate arrays are
	 * left in place so sibling entries (addressed by other schema fields)
	 * continue to round-trip through the default merge.
	 *
	 * @param array<string, mixed> $target Target array, mutated in place.
	 * @param string               $path   Dot-separated key path.
	 */
	private function unset_at_path( array &$target, string $path ): void {
		$segments = explode( '.', $path );
		$cursor   = &$target;
		$last     = count( $segments ) - 1;

		foreach ( $segments as $i => $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return;
			}
			if ( $i === $last ) {
				unset( $cursor[ $segment ] );
				return;
			}
			$cursor = &$cursor[ $segment ];
		}
	}
}
