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
use FanxieLab\WPCore\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\WPCore\Modules\Hardening\Hardening;
use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginProtection;
use FanxieLab\WPCore\Modules\ModuleRegistry;
use FanxieLab\WPCore\Modules\SecurityHeaders\SecurityHeaders;
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository;

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
	public const DB_VERSION = '2';

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

		// Ensure module-owned tables exist before any hooks fire. The same
		// routine backs the runtime upgrade check (see self::maybe_upgrade()).
		self::install_tables();

		// Hardening: drop protection files into uploads (idempotent).
		// Uses the current option value so a user who's toggled either uploads
		// flag off on a previous activation doesn't get the files re-created.
		$hardening_settings = get_option( 'fanxie_wp_core_hardening_settings', [] );
		$hardening_config   = is_array( $hardening_settings ) ? $hardening_settings : [];
		if (
			! isset( $hardening_config['uploads']['drop_index'] )
			|| ! empty( $hardening_config['uploads']['drop_index'] )
			|| ! isset( $hardening_config['uploads']['block_php_execution'] )
			|| ! empty( $hardening_config['uploads']['block_php_execution'] )
		) {
			( new \FanxieLab\WPCore\Modules\Hardening\UploadsProtector( $hardening_config ) )->ensure_protection( $hardening_config );
		}

		// Hardening: install the root `.htaccess` block for `/readme.html` and
		// `/license.txt` when the toggle is on (defaults to on for fresh installs).
		$block_readme_license = ! isset( $hardening_config['version_hiding']['block_readme_license'] )
			|| ! empty( $hardening_config['version_hiding']['block_readme_license'] );
		if ( $block_readme_license ) {
			( new \FanxieLab\WPCore\Modules\Hardening\Runtime\RootHtaccessWriter() )->ensure_readme_license_block();
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
	 * Install / upgrade every module-owned custom table.
	 *
	 * Each repository's `install()` is itself version-gated (per-table
	 * `SCHEMA_VERSION_OPTION`) and idempotent, so this is safe to call on both
	 * activation and the runtime upgrade check without duplicating work.
	 */
	private static function install_tables(): void {
		( new ViolationRepository() )->install();
		( new LoginLogRepository() )->install();
		( new BanRepository() )->install();
	}

	/**
	 * Self-healing, version-gated schema upgrade check.
	 *
	 * Custom tables are created on `activate()`, but activation does not run when
	 * an already-active plugin is updated to new code — so a table added in a
	 * later release would never exist on upgraded sites. This closes that gap: on
	 * every boot it compares the stored DB version against {@see self::DB_VERSION}
	 * and, when they differ (including a brand-new/upgraded site where the option
	 * is unset and `get_option()` returns `false`), (re)installs the tables and
	 * records the new version. When the versions already match it is a single
	 * `get_option()` and nothing else — `install_tables()` is never run
	 * unconditionally.
	 */
	public function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
		$registry    = new ModuleRegistry();
		$ajax_router = new AjaxRouter();

		$security_headers = new SecurityHeaders( $ajax_router );
		$registry->register( $security_headers );

		$hardening = new Hardening( $ajax_router );
		$registry->register( $hardening );

		$login_protection = new LoginProtection( $ajax_router );
		$registry->register( $login_protection );

		$environment_health = new EnvironmentHealth( $ajax_router );
		$registry->register( $environment_health );

		$settings_page = new SettingsPage( $registry );

		$this->services[ ModuleRegistry::class ]  = $registry;
		$this->services[ AjaxRouter::class ]      = $ajax_router;
		$this->services[ SettingsPage::class ]    = $settings_page;
		$this->services[ SecurityHeaders::class ] = $security_headers;
		$this->services[ Hardening::class ]       = $hardening;
		$this->services[ LoginProtection::class ] = $login_protection;

		$this->services[ EnvironmentHealth::class ] = $environment_health;
	}

	/**
	 * Wire WordPress hooks owned by the core bootstrap.
	 */
	private function register_hooks(): void {
		// Self-heal the schema on upgraded sites. `boot()` already runs on
		// `plugins_loaded`, so re-hooking `maybe_upgrade` onto the same hook is
		// unreliable — a callback added at the priority currently being dispatched
		// is not guaranteed to fire this request (PHP iterates a copy of the
		// priority bucket). Calling it directly here runs it exactly once per
		// request, synchronously during boot and well before any login handling,
		// which is precisely the guarantee this migration needs.
		$this->maybe_upgrade();

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
