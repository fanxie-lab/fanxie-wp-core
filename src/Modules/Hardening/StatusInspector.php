<?php
/**
 * Server-state detection probes for the Hardening module.
 *
 * @package FanxieLab\WPCore\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\Runtime\RootHtaccessWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Pure detection layer — no side-effects beyond a transient cache.
 *
 * Probes the runtime environment for hardening-relevant state:
 *
 *   - Server type (apache / nginx / litespeed / iis / unknown).
 *   - `X-Powered-By` still present in a frontend response? (HTTP probe.)
 *   - `DISALLOW_FILE_EDIT` defined (and to what)?
 *   - Uploads dir listable + PHP-executable via live HTTP probe.
 *   - Uploads protection files on disk?
 *   - `/readme.html` and `/license.txt` reachable? (HTTP probe.)
 *   - Count of Application Passwords across users.
 *
 * Results are cached in a 5-minute transient so the admin UI stays snappy.
 * `snapshot( force: true )` bypasses the cache for manual re-probes, and
 * {@see invalidate_cache()} is called from every AJAX handler that mutates
 * state so the next read triggers a fresh probe.
 */
final class StatusInspector {

	public const CACHE_KEY     = 'fanxie_wp_core_hardening_status';
	public const CACHE_TTL_SEC = 300;

	/**
	 * Cached writer (constructed lazily).
	 *
	 * @var RootHtaccessWriter|null
	 */
	private ?RootHtaccessWriter $htaccess_writer;

	/**
	 * Constructor.
	 *
	 * @param UploadsProtector        $uploads         Uploads probe + detection helper.
	 * @param RootHtaccessWriter|null $htaccess_writer Optional writer override (tests).
	 */
	public function __construct(
		private readonly UploadsProtector $uploads,
		?RootHtaccessWriter $htaccess_writer = null,
	) {
		$this->htaccess_writer = $htaccess_writer;
	}

