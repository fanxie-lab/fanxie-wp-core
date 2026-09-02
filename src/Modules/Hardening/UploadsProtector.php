<?php
/**
 * Writes protection files into `wp-content/uploads` and probes their effect.
 *
 * @package FanxieLab\WPCore\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads directory protection writer + probe.
 *
 * Responsibilities:
 *   - Drop `index.php` into uploads to suppress directory listing on servers
 *     whose default config lists directories.
 *   - Drop `.htaccess` to deny PHP execution under Apache.
 *   - Detect nginx / LiteSpeed / IIS and surface a copy-paste snippet in the
 *     UI (writing those servers' configs is out of scope — they live in
 *     root-owned files we can't safely modify from PHP).
 *   - Probe protection effectiveness via `wp_remote_get()` against an
 *     intentionally-placed canary PHP file, without leaving it behind.
 *
 * Every method is idempotent — callers can invoke `ensure_protection()` as
 * often as they like (we do on activation + on "Apply fix").
 */
final class UploadsProtector {

	public const INDEX_FILENAME    = 'index.php';
	public const HTACCESS_FILENAME = '.htaccess';

	/**
	 * Content written into the uploads `index.php`.
	 *
	 * @var string
	 */
	private const INDEX_PHP_CONTENTS = "<?php\n// Silence is golden. Dropped by Fanxie WP Core.\n";

	/**
	 * Apache `.htaccess` body used when `block_php_execution` is on.
	 *
	 * Targets every PHP-ish extension Apache might hand to the interpreter.
	 *
	 * @var string
	 */
	private const HTACCESS_CONTENTS = "# BEGIN Fanxie WP Core — deny PHP execution\n<FilesMatch \"\\.(?:php|phtml|php[3-7]?|phar)$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n# END Fanxie WP Core\n";

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Snapshot of `$module->get_config()`.
	 */
	public function __construct( private readonly array $config = [] ) {}

	/**
	 * Idempotently drop protection files per the active config.
	 *
	 * @param array<string, mixed>|null $config Optional override (used by the AJAX controller after save).
	 * @return array<string, bool> Map of target id → whether the file now exists.
	 */
	public function ensure_protection( ?array $config = null ): array {
		$config  = $config ?? $this->config;
		$results = [
			'uploads_index'    => false,
			'uploads_htaccess' => false,
		];

		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return $results;
		}

		if ( ! empty( $config['uploads']['drop_index'] ?? true ) ) {
			$results['uploads_index'] = $this->write_file_if_missing(
				trailingslashit( $uploads ) . self::INDEX_FILENAME,
				self::INDEX_PHP_CONTENTS
			);
		}

		if ( ! empty( $config['uploads']['block_php_execution'] ?? true ) && $this->is_apache_compatible() ) {
			$results['uploads_htaccess'] = $this->write_file_if_missing(
				trailingslashit( $uploads ) . self::HTACCESS_FILENAME,
				self::HTACCESS_CONTENTS
			);
		}

