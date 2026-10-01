<?php
/**
 * Hide WordPress version fingerprints.
 *
 * @package FanxieLab\Warden\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Strips WP version markers that help attackers fingerprint the install:
 *
 *   - `<meta name="generator">` in `<head>` (and RSS `<generator>` feeds).
 *   - `?ver=` cache-busters on script/style URLs.
 *   - `/readme.html` and `/license.txt`, which ship WP's exact version.
 *
 * For the readme/license block we prefer a server-layer deny (Apache `.htaccess`
 * snippet dropped by {@see RootHtaccessWriter}) because PHP never runs for
 * static files under default Apache configs. A PHP `init` fallback covers edge
 * cases (nginx / LiteSpeed with non-Apache rules, files removed from disk,
 * request routed through PHP by a custom rewrite).
 *
 * Each behaviour is independently toggleable via the module config.
 */
final class VersionHider {

	/**
	 * Optional writer override (tests).
	 *
	 * @var RootHtaccessWriter|null
	 */
	private ?RootHtaccessWriter $htaccess_writer;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>    $config          Snapshot of `$module->get_config()`.
	 * @param RootHtaccessWriter|null $htaccess_writer Optional writer override (tests).
	 */
	public function __construct( private readonly array $config, ?RootHtaccessWriter $htaccess_writer = null ) {
		$this->htaccess_writer = $htaccess_writer;
	}

	/**
	 * Attach hooks.
	 */
	public function register_hooks(): void {
		if ( $this->get( 'remove_wp_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', [ $this, 'empty_generator' ], 10, 0 );
		}

		if ( $this->get( 'remove_rss_generator' ) ) {
			foreach ( [ 'rss2_head', 'atom_head', 'rdf_header', 'rss_head', 'commentsrss2_head', 'opml_head' ] as $feed_action ) {
				remove_action( $feed_action, 'the_generator' );
			}
			add_filter( 'the_generator', [ $this, 'empty_generator' ], 10, 0 );
		}

		if ( $this->get( 'strip_version_query' ) ) {
			add_filter( 'script_loader_src', [ $this, 'strip_version_query' ], 9999 );
			add_filter( 'style_loader_src', [ $this, 'strip_version_query' ], 9999 );
		}

		if ( $this->get( 'block_readme_license' ) ) {
			// Runtime fallback — fires before WordPress resolves the request,
			// so we catch `/readme.html` / `/license.txt` regardless of how WP
			// would otherwise route them. Priority 1 keeps us ahead of plugins
			// that also listen on `init`.
			add_action( 'init', [ $this, 'maybe_block_readme_license_runtime' ], 1 );
		}
	}

	/**
	 * Idempotently install the server-layer block (call from activation + save).
	 *
	 * @return bool True when the block is now present; false on write failure.
	 */
	public function ensure_readme_license_block(): bool {
		if ( ! $this->get( 'block_readme_license' ) ) {
			return $this->writer()->remove_block();
		}

		return $this->writer()->ensure_readme_license_block();
	}

	/**
	 * Remove the server-layer block (called on uninstall / toggle-off).
	 */
	public function remove_readme_license_block(): bool {
		return $this->writer()->remove_block();
	}

	/**
	 * Expose the writer so callers (AJAX controller, status probe) can inspect state.
	 */
	public function writer(): RootHtaccessWriter {
		if ( null === $this->htaccess_writer ) {
			$this->htaccess_writer = new RootHtaccessWriter();
		}
		return $this->htaccess_writer;
	}

	/**
	 * Replace the generator tag output with an empty string.
	 */
	public function empty_generator(): string {
		return '';
	}

	/**
	 * Strip the `ver` query arg from enqueued asset URLs.
	 *
	 * @param string|mixed $src Asset URL.
	 */
	public function strip_version_query( mixed $src ): string {
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}

		if ( false === strpos( $src, 'ver=' ) ) {
			return $src;
		}

		return (string) remove_query_arg( 'ver', $src );
	}

	/**
	 * 404 `/readme.html` and `/license.txt` at the WordPress layer.
	 *
	 * Hooked on `init` (priority 1) so we fire before any theme / plugin
	 * `init` listener and well before `template_redirect`. We inspect
	 * `REQUEST_URI` directly rather than relying on WP's resolved query so
	 * the block works even when the file-backed request never resolves into
	 * a WP_Query.
	 */
	public function maybe_block_readme_license_runtime(): void {
		$this->maybe_block_readme_license();
	}

	/**
	 * Back-compat shim for the previous `template_redirect` binding and existing
	 * tests that call it directly.
	 */
	public function maybe_block_readme_license(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return;
		}

		$basename = strtolower( basename( $path ) );
		if ( 'readme.html' !== $basename && 'license.txt' !== $basename ) {
			return;
		}

		status_header( 404 );
		nocache_headers();

		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'Not Found', 'fanxie-warden' ), '', [ 'response' => 404 ] );
		}
	}

	/**
	 * Read a toggle under `version_hiding.*`, defaulting to `true`.
	 *
	 * @param string $key Settings leaf name.
	 */
	private function get( string $key ): bool {
		return (bool) ( $this->config['version_hiding'][ $key ] ?? true );
	}
}