	/**
	 * Run the probes and return a shape compatible with the TS contract.
	 *
	 * @param bool $force Bypass the transient cache when true.
	 *
	 * @return array{
	 *   server_type: string,
	 *   x_powered_by_present: bool,
	 *   disallow_file_edit_defined: bool,
	 *   disallow_file_edit_value: bool,
	 *   uploads_dir_listable: bool|null,
	 *   uploads_php_executable: bool|null,
	 *   uploads_htaccess_exists: bool,
	 *   uploads_index_exists: bool,
	 *   readme_blocked: bool|null,
	 *   license_blocked: bool|null,
	 *   application_passwords_count: int,
	 *   probed_at: int,
	 * }
	 */
	public function snapshot( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && $this->is_valid_shape( $cached ) ) {
				/**
				 * Cached snapshot narrowed by `is_valid_shape()` above.
				 *
				 * @var array{
				 *   server_type: string,
				 *   x_powered_by_present: bool,
				 *   disallow_file_edit_defined: bool,
				 *   disallow_file_edit_value: bool,
				 *   uploads_dir_listable: bool|null,
				 *   uploads_php_executable: bool|null,
				 *   uploads_htaccess_exists: bool,
				 *   uploads_index_exists: bool,
				 *   readme_blocked: bool|null,
				 *   license_blocked: bool|null,
				 *   application_passwords_count: int,
				 *   probed_at: int,
				 * } $cached
				 */
				return $cached;
			}
		}

		$snapshot = [
			'server_type'                 => $this->uploads->detect_server_type(),
			'x_powered_by_present'        => $this->x_powered_by_present(),
			'disallow_file_edit_defined'  => defined( 'DISALLOW_FILE_EDIT' ),
			'disallow_file_edit_value'    => defined( 'DISALLOW_FILE_EDIT' ) ? (bool) constant( 'DISALLOW_FILE_EDIT' ) : false,
			'uploads_dir_listable'        => $this->uploads->probe_directory_listable(),
			'uploads_php_executable'      => $this->uploads->probe_php_execution(),
			'uploads_htaccess_exists'     => $this->uploads->htaccess_exists(),
			'uploads_index_exists'        => $this->uploads->index_exists(),
			'readme_blocked'              => $this->probe_path_blocked( 'readme.html' ),
			'license_blocked'             => $this->probe_path_blocked( 'license.txt' ),
			'application_passwords_count' => $this->count_application_passwords(),
			'probed_at'                   => time(),
		];

		set_transient( self::CACHE_KEY, $snapshot, self::CACHE_TTL_SEC );

		return $snapshot;
	}

	/**
	 * Invalidate the cached snapshot — called after settings save or fix apply.
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Whether a real frontend response still emits an `X-Powered-By` header.
	 *
	 * Previously we inspected `headers_list()` in-process, which returns only
	 * the headers *this* PHP request emits — useless during admin-ajax, which
	 * never runs `send_headers` on the public pipeline. Instead we fire an
	 * HTTP probe against `home_url('/')` and inspect the response headers
	 * case-insensitively. `wp_remote_retrieve_headers()` returns a
	 * `Requests_Utility_CaseInsensitiveDictionary` in older WP, or a plain
	 * array-offset handle in newer Requests builds — we coerce to an array
	 * and walk the keys manually to avoid false negatives from a mistyped
	 * `HeaderName` lookup.
	 */
	private function x_powered_by_present(): bool {
		$url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		if ( '' === $url ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$headers = wp_remote_retrieve_headers( $response );

		// Newer WP/Requests returns an iterable case-insensitive dictionary;
		// older returns a plain associative array. Normalise to a flat map of
		// lowercase-key => string value and look up our target.
		$map = [];
		if ( is_array( $headers ) ) {
			$map = $headers;
		} elseif ( is_object( $headers ) ) {
			if ( is_iterable( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					$map[ (string) $name ] = $value;
				}
			} elseif ( method_exists( $headers, 'getAll' ) ) {
				// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline PHPStan `@var` narrow for the plain `mixed` the method returns without an annotation.
				/** @var array<string, mixed> $all */
				$all = $headers->getAll();
				$map = is_array( $all ) ? $all : [];
			}
		}

		foreach ( $map as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'x-powered-by' ) ) {
				$value = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
				return '' !== trim( $value );
			}
		}

		return false;
	}

	/**
	 * HTTP-probe a path under the site root. Returns:
	 *   - `true`  when the server refuses it (non-200) → blocked.
	 *   - `false` when it returns 200 → still served.
	 *   - `null`  when the probe can't reach the host (WP_Error) → inconclusive.
	 *
	 * The `null` case matters in container/dev setups (e.g. wp-env) where the
	 * site cannot resolve its own public URL: we must not report "accessible"
	 * (a false warning) when we simply couldn't look.
	 *
	 * @param string $relative Path under the site root (e.g. `readme.html`).
	 */
	private function probe_path_blocked( string $relative ): ?bool {
		if ( ! function_exists( 'home_url' ) ) {
			return null;
		}

		$url = (string) home_url( '/' . ltrim( $relative, '/' ) );

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// 200 → served (bad). Everything else (403, 404, 5xx, 3xx without
		// follow-through, 0 from a misconfigured response) → blocked.
		return 200 !== $status;
	}

	/**
	 * Count Application Passwords across all users.
	 *
	 * Application Passwords are stored in `wp_usermeta` under the
	 * `_application_passwords` key as a serialised array. Rather than unserialise
	 * every row we ask WordPress to count them via `WP_Application_Passwords`
	 * when available; otherwise we fall back to a `usermeta` scan.
	 */
	private function count_application_passwords(): int {
		if ( ! class_exists( \WP_Application_Passwords::class ) ) {
			return 0;
		}

		/**
		 * WordPress database handle.
		 *
		 * @var \wpdb|null $wpdb
		 */
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		// `$wpdb->usermeta` is the internally-owned, filter-safe table name.
		// PHPStan asks for a literal-string query, so we `%i`-quote the table
		// name through `prepare()` itself rather than string-interpolating.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE meta_key = %s',
				$wpdb->usermeta,
				\WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $rows as $serialised ) {
			if ( ! is_string( $serialised ) || '' === $serialised ) {
				continue;
			}
			$decoded = maybe_unserialize( $serialised );
			if ( is_array( $decoded ) ) {
				$total += count( $decoded );
			}
		}

		return $total;
	}

	/**
	 * Defensive shape check — reject cached payloads from older deploys.
	 *
	 * @param array<string, mixed> $snapshot Candidate snapshot.
	 */
	private function is_valid_shape( array $snapshot ): bool {
		foreach (
			[
				'server_type',
				'x_powered_by_present',
				'disallow_file_edit_defined',
				'disallow_file_edit_value',
				'uploads_dir_listable',
				'uploads_php_executable',
				'uploads_htaccess_exists',
				'uploads_index_exists',
				'readme_blocked',
				'license_blocked',
				'application_passwords_count',
				'probed_at',
			] as $key
		) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lazily resolve the root `.htaccess` writer (currently unused inside
	 * `StatusInspector` but exposed so tests can inject a custom writer; the
	 * AJAX controller owns the block-ensure calls).
	 */
	public function htaccess_writer(): RootHtaccessWriter {
		if ( null === $this->htaccess_writer ) {
			$this->htaccess_writer = new RootHtaccessWriter();
		}
		return $this->htaccess_writer;
	}
}
