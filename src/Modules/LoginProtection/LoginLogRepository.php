<?php
/**
 * Persistence layer for login-protection event history.
 *
 * @package FanxieLab\Warden\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Data layer for `{$wpdb->prefix}fanxie_core_login_log`.
 *
 * The login event history that powers the admin log viewer and feeds the
 * attempt-limiting heuristics. Mirrors the structure of
 * {@see \FanxieLab\Warden\Modules\SecurityHeaders\ViolationRepository}:
 *
 *   - `install()` — create/upgrade the table via `dbDelta`, gated by a version
 *     option so it only runs on fresh installs or schema bumps.
 *   - `record()`  — append a single login event row.
 *   - `query()`   — paginated list with simple column + date filters.
 *   - `prune()`   — delete rows older than a given number of days.
 */
final class LoginLogRepository implements LoginLogRecorder {

	/**
	 * Option key holding the installed schema version.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'fanxie_warden_login_protection_log_version';

	/**
	 * Current schema version. Bump whenever the CREATE TABLE shape changes.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Table basename (excluding the WordPress prefix).
	 *
	 * @var string
	 */
	public const TABLE_BASENAME = 'fanxie_core_login_log';

	/**
	 * Fully-qualified table name, resolved once at construction.
	 *
	 * Resolved from `$wpdb->prefix` plus the class constant above, so the
	 * value can never contain anything a request could influence. Query
	 * builders below read this property directly rather than calling
	 * `table_name()` — a `$this->table` property read is provably
	 * request-independent to a reader and to static analysis alike,
	 * whereas an accessor call is opaque to both.
	 *
	 * @var string
	 */
	private readonly string $table;

	/**
	 * Resolve the table name from the active `$wpdb` prefix.
	 */
	public function __construct() {
		global $wpdb;

		$prefix      = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$this->table = $prefix . self::TABLE_BASENAME;
	}

	/**
	 * Expose the resolved table name — handy for diagnostics + tests.
	 */
	public function table_name(): string {
		return $this->table;
	}

	/**
	 * Ensure the custom table exists and is up to date.
	 *
	 * Idempotent — safe to call on every activation. dbDelta gracefully no-ops
	 * when the installed schema already matches.
	 */
	public function install(): void {
		global $wpdb;

		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->table;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(32) NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			username VARCHAR(180) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY ip (ip),
			KEY username (username),
			KEY created_at (created_at)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Append a login event to the log.
	 *
	 * @param string               $event_type Short event slug (e.g. `failed_login`), capped at 32 chars.
	 * @param string               $ip         Client IP address, capped at 45 chars (IPv6-safe).
	 * @param string               $username   Attempted username, capped at 180 chars.
	 * @param int|null             $user_id    Resolved user id when the username matched an account.
	 * @param array<string, mixed> $context    Extra detail, JSON-encoded into the `context` column.
	 */
	public function record( string $event_type, string $ip, string $username, ?int $user_id, array $context = [] ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table,
			[
				'event_type' => substr( $event_type, 0, 32 ),
				'ip'         => substr( $ip, 0, 45 ),
				'username'   => substr( $username, 0, 180 ),
				'user_id'    => $user_id,
				'context'    => [] === $context ? null : (string) wp_json_encode( $context ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Paginate + filter the login event log.
	 *
	 * @param array<string, mixed> $filters  Supported keys: `event_type`, `ip`, `username`, `since`, `until`.
	 *                                       Date filters accept `Y-m-d` or `Y-m-d H:i:s`.
	 * @param int                  $page     1-based page number.
	 * @param int                  $per_page Page size (clamped to 1..200).
	 * @return array{rows: list<array{id: int, event_type: string, ip: string, username: string, user_id: int|null, context: string|null, created_at: string}>, total: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table    = $this->table;
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [];
		$params = [];

		// Each filter maps to a literal WHERE fragment rather than one built by
		// interpolating the column name. The two forms behave identically for
		// the three keys below, but the literal form makes it impossible for a
		// future filter key to reach the SQL body: only the bound %s
		// placeholders ever carry caller-supplied values.
		$column_fragments = [
			'event_type' => 'event_type = %s',
			'ip'         => 'ip = %s',
			'username'   => 'username = %s',
		];

		foreach ( $column_fragments as $col => $fragment ) {
			if ( isset( $filters[ $col ] ) && '' !== (string) $filters[ $col ] ) {
				$where[]  = $fragment;
				$params[] = sanitize_text_field( (string) $filters[ $col ] );
			}
		}

		if ( isset( $filters['since'] ) && '' !== (string) $filters['since'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $filters['since'];
		}

		if ( isset( $filters['until'] ) && '' !== (string) $filters['until'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $filters['until'];
		}

		$where_sql = [] === $where ? '' : 'WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$rows_sql  = "SELECT id, event_type, ip, username, user_id, context, created_at
			FROM {$table} {$where_sql}
			ORDER BY id DESC
			LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// SQL bodies are built from internal constants / whitelisted column
		// fragments; user-provided values go through $wpdb->prepare() below.
		if ( [] === $params ) {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$records = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					// $wpdb returns every column as a string; cast the integer
					// columns back so the JSON payload matches the TS contract
					// (LoginLogRow.id: number, user_id: number | null). A NULL
					// user_id (unresolved username) surfaces as an unset ARRAY_A
					// value, so isset() is false and it stays null.
					$records[] = [
						'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
						'event_type' => isset( $row['event_type'] ) ? (string) $row['event_type'] : '',
						'ip'         => isset( $row['ip'] ) ? (string) $row['ip'] : '',
						'username'   => isset( $row['username'] ) ? (string) $row['username'] : '',
						'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
						'context'    => isset( $row['context'] ) ? (string) $row['context'] : null,
						'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
					];
				}
			}
		}

		return [
			'rows'  => $records,
			'total' => $total,
		];
	}

	/**
	 * Delete rows whose created_at is older than the given number of days.
	 *
	 * @param int $older_than_days Minimum age in days (clamped to >= 1).
	 * @return int Rows deleted.
	 */
	public function prune( int $older_than_days ): int {
		global $wpdb;

		$table = $this->table;
		$days  = max( 1, $older_than_days );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
				"DELETE FROM {$table} WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