		return $results;
	}

	/**
	 * Write a single target on demand (called by `hardening/apply-fix` +
	 * `hardening/drop-upload-guard`).
	 *
	 * The `uploads_htaccess` target is gated on `is_apache_compatible()` — same
	 * as `ensure_protection()` — so the two write paths agree. Dropping a
	 * `.htaccess` a non-Apache server ignores would leave a no-op file that
	 * `htaccess_exists()` then misreports as "protected". On nginx/IIS/unknown
	 * we return false; the UI already warns and surfaces a server-specific
	 * snippet instead. `uploads_index` stays unconditional: a blank `index.php`
	 * suppresses directory listing everywhere.
	 *
	 * @param string $target `'uploads_index'` | `'uploads_htaccess'`.
	 * @return bool Whether the target now exists on disk.
	 */
	public function apply_fix( string $target ): bool {
		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return false;
		}

		return match ( $target ) {
			'uploads_index'    => $this->write_file(
				trailingslashit( $uploads ) . self::INDEX_FILENAME,
				self::INDEX_PHP_CONTENTS
			),
			'uploads_htaccess' => $this->is_apache_compatible()
				? $this->write_file(
					trailingslashit( $uploads ) . self::HTACCESS_FILENAME,
					self::HTACCESS_CONTENTS
				)
				: false,
			default            => false,
		};
	}

	/**
	 * Delete a protection file on demand (called by `hardening/remove-upload-guard`).
	 *
	 * @param string $target `'uploads_index'` | `'uploads_htaccess'`.
	 * @return bool True on success (including "already absent"); false on IO failure.
	 */
	public function remove_fix( string $target ): bool {
		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return false;
		}

		$path = match ( $target ) {
			'uploads_index'    => trailingslashit( $uploads ) . self::INDEX_FILENAME,
			'uploads_htaccess' => trailingslashit( $uploads ) . self::HTACCESS_FILENAME,
			default            => null,
		};

		if ( null === $path ) {
			return false;
		}

		if ( ! file_exists( $path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- matching the direct file_put_contents writer above; WP_Filesystem is admin-only.
		return (bool) @unlink( $path );
	}

	/**
	 * Resolve the uploads base directory, filterable for multisite edge cases.
	 */
	public function uploads_dir(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$info    = wp_upload_dir( null, false );
		$basedir = is_array( $info ) && isset( $info['basedir'] ) ? (string) $info['basedir'] : '';

		/**
		 * Filter: fanxie_wp_core/hardening/uploads_dir
		 *
		 * Override the uploads directory used for protection writes + probes.
		 *
		 * @since 0.2.0-dev
		 *
		 * @param string $basedir Resolved uploads base dir.
		 */
		$basedir = (string) apply_filters( 'fanxie_wp_core/hardening/uploads_dir', $basedir );

		return '' === $basedir ? null : $basedir;
	}

	/**
	 * Detect the web server so we can pick the right remediation path.
	 *
	 * Returns one of: `apache` | `nginx` | `litespeed` | `iis` | `unknown`.
	 */
	public function detect_server_type(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
		$software = strtolower( $software );

		if ( str_contains( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( str_contains( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( str_contains( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( str_contains( $software, 'iis' ) || str_contains( $software, 'microsoft-iis' ) ) {
			return 'iis';
		}

		return 'unknown';
	}

	/**
	 * Nginx config snippet the admin UI can surface verbatim.
	 */
	public function nginx_snippet(): string {
		return "location ~* /wp-content/uploads/.*\\.php\$ {\n    deny all;\n}\n";
	}

	/**
	 * Attempt to list the uploads directory over HTTP.
	 *
	 * Returns:
	 *   - `true`  if the response looks like a directory index (HTML + `Index of`).
	 *   - `false` if the server returns 403 / 404 / an `index.php` silence.
	 *   - `null`  if the probe can't reach the server (WP_Error, timeout).
	 */
	public function probe_directory_listable(): ?bool {
		$url = $this->uploads_url();
		if ( null === $url ) {
			return null;
		}

		$response = wp_remote_get( $url, [ 'timeout' => 5 ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $status || 404 === $status ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $status && false !== stripos( $body, 'Index of' ) ) {
			return true;
		}

		// 200 with an `index.php` silence (empty body) counts as not-listable.
		return false;
	}

	/**
	 * Probe PHP execution in the uploads dir by writing, requesting, deleting
	 * a canary. Returns:
	 *
	 *   - `true`  when the canary body echoes back (PHP IS executing — bad).
	 *   - `false` when the server refuses the request (4xx / 5xx) OR when it
	 *             serves the file raw / empty (body missing our marker).
	 *   - `null`  ONLY when the write itself failed — we couldn't place a
	 *             canary, so we have no way to know.
	 *
	 * Previously we returned `null` on `WP_Error` / transport failure too,
	 * which produced false "inconclusive" results on wp-env containers whose
	 * outbound HTTP can't resolve `home_url()` back at themselves. A failed
	 * probe after a successful write is strong evidence the file is blocked
	 * — the canary is always cleaned up in a `finally` regardless.
	 */
	public function probe_php_execution(): ?bool {
		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return null;
		}

		$basename = 'fanxie-hardening-probe-' . wp_generate_password( 8, false, false ) . '.php';
		$path     = trailingslashit( $uploads ) . $basename;
		$marker   = 'CANARY_HIT';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem requires admin bootstrap; probe writes a tiny throwaway canary we immediately delete.
		$written = @file_put_contents( $path, "<?php echo '{$marker}';\n" );
		if ( false === $written ) {
			return null;
		}

		$url = trailingslashit( (string) $this->uploads_url() ) . $basename;
		try {
			$response = wp_remote_get(
				$url,
				[
					'timeout'     => 3,
					'redirection' => 0,
					'sslverify'   => false,
				]
			);

			// Transport error or non-response: we wrote the canary and it
			// wasn't reachable — treat that as "safe / blocked" rather than
			// inconclusive. The UI still surfaces a warning via the secondary
			// status row, but we don't drop into null-land.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );

			if ( 200 === $status && false !== strpos( $body, $marker ) ) {
				return true;
			}

			return false;
		} finally {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- matching the direct file_put_contents used above; runs even if the HTTP probe throws.
				@unlink( $path );
			}
		}
	}

	/**
	 * Whether the current server honours `.htaccess` — Apache proper or
	 * LiteSpeed (same syntax). We no longer treat `unknown` as Apache: writing
	 * a `.htaccess` we can't confirm is honoured produced misleading "write
	 * failed" states. On unknown/nginx/IIS the UI surfaces a server-appropriate
	 * snippet instead (see StatusInspector + the Hardening admin view).
	 */
	private function is_apache_compatible(): bool {
		$type = $this->detect_server_type();
		return 'apache' === $type || 'litespeed' === $type;
	}

	/**
	 * Public URL for the uploads directory, filterable.
	 */
	private function uploads_url(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$info = wp_upload_dir( null, false );
		$url  = is_array( $info ) && isset( $info['baseurl'] ) ? (string) $info['baseurl'] : '';

		return '' === $url ? null : trailingslashit( $url );
	}

	/**
	 * Write file contents only when the file is missing. Returns whether the
	 * file now exists on disk (already-present counts as true).
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents to write on creation.
	 */
	private function write_file_if_missing( string $path, string $contents ): bool {
		if ( file_exists( $path ) ) {
			return true;
		}

		return $this->write_file( $path, $contents );
	}

	/**
	 * Write file contents, overwriting any existing file.
	 *
	 * Used by the "drop" action which is deliberately re-assertive — the user
	 * clicked a button asking us to restore protection, so we rewrite even
	 * when the file already exists (guarantees correct content after a
	 * manual edit).
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents.
	 */
	private function write_file( string $path, string $contents ): bool {
		$dir = dirname( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- runtime writability check; WP_Filesystem is admin-only.
		if ( ! is_dir( $dir ) || ( ! is_writable( $dir ) && ! file_exists( $path ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem requires admin.php; this writer is called from activation and AJAX where the admin stack is not guaranteed.
		$written = @file_put_contents( $path, $contents, LOCK_EX );
		return false !== $written;
	}

	/**
	 * Whether the uploads `index.php` is in place.
	 */
	public function index_exists(): bool {
		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return false;
		}
		return file_exists( trailingslashit( $uploads ) . self::INDEX_FILENAME );
	}

	/**
	 * Whether the uploads `.htaccess` is in place.
	 */
	public function htaccess_exists(): bool {
		$uploads = $this->uploads_dir();
		if ( null === $uploads ) {
			return false;
		}
		return file_exists( trailingslashit( $uploads ) . self::HTACCESS_FILENAME );
	}
}
