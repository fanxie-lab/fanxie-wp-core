<?php
/**
 * Registry for Fanxie WP Core modules.
 *
 * @package FanxieLab\WPCore\Modules
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks registered modules and boots the enabled subset.
 *
 * At Phase 0.1 the registry is empty — concrete modules plug in from Phase 1
 * onward, each calling `ModuleRegistry::register()` with its own descriptor.
 */
final class ModuleRegistry {

	/**
	 * Registered modules indexed by their `id()`.
	 *
	 * @var array<string, ModuleBase>
	 */
	private array $modules = [];

	/**
	 * Register a module descriptor.
	 *
	 * Later registrations for the same id overwrite earlier ones. We fire the
	 * action hook *after* storage so listeners observe the final state.
	 *
	 * @param ModuleBase $module Module instance.
	 */
	public function register( ModuleBase $module ): void {
		$this->modules[ $module->id() ] = $module;

		/**
		 * Action: fanxie_wp_core/module/registered
		 *
		 * Fires after a module has been added to the registry.
		 *
		 * @param ModuleBase $module The freshly-registered module.
		 */
		do_action( 'fanxie_wp_core/module/registered', $module );
	}

	/**
	 * All registered modules, regardless of enabled state.
	 *
	 * @return array<string, ModuleBase>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * The subset of registered modules whose `is_enabled()` returns true.
	 *
	 * @return array<string, ModuleBase>
	 */
	public function enabled(): array {
		return array_filter(
			$this->modules,
			static fn ( ModuleBase $module ): bool => $module->is_enabled()
		);
	}

	/**
	 * Retrieve a module by id.
	 *
	 * @param string $id Module id (`self::id()`).
	 */
	public function get( string $id ): ?ModuleBase {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Boot every enabled module by calling its `register_hooks()`.
	 */
	public function boot(): void {
		foreach ( $this->enabled() as $module ) {
			$module->register_hooks();
		}
	}
}
