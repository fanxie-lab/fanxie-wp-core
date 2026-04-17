<?php
/**
 * Core plugin bootstrap class.
 *
 * @package FanxieLab\WPCore
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Admin\SettingsPage;
use FanxieLab\WPCore\Modules\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap and lightweight service container.
 *
 * The class is singleton-ish *only* at the bootstrap layer: `boot()` is the
 * single entry point called from `fanxie-wp-core.php` on `plugins_loaded`.
 * Consumers should inject collaborators via the constructor rather than
 * reaching back into the container.
 */
final class Plugin {

	/**
	 * Option key storing the schema / DB version for future migrations.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'fanxie_wp_core_db_version';

	/**
	 * Current DB schema version. Bump when custom tables / option shapes change.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1';

	/**
	 * Custom capability that gates every admin action.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_fanxie_wp_core';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service bag — a deliberately tiny DI container.
	 *
	 * Keyed by service id (typically the FQCN), value is the instantiated
	 * service. We keep it closed to this class: nothing outside the bootstrap
	 * should be swapping services at runtime.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Private — use Plugin::boot() instead.
	 */
	private function __construct() {}

	/**
	 * Bootstrap the plugin.
	 *
	 * Idempotent: calling twice is a no-op.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_services();
		self::$instance->register_hooks();
	}

	/**
	 * Activation handler — runs once on plugin activation.
	 *
	 * Responsibilities:
	 *   - Grant the custom capability to administrators.
	 *   - Store the DB schema version.
	 *   - Flush rewrite rules (future modules register custom endpoints).
	 */
	public static function activate(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role && ! $administrator->has_cap( self::CAPABILITY ) ) {
			$administrator->add_cap( self::CAPABILITY );
		}

		if ( false === get_option( self::DB_VERSION_OPTION ) ) {
			add_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} else {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation handler — non-destructive.
	 *
	 * Only unschedules our cron jobs and clears transients. Options and custom
	 * tables are preserved so that re-activation restores the previous state.
	 * Full cleanup is the uninstall handler's job.
	 */
	public static function deactivate(): void {
		// Clear any scheduled hook we own (`fanxie_wp_core_*`).
		$cron      = _get_cron_array();
		$to_cancel = [];

		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( array_keys( $events ) as $hook ) {
					if ( is_string( $hook ) && str_starts_with( $hook, 'fanxie_wp_core_' ) ) {
						$to_cancel[ $hook ] = true;
					}
				}
			}
		}

		foreach ( array_keys( $to_cancel ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Retrieve a registered service.
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $id Service id (FQCN). PHP-level type is `string`; the
	 *                            generic form is a PHPStan hint so callers that pass
	 *                            a FQCN get a typed return.
	 *
	 * @return T|null Instance stored under the given id, or null when unset.
	 *
	 * phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- class-string<T> narrows the plain `string` runtime hint for PHPStan generics; the types are compatible.
	 */
	public function get( string $id ): ?object {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline PHPStan `@var` narrowing tag; a short description would be noise.
		/** @var T|null $service */
		$service = $this->services[ $id ] ?? null;
		return $service;
	}

	/**
	 * Get the singleton instance. Prefer constructor injection when possible.
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Instantiate the core services and store them in the container.
	 */
	private function register_services(): void {
		$registry      = new ModuleRegistry();
		$ajax_router   = new AjaxRouter();
		$settings_page = new SettingsPage( $registry );

		$this->services[ ModuleRegistry::class ] = $registry;
		$this->services[ AjaxRouter::class ]     = $ajax_router;
		$this->services[ SettingsPage::class ]   = $settings_page;
	}

	/**
	 * Wire WordPress hooks owned by the core bootstrap.
	 */
	private function register_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'boot_modules' ], 5 );

		$settings_page = $this->services[ SettingsPage::class ];
		if ( $settings_page instanceof SettingsPage ) {
			$settings_page->register_hooks();
		}

		$ajax_router = $this->services[ AjaxRouter::class ];
		if ( $ajax_router instanceof AjaxRouter ) {
			$ajax_router->register_hooks();
		}
	}

	/**
	 * Load the plugin text domain.
	 *
	 * Fires on `init` (WordPress 6.7+ warns if registered earlier).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'fanxie-wp-core',
			false,
			dirname( plugin_basename( FANXIE_WP_CORE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Boot every enabled module through the registry.
	 */
	public function boot_modules(): void {
		$registry = $this->services[ ModuleRegistry::class ];
		if ( $registry instanceof ModuleRegistry ) {
			$registry->boot();
		}
	}
}
