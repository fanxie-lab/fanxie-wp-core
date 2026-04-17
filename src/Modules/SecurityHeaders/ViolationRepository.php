<?php
/**
 * Persistence layer for CSP violation records.
 *
 * @package FanxieLab\WPCore\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\SecurityHeaders;

defined( 'ABSPATH' ) || exit;

/**
 * Data layer for `{$wpdb->prefix}fanxie_core_csp_violations`.
 *
 * Responsibilities:
 *   - `install()` — create/upgrade the table via `dbDelta`, gated by a
 *     version option so we only run it on fresh installs or schema bumps.
 *   - `record()`  — insert a new violation or increment an existing row
 *     within a one-hour dedup window.
 *   - `query()`   — paginated list with simple directive + date filters.
 *   - `prune()`   — delete rows older than a given number of days.
 *   - `purge_all()` — wipe the table (soft equivalent of `TRUNCATE`, done
 *     as a bounded `DELETE` so it participates in any WP filters).
 */
final class ViolationRepository {

	/**
	 * Option key holding the installed schema version.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'fanxie_wp_core_security_headers_table_version';

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
	public const TABLE_BASENAME = 'fanxie_core_csp_violations';

	/**
	 * Fully-qualified table name, resolved lazily.
	 *
	 * @var string|null
	 */
	private ?string $table = null;

	/**
	 * Expose the resolved table name — handy for diagnostics + tests.
	 */
	public function table_name(): string {
		if ( null === $this->table ) {
			global $wpdb;
			$prefix      = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			$this->table = $prefix . self::TABLE_BASENAME;
		}
		return $this->table;
	}

	/**
	 * Ensure the custom table exists and is up to date.
	 *
	 * Idempotent — safe to call on every request. dbDelta gracefully no-ops
	 * when the installed schema already matches.
	 */
	public function install(): void {
		global $wpdb;

		$installed_version = (string) get_option( self::SCHEMA_VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === $installed_version ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			directive VARCHAR(100) NOT NULL,
			blocked_uri TEXT NOT NULL,
			document_uri TEXT NOT NULL,
			source_file VARCHAR(500) DEFAULT NULL,
			line_number INT UNSIGNED DEFAULT NULL,
			user_agent VARCHAR(500) DEFAULT NULL,
			count INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY directive (directive),
			KEY created_at (created_at),
			KEY dedup (directive(50), blocked_uri(100), document_uri(100))
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record a violation, deduping against the last hour window.
	 *
	 * Within a one-hour window we increment `count` and update `last_seen_at`
	 * rather than inserting a new row — this keeps the table bounded on
	 * sites that receive a storm of reports from a single page load.
	 *
	 * @param ViolationRecord $violation Incoming violation.
	 */
	public function record( ViolationRecord $violation ): void {
		global $wpdb;

		$table        = $this->table_name();
		$directive    = $violation->directive;
		$blocked_uri  = $violation->blocked_uri;
		$document_uri = $violation->document_uri;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
				"SELECT id FROM {$table}
				 WHERE directive = %s
				   AND blocked_uri = %s
				   AND document_uri = %s
				   AND last_seen_at >= ( UTC_TIMESTAMP() - INTERVAL 1 HOUR )
				 LIMIT 1",
				$directive,
				$blocked_uri,
				$document_uri
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null !== $existing_id && '' !== $existing_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
					"UPDATE {$table}
					 SET count = count + 1,
					     last_seen_at = UTC_TIMESTAMP()
					 WHERE id = %d",
					(int) $existing_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				[
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					'last_seen_at' => gmdate( 'Y-m-d H:i:s' ),
					'directive'    => $directive,
					'blocked_uri'  => $blocked_uri,
					'document_uri' => $document_uri,
					'source_file'  => $violation->source_file,
					'line_number'  => $violation->line_number,
					'user_agent'   => $violation->user_agent,
					'count'        => 1,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * Action: fanxie_wp_core/security_headers/violation_recorded
		 *
		 * Fires after a CSP violation has been persisted (inserted or
		 * incremented) to the custom table.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param ViolationRecord $violation The violation that was just recorded.
		 */
		do_action( 'fanxie_wp_core/security_headers/violation_recorded', $violation );
	}

	/**
	 * Paginate + filter the violation log.
	 *
	 * @param array<string, mixed> $filters Supported keys: `directive`, `since`, `until`.
	 *                                      Date filters accept `Y-m-d` or `Y-m-d H:i:s`.
	 * @param int                  $page     1-based page number.
	 * @param int                  $per_page Page size (clamped to 1..200).
	 * @return array{rows: array<int, ViolationRecord>, total: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table    = $this->table_name();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [];
		$params = [];

		if ( isset( $filters['directive'] ) && '' !== (string) $filters['directive'] ) {
			$where[]  = 'directive = %s';
			$params[] = sanitize_text_field( (string) $filters['directive'] );
		}

		if ( isset( $filters['since'] ) && '' !== (string) $filters['since'] ) {
			$where[]  = 'last_seen_at >= %s';
			$params[] = (string) $filters['since'];
		}

		if ( isset( $filters['until'] ) && '' !== (string) $filters['until'] ) {
			$where[]  = 'last_seen_at <= %s';
			$params[] = (string) $filters['until'];
		}

		$where_sql = '' === implode( '', $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$rows_sql  = "SELECT id, created_at, last_seen_at, directive, blocked_uri, document_uri,
			source_file, line_number, user_agent, count
			FROM {$table} {$where_sql}
			ORDER BY last_seen_at DESC, id DESC
			LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// SQL bodies are built from internal constants / whitelisted WHERE
		// fragments; user-provided values go through $wpdb->prepare() below.
		if ( [] === $params ) {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					$rows_sql,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					$count_sql,
					...$params
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					$rows_sql,
					...array_merge( $params, [ $per_page, $offset ] )
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$records = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$records[] = $this->hydrate( $row );
			}
		}

		return [
			'rows'  => $records,
			'total' => $total,
		];
	}

	/**
	 * Delete rows whose last_seen_at is older than the given number of days.
	 *
	 * @param int $older_than_days Minimum age in days (clamped to >= 1).
	 * @return int Rows deleted.
	 */
	public function prune( int $older_than_days ): int {
		global $wpdb;

		$table = $this->table_name();
		$days  = max( 1, $older_than_days );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is private.
				"DELETE FROM {$table} WHERE last_seen_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Delete every row from the violations table.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_all(): int {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( "DELETE FROM {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Hydrate a row into a ViolationRecord.
	 *
	 * @param array<string, mixed> $row Raw DB row.
	 */
	private function hydrate( array $row ): ViolationRecord {
		return new ViolationRecord(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) ( $row['directive'] ?? '' ),
			(string) ( $row['blocked_uri'] ?? '' ),
			(string) ( $row['document_uri'] ?? '' ),
			isset( $row['source_file'] ) && '' !== (string) $row['source_file'] ? (string) $row['source_file'] : null,
			isset( $row['line_number'] ) && '' !== (string) $row['line_number'] ? (int) $row['line_number'] : null,
			isset( $row['user_agent'] ) && '' !== (string) $row['user_agent'] ? (string) $row['user_agent'] : null,
			isset( $row['count'] ) ? (int) $row['count'] : 1,
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : null,
		);
	}
}
