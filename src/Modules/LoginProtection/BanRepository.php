<?php
/**
 * Persistence layer for active login-protection bans.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Data layer for `{$wpdb->prefix}fanxie_core_login_bans`.
 *
 * Holds active persistent bans, looked up on every gated login attempt. A ban
 * is keyed by a `(subject_type, subject_value)` pair — e.g. `('ip', '203.0.113.9')`
 * or `('username', 'bob')` — and may be indefinite (`expires_at IS NULL`) or
 * time-boxed. Mirrors the conventions of
 * {@see \FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository}.
 */
final class BanRepository {

	/**
	 * Option key holding the installed schema version.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'fanxie_wp_core_login_protection_bans_version';

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
	public const TABLE_BASENAME = 'fanxie_core_login_bans';

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
	 * Idempotent — safe to call on every activation. dbDelta gracefully no-ops
	 * when the installed schema already matches.
	 */
	public function install(): void {
		global $wpdb;

		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type VARCHAR(16) NOT NULL,
			subject_value VARCHAR(180) NOT NULL,
			reason VARCHAR(191) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subject (subject_type, subject_value)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Create or refresh a ban for the given subject.
	 *
	 * Upserts on the unique `(subject_type, subject_value)` key: re-banning an
	 * already-banned subject updates its reason and expiry rather than erroring.
	 *
	 * @param string      $subject_type  Ban dimension, e.g. `ip` or `username` (capped at 16 chars).
	 * @param string      $subject_value The banned value (capped at 180 chars).
	 * @param string|null $reason        Optional human-readable reason (capped at 191 chars).
	 * @param int|null    $ttl_minutes   Lifetime in minutes; `null` for an indefinite ban.
	 */
	public function add( string $subject_type, string $subject_value, ?string $reason = null, ?int $ttl_minutes = null ): void {
		global $wpdb;

		$expires = null === $ttl_minutes ? null : gmdate( 'Y-m-d H:i:s', time() + ( $ttl_minutes * 60 ) );

		// `$wpdb->prepare()` cannot bind a SQL NULL — it renders a PHP null as an
		// empty string, which a DATETIME column rejects under strict SQL mode
		// (and silently coerces to a zero-date otherwise), so a null `expires_at`
		// could never satisfy the `expires_at IS NULL` lookup in is_banned().
		// Bind empty strings and let NULLIF() restore a real NULL at the DB layer.
		$reason_value  = null === $reason ? '' : substr( $reason, 0, 191 );
		$expires_value = null === $expires ? '' : $expires;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
				"INSERT INTO {$this->table_name()} (subject_type, subject_value, reason, expires_at, created_at)
				 VALUES (%s, %s, NULLIF(%s, ''), NULLIF(%s, ''), %s)
				 ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)",
				substr( $subject_type, 0, 16 ),
				substr( $subject_value, 0, 180 ),
				$reason_value,
				$expires_value,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Remove a ban for the given subject.
	 *
	 * @param string $subject_type  Ban dimension.
	 * @param string $subject_value The banned value.
	 * @return int Rows deleted (0 or 1).
	 */
	public function remove( string $subject_type, string $subject_value ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table_name(),
			[
				'subject_type'  => $subject_type,
				'subject_value' => $subject_value,
			],
			[ '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Whether the given subject currently has an active (non-expired) ban.
	 *
	 * @param string $subject_type  Ban dimension.
	 * @param string $subject_value The value to check.
	 */
	public function is_banned( string $subject_type, string $subject_value ): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
				"SELECT id FROM {$table} WHERE subject_type = %s AND subject_value = %s AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1",
				$subject_type,
				$subject_value,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $id && '' !== $id;
	}

	/**
	 * Paginate the ban list, newest first.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size (clamped to 1..200).
	 * @return array{rows: list<array<array-key, mixed>>, total: int}
	 */
	public function query( int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table    = $this->table_name();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, subject_type, subject_value, reason, expires_at, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$records = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$records[] = $row;
				}
			}
		}

		return [
			'rows'  => $records,
			'total' => $total,
		];
	}

	/**
	 * Delete every ban whose expiry has passed.
	 *
	 * @return int Rows deleted.
	 */
	public function prune_expired(): int {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a private property.
				"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= %s",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
