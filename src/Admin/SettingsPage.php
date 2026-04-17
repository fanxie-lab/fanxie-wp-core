<?php
/**
 * Admin settings page — hosts the Vue 3 single-page app.
 *
 * @package FanxieLab\WPCore\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Admin;

use FanxieLab\WPCore\Modules\ModuleRegistry;
use FanxieLab\WPCore\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and hydrates the Vue SPA mount node.
 *
 * Enqueue contract (do NOT rename without coordinating with the frontend
 * agent — the Vite build ships files at exactly these paths):
 *
 *   - Script handle: `fanxie-wp-core-admin`  → assets/admin/dist/admin.js
 *   - Style handle:  `fanxie-wp-core-admin`  → assets/admin/dist/admin.css
 *
 * The bootstrap object exposed at `window.fanxieWPCore` (shape is frozen;
 * filter `fanxie_wp_core/admin/bootstrap` allows consumers to extend it):
 *
 *   {
 *     version, ajaxUrl, adminUrl, restUrl, nonce, assetsUrl,
 *     user: { id, caps },
 *     modules: [],
 *     i18n: { locale },
 *   }
 */
final class SettingsPage {

	/**
	 * WordPress-registered hook suffix for the settings page.
	 *
	 * Populated inside `register_menu()` via the return value of
	 * `add_options_page()` — used by `enqueue_assets()` to scope enqueues
	 * to this screen only.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Menu slug — kept public so other parts of the plugin can reference it
	 * when building admin URLs.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'fanxie-wp-core';

	/**
	 * Asset handle used for both the JS and CSS registrations.
	 *
	 * @var string
	 */
	public const ASSET_HANDLE = 'fanxie-wp-core-admin';

	/**
	 * Asset handle for Vite's HMR client (dev mode only).
	 *
	 * @var string
	 */
	public const VITE_CLIENT_HANDLE = 'fanxie-wp-core-vite-client';

	/**
	 * Relative path (from plugin root) to the Vite "hot file" marker.
	 *
	 * Written by the dev server on start, removed on shutdown — presence of
	 * this file is the single source of truth for "dev mode".
	 *
	 * @var string
	 */
	private const HOT_FILE_RELATIVE_PATH = 'assets/admin/.vite-hot';

	/**
	 * Whether the `script_loader_tag` filter for Vite module scripts has
	 * already been registered. Prevents double-registration across multiple
	 * `enqueue_assets()` calls in the same request.
	 *
	 * @var bool
	 */
	private static bool $vite_tag_filter_registered = false;

	/**
	 * Constructor.
	 *
	 * @param ModuleRegistry $registry Injected so the bootstrap payload can
	 *                                 expose module descriptors to the SPA.
	 */
	public function __construct( private readonly ModuleRegistry $registry ) {}

