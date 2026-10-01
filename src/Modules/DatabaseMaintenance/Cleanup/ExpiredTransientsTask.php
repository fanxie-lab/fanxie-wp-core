<?php
/**
 * Expired transients still sitting in wp_options.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Removes expired transients (and site transients) from the options table.
 */
final class ExpiredTransientsTask implements CleanupTask {

	/**
	 * Value prefix => matching timeout prefix.
	 */
	private const KINDS = [
		'_transient_'      => '_transient_timeout_',
		'_site_transient_' => '_site_transient_timeout_',
	];

	/**
	 * Whether transients live in an external object cache (not in wp_options).
	 */
	public static function uses_object_cache(): bool {
		return true === wp_using_ext_object_cache();
	}

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'expired-transients';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'Expired transients', 'fanxie-warden' );
	}

	/**
	 * Number of expired transients.
	 */
	public function count(): int {
		if ( self::uses_object_cache() ) {
			return 0;
		}

		global $wpdb;
		$total = 0;

		foreach ( self::KINDS as $timeout_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- live count for the maintenance screen; core table name.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$wpdb->esc_like( $timeout_prefix ) . '%',
					time()
				)
			);
		}

		return $total;
	}

	/**
	 * Approximate bytes held by expired transients (value and timeout rows).
	 */
	public function estimate_bytes(): int {
		if ( self::uses_object_cache() ) {
			return 0;
		}

		global $wpdb;
		$total = 0;

		foreach ( self::KINDS as $value_prefix => $timeout_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- live estimate for the maintenance screen; core table name.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(LENGTH(t.option_name) + LENGTH(t.option_value) + COALESCE(LENGTH(v.option_name) + LENGTH(v.option_value), 0)), 0)
					FROM {$wpdb->options} t
					LEFT JOIN {$wpdb->options} v ON v.option_name = CONCAT(%s, SUBSTRING(t.option_name, %d))
					WHERE t.option_name LIKE %s AND t.option_value < %d",
					$value_prefix,
					strlen( $timeout_prefix ) + 1,
					$wpdb->esc_like( $timeout_prefix ) . '%',
					time()
				)
			);
		}

		return $total;
	}

	/**
	 * Preview rows for the confirmation dialog.
	 *
	 * @param int $n Maximum number of rows.
	 * @return list<array{label: string, detail: string, date: string|null}>
	 */
	public function sample( int $n ): array {
		if ( self::uses_object_cache() ) {
			return [];
		}

		global $wpdb;
		$out = [];

		foreach ( self::KINDS as $timeout_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- preview rows; core table name.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d ORDER BY option_value ASC LIMIT %d",
					$wpdb->esc_like( $timeout_prefix ) . '%',
					time(),
					$n
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[] = [
					'label'  => substr( (string) $row['option_name'], strlen( $timeout_prefix ) ),
					'detail' => '_site_transient_timeout_' === $timeout_prefix ? __( 'Site transient', 'fanxie-warden' ) : __( 'Transient', 'fanxie-warden' ),
					'date'   => gmdate( 'c', (int) $row['option_value'] ),
				];
			}
		}

		return array_slice( $out, 0, $n );
	}

	/**
	 * Delete expired transients via core, in a single pass.
	 *
	 * @param int              $limit   Unused; core deletes in one pass.
	 * @param list<int|string> $exclude Unused.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		$before = $this->count();

		if ( 0 === $before ) {
			return new BatchResult( 0, [], true );
		}

		delete_expired_transients( true );

		// Core only removes timeout rows that still have a value row; sweep the orphans so count() can reach 0.
		$this->delete_orphaned_timeouts( max( 1, $limit ) );

		// Core deletes with raw SQL, so drop the in-request options cache to avoid stale reads.
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'options' );
		}

		return new BatchResult( max( 0, $before - $this->count() ), [], true );
	}

	/**
	 * Delete expired timeout rows that have no matching value row.
	 *
	 * @param int $limit Rows deleted per round trip.
	 */
	private function delete_orphaned_timeouts( int $limit ): void {
		global $wpdb;

		foreach ( self::KINDS as $value_prefix => $timeout_prefix ) {
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- orphan lookup; core table name.
				$names = (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT t.option_name FROM {$wpdb->options} t
						LEFT JOIN {$wpdb->options} v ON v.option_name = CONCAT(%s, SUBSTRING(t.option_name, %d))
						WHERE t.option_name LIKE %s AND t.option_value < %d AND v.option_id IS NULL
						LIMIT %d",
						$value_prefix,
						strlen( $timeout_prefix ) + 1,
						$wpdb->esc_like( $timeout_prefix ) . '%',
						time(),
						$limit
					)
				);

				$fetched = count( $names );
				$removed = 0;
				foreach ( $names as $name ) {
					if ( delete_option( (string) $name ) ) {
						++$removed;
					}
				}
			} while ( $removed > 0 && $fetched >= $limit );
		}
	}
}
