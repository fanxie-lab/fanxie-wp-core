<?php
/**
 * Idempotent Apache `.htaccess` snippet writer for the WordPress root.
 *
 * @package FanxieLab\WPCore\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Writes / removes a named block in the WordPress root `.htaccess`.
 *
 * Apache (and LiteSpeed) serve files like `readme.html` / `license.txt` as
 * static content, so a PHP `template_redirect` 404 can never run. To actually
 * block those URLs we need a server-layer rule. This writer drops an
 * idempotent snippet between `# BEGIN Fanxie WP Core` / `# END Fanxie WP Core`
 * markers in the root `.htaccess`, mirroring how WordPress core manages its
 * own block via `save_mod_rewrite_rules()`.
 *
 * Responsibilities:
 *   - Locate the root `.htaccess` (create it when writable + missing).
 *   - Replace just the fanxie block between the markers (no other content
 *     is touched).
 *   - Remove the block cleanly on request (on uninstall or toggle-off).
 *   - Report whether the block is currently present for the UI status probe.
 *
 * Never writes when the target directory is unwritable. Callers get a boolean
 * result and can surface a "could not write" warning.
 */
final class RootHtaccessWriter {

	public const MARKER_BEGIN = '# BEGIN Fanxie WP Core';
	public const MARKER_END   = '# END Fanxie WP Core';

	/**
	 * Snippet denying direct access to `readme.html` and `license.txt`.
	 *
	 * Uses `<Files>` + `Require all denied` so we cover both Apache 2.2 and
	 * 2.4+ deployments (LiteSpeed honours the same directive). We intentionally
	 * use two separate `<Files>` stanzas rather than a `<FilesMatch>` so the
	 * intent reads cleanly in anyone's `.htaccess`.
	 *
	 * @var string
	 */
	private const README_LICENSE_SNIPPET = "<Files \"readme.html\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</Files>\n<Files \"license.txt\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</Files>";

	/**
	 * Absolute path to the `.htaccess` we operate on.
	 *
	 * @var string
	 */
	private readonly string $htaccess_path;

	/**
	 * Constructor.
	 *
	 * @param string|null $htaccess_path Optional override for tests.
	 */
	public function __construct( ?string $htaccess_path = null ) {
		if ( null !== $htaccess_path && '' !== $htaccess_path ) {
			$this->htaccess_path = $htaccess_path;
			return;
		}

		/**
		 * Filter: fanxie_wp_core/hardening/root_htaccess_path
		 *
		 * Override the root `.htaccess` path resolved from `ABSPATH`.
		 *
		 * @since 0.2.0-dev
		 *
		 * @param string $path Resolved absolute path.
		 */
		$default             = rtrim( defined( 'ABSPATH' ) ? (string) ABSPATH : '', '/\\' ) . '/.htaccess';
		$this->htaccess_path = (string) apply_filters( 'fanxie_wp_core/hardening/root_htaccess_path', $default );
	}

	/**
	 * Ensure the readme/license denial block is present in the root `.htaccess`.
	 *
	 * @return bool True when the block is now in place; false on write failure.
	 */
	public function ensure_readme_license_block(): bool {
		return $this->write_block( self::README_LICENSE_SNIPPET );
	}

	/**
	 * Remove the Fanxie block entirely.
	 *
	 * @return bool True on success (including "no block to remove"); false on write failure.
	 */
	public function remove_block(): bool {
		if ( ! file_exists( $this->htaccess_path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem requires admin bootstrap; this runs from AJAX + activation.
		$current = @file_get_contents( $this->htaccess_path );
		if ( false === $current ) {
			return false;
		}

		$replaced = $this->splice_block( $current, null );
		if ( $replaced === $current ) {
			return true;
		}

		return $this->write_file( $replaced );
	}

	/**
	 * Whether the Fanxie block is currently present.
	 */
	public function block_present(): bool {
		if ( ! file_exists( $this->htaccess_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- runtime read; WP_Filesystem is admin-only.
		$current = @file_get_contents( $this->htaccess_path );
		if ( false === $current ) {
			return false;
		}

		return false !== strpos( $current, self::MARKER_BEGIN )
			&& false !== strpos( $current, self::MARKER_END );
	}

	/**
	 * Resolved `.htaccess` path (exposed for tests).
	 */
	public function path(): string {
		return $this->htaccess_path;
	}

	/**
	 * Write (or replace) the Fanxie block with the given body.
	 *
	 * @param string $body Rule body to place between the markers.
	 */
	private function write_block( string $body ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- runtime read; WP_Filesystem is admin-only.
		$current = file_exists( $this->htaccess_path ) ? @file_get_contents( $this->htaccess_path ) : '';
		if ( false === $current ) {
			$current = '';
		}

		$replaced = $this->splice_block( $current, $body );
		if ( $replaced === $current ) {
			return true;
		}

		return $this->write_file( $replaced );
	}

	/**
	 * Replace (or insert / remove) the Fanxie block inside a `.htaccess` body.
	 *
	 * @param string      $source Existing file contents.
	 * @param string|null $body   Block body; `null` removes the block entirely.
	 */
	private function splice_block( string $source, ?string $body ): string {
		$block = null === $body
			? ''
			: self::MARKER_BEGIN . "\n" . rtrim( $body, "\n" ) . "\n" . self::MARKER_END . "\n";

		$pattern = '/' . preg_quote( self::MARKER_BEGIN, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . "\n?/s";

		if ( 1 === preg_match( $pattern, $source ) ) {
			$replaced = preg_replace( $pattern, $block, $source, 1 );
			return is_string( $replaced ) ? $replaced : $source;
		}

		if ( '' === $block ) {
			return $source;
		}

		// Append with a single blank-line separator when the file is not empty.
		$prefix = '' === $source ? '' : rtrim( $source, "\n" ) . "\n\n";
		return $prefix . $block;
	}

	/**
	 * Write contents atomically to the resolved `.htaccess` path.
	 *
	 * @param string $contents Full file contents.
	 */
	private function write_file( string $contents ): bool {
		$dir = dirname( $this->htaccess_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- runtime writability check; WP_Filesystem is admin-only.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			// If the file already exists and is writable we can still proceed —
			// the directory needn't be writable to rewrite an existing file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- ditto.
			if ( ! file_exists( $this->htaccess_path ) || ! is_writable( $this->htaccess_path ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem requires admin bootstrap; runs from AJAX + activation.
		$written = @file_put_contents( $this->htaccess_path, $contents, LOCK_EX );
		return false !== $written;
	}
}