	/**
	 * Register all admin hooks owned by this class.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the settings page under **Settings → Fanxie WP Core**.
	 */
	public function register_menu(): void {
		$hook = add_options_page(
			esc_html__( 'Fanxie WP Core', 'fanxie-wp-core' ),
			esc_html__( 'Fanxie WP Core', 'fanxie-wp-core' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Render the mount node for the Vue app plus a noscript fallback.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fanxie-wp-core' ) );
		}

		$hot_url       = $this->hot_server_url();
		$is_dev        = null !== $hot_url;
		$build_missing = ! $is_dev && ! $this->build_exists();

		$bootstrap_json = wp_json_encode( $this->get_bootstrap() );
		// Defensive fallback: if JSON encoding fails, emit an empty object so
		// the SPA's `window.fanxieWPCore` reference never goes undefined.
		$bootstrap_js = 'window.fanxieWPCore = ' . ( false === $bootstrap_json ? '{}' : $bootstrap_json ) . ';';
		?>
		<div class="wrap">
			<?php
			/*
			 * Emit the `window.fanxieWPCore` hydration bootstrap inline here,
			 * rather than attaching it to a script handle via
			 * `wp_add_inline_script( ..., 'before' )`.
			 *
			 * Rationale: our `script_loader_tag` filter rewrites the enqueued
			 * module tags to add `type="module" crossorigin`. WordPress passes
			 * the filter a `$tag` value that already has inline-before + base
			 * tag + inline-after concatenated into a single string, so any
			 * wholesale rewrite silently drops the bootstrap. Emitting the
			 * bootstrap here guarantees it is in the DOM before any enqueued
			 * script runs, independent of script strategy or tag-filter
			 * rewrites applied to the handles.
			 *
			 * See: https://make.wordpress.org/core/2023/07/14/registering-scripts-with-async-and-defer-attributes-in-wordpress-6-3/
			 *
			 * Future migration: when the plugin's minimum WordPress version is
			 * bumped to 6.5+, the dev-mode enqueues should move to
			 * `wp_enqueue_script_module()`. At that point this manual emission
			 * can optionally be kept or replaced with module-level mechanisms.
			 */
			// Emitted here so the bootstrap is guaranteed to be in the DOM before any enqueued script runs, independent of strategy or tag-filter rewrites applied to the script handles.
			echo wp_get_inline_script_tag( $bootstrap_js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() is the proper helper; it handles attributes and wraps contents, and wp_json_encode() escapes slashes to prevent `</script>` injection.
			?>

			<h1 class="screen-reader-text"><?php echo esc_html__( 'Fanxie WP Core', 'fanxie-wp-core' ); ?></h1>

			<?php if ( $is_dev ) : ?>
				<div class="notice notice-info" style="margin: 0 0 16px;">
					<p>
						<strong><?php echo esc_html__( 'Vite dev server connected.', 'fanxie-wp-core' ); ?></strong>
						<?php
						printf(
							/* translators: %s: Vite dev server URL (e.g. http://localhost:5173) */
							esc_html__( 'HMR active at %s.', 'fanxie-wp-core' ),
							'<code>' . esc_html( $hot_url ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $build_missing ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo esc_html__(
							'Admin UI build missing — run `npm run build` in `assets/admin/`.',
							'fanxie-wp-core'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div id="fanxie-wp-core-admin" data-version="<?php echo esc_attr( FANXIE_WP_CORE_VERSION ); ?>">
				<noscript>
					<p>
						<?php
						echo esc_html__(
							'Fanxie WP Core requires JavaScript to configure. See the documentation for alternative options.',
							'fanxie-wp-core'
						);
						?>
					</p>
				</noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the SPA assets — scoped tightly to our settings screen.
	 *
	 * Two branches:
	 *   - Dev (Vite dev server running, hot-file present): enqueue the HMR
	 *     client and the TypeScript entry directly from the dev server, as
	 *     ES modules. No CSS — Vite injects styles via JS in dev.
	 *   - Prod: enqueue the built `dist/admin.js` + `dist/admin.css` bundle.
	 *
	 * The `window.fanxieWPCore` bootstrap is emitted directly inside
	 * `render()` — see the docblock there for the rationale.
	 *
	 * @param string $hook_suffix Current admin screen's hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$hot_url = $this->hot_server_url();

		if ( null !== $hot_url ) {
			$this->enqueue_dev_assets( $hot_url );
			return;
		}

		// Prod: if the build hasn't been produced yet, bail — the admin page
		// already shows a visible notice pointing to `npm run build`.
		if ( ! $this->build_exists() ) {
			return;
		}

		$dist_url = FANXIE_WP_CORE_URL . 'assets/admin/dist/';

		wp_enqueue_style(
			self::ASSET_HANDLE,
			$dist_url . 'admin.css',
			[],
			FANXIE_WP_CORE_VERSION
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			$dist_url . 'admin.js',
			[],
			FANXIE_WP_CORE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}

	/**
	 * Enqueue the Vite dev server scripts (HMR client + TS entry).
	 *
	 * No version string — dev assets must never be cached by the browser.
	 * No CSS enqueue — Vite injects styles at runtime during dev.
	 *
	 * @param string $hot_url Trimmed dev server base URL, e.g. `http://localhost:5173`.
	 */
	private function enqueue_dev_assets( string $hot_url ): void {
		// phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion -- dev-mode only; Vite serves source files that MUST NOT be cached.
		wp_enqueue_script(
			self::VITE_CLIENT_HANDLE,
			$hot_url . '/@vite/client',
			[],
			null,
			[ 'in_footer' => true ]
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			$hot_url . '/src/main.ts',
			[ self::VITE_CLIENT_HANDLE ],
			null,
			[ 'in_footer' => true ]
		);
		// phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion

		$this->register_vite_tag_filter();
	}

	/**
	 * Register the `script_loader_tag` filter that rewrites our two dev-mode
	 * handles as native ES modules (`type="module" crossorigin`).
	 *
	 * Idempotent — only registers on first call per request.
	 */
	private function register_vite_tag_filter(): void {
		if ( self::$vite_tag_filter_registered ) {
			return;
		}

		self::$vite_tag_filter_registered = true;

		/*
		 * Rewrite the enqueued module tags to add `type="module" crossorigin`.
		 *
		 * Since the `window.fanxieWPCore` bootstrap is no longer attached to
		 * these handles via `wp_add_inline_script( ..., 'before' )` — it is
		 * emitted directly in `render()` instead — there is no inline-before
		 * payload that a wholesale rewrite would drop. The filter can safely
		 * return a freshly-built tag.
		 */
		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle, $src ) {
				if ( ! in_array( $handle, [ self::VITE_CLIENT_HANDLE, self::ASSET_HANDLE ], true ) ) {
					return $tag;
				}

				// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- rewriting an already-enqueued handle's tag to add `type="module" crossorigin` for Vite HMR; the script was registered via wp_enqueue_script() in enqueue_dev_assets().
				$module_tag = sprintf(
					'<script type="module" crossorigin src="%1$s" id="%2$s-js"></script>' . "\n",
					esc_url( (string) $src ),
					esc_attr( (string) $handle )
				);
				// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

				return $module_tag;
			},
			10,
			3
		);
	}

	/**
	 * Read the Vite dev server URL from the "hot file" marker, if present.
	 *
	 * Contract with the frontend agent: when the dev server is running,
	 * `assets/admin/.vite-hot` contains a single line — the dev server base
	 * URL (e.g. `http://localhost:5173`). The file is removed on shutdown.
	 *
	 * Robust against a missing or unreadable file: silently returns null.
	 *
	 * @return string|null Trimmed URL, or null when no dev server is running.
	 */
	private function hot_server_url(): ?string {
		$path = FANXIE_WP_CORE_PATH . self::HOT_FILE_RELATIVE_PATH;

		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- local filesystem read of a dev-only marker; WP_Filesystem is overkill here and `@` silences a benign race (file may vanish between is_readable() and read).
		$contents = @file_get_contents( $path );

		if ( false === $contents ) {
			return null;
		}

		$url = trim( $contents );

		return '' === $url ? null : $url;
	}

	/**
	 * Build the bootstrap payload mirrored into `window.fanxieWPCore`.
	 *
	 * The returned shape is a public contract with the frontend agent — do
	 * not remove keys. Extend via the `fanxie_wp_core/admin/bootstrap` filter.
	 *
	 * @return array<string, mixed>
	 */
	private function get_bootstrap(): array {
		$bootstrap = [
			'version'   => FANXIE_WP_CORE_VERSION,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'adminUrl'  => admin_url(),
			'restUrl'   => esc_url_raw( rest_url( 'fanxie-wp-core/v1/' ) ),
			'nonce'     => wp_create_nonce( 'fanxie_wp_core_admin' ),
			'assetsUrl' => FANXIE_WP_CORE_URL . 'assets/admin/dist/',
			'user'      => [
				'id'   => get_current_user_id(),
				'caps' => [
					Plugin::CAPABILITY => current_user_can( Plugin::CAPABILITY ),
				],
			],
			'modules'   => $this->module_descriptors(),
			'i18n'      => [
				'locale' => determine_locale(),
			],
		];

		/**
		 * Filter: fanxie_wp_core/admin/bootstrap
		 *
		 * Modifies the bootstrap payload sent to the Vue SPA.
		 *
		 * @param array<string, mixed> $bootstrap Bootstrap payload.
		 */
		$filtered = apply_filters( 'fanxie_wp_core/admin/bootstrap', $bootstrap );

		return is_array( $filtered ) ? $filtered : $bootstrap;
	}

	/**
	 * Lightweight module descriptors for the SPA.
	 *
	 * Empty at Phase 0.1 (no modules registered). Later phases populate this
	 * array via `ModuleRegistry`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function module_descriptors(): array {
		$descriptors = [];

		foreach ( $this->registry->all() as $module ) {
			$descriptors[] = [
				'id'      => $module->id(),
				'name'    => $module->name(),
				'enabled' => $module->is_enabled(),
			];
		}

		return $descriptors;
	}

	/**
	 * Does the Vite production build exist on disk?
	 */
	private function build_exists(): bool {
		return file_exists( FANXIE_WP_CORE_PATH . 'assets/admin/dist/admin.js' );
	}
}
