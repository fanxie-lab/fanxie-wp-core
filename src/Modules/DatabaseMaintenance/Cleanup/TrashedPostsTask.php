<?php
/**
 * Trashed posts older than N days, measured from when they were trashed.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes posts that have been in the trash for N days.
 */
final class TrashedPostsTask implements CleanupTask {

	use DeletesById;

	/**
	 * Constructor.
	 *
	 * @param int $days Minimum days in trash.
	 */
	public function __construct( private readonly int $days ) {}

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'trashed-posts';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'Trashed posts', 'fanxie-warden' );
	}

	/**
	 * FROM/WHERE clause + its two arguments. Age = _wp_trash_meta_time,
	 * falling back to post_modified_gmt when the meta is missing.
	 *
	 * @return array{0: string, 1: list<int|string>}
	 */
	private function where(): array {
		global $wpdb;
		$cutoff = time() - $this->days * DAY_IN_SECONDS;

		return [
			"FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time'
			WHERE p.post_status = 'trash'
			AND ( ( m.meta_value IS NOT NULL AND CAST(m.meta_value AS UNSIGNED) < %d )
				OR ( m.meta_value IS NULL AND p.post_modified_gmt < %s ) )",
			[ $cutoff, gmdate( 'Y-m-d H:i:s', $cutoff ) ],
		];
	}

	/**
	 * Matching post IDs.
	 *
	 * @param int              $limit   Max rows.
	 * @param list<int|string> $exclude IDs to skip.
	 * @return list<int>
	 */
	private function ids( int $limit, array $exclude = [] ): array {
		global $wpdb;
		[ $from, $args ]          = $this->where();
		[ $not_in, $not_in_args ] = SqlHelpers::not_in( 'p.ID', array_map( 'intval', $exclude ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- clause built from literals + placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID {$from}{$not_in} ORDER BY p.ID ASC LIMIT %d", array_merge( $args, $not_in_args, [ $limit ] ) ) );

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Number of matching posts.
	 */
	public function count(): int {
		global $wpdb;
		[ $from, $args ] = $this->where();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- see ids().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) );
	}

	/**
	 * Estimated reclaimable bytes.
	 */
	public function estimate_bytes(): int {
		global $wpdb;
		[ $from, $args ] = $this->where();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- see ids().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(p.post_content) + LENGTH(p.post_title) + LENGTH(p.post_excerpt)), 0) {$from}", $args ) );
	}

	/**
	 * Sample rows for the preview.
	 *
	 * @param int $n Max rows.
	 * @return list<array{label: string, detail: string, date: string}>
	 */
	public function sample( int $n ): array {
		return array_values(
			array_map(
				function ( int $id ): array {
					$trashed = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
					return [
						'label'  => $this->post_label( $id ),
						'detail' => (string) get_post_type( $id ),
						'date'   => $trashed > 0 ? gmdate( 'c', $trashed ) : (string) get_post_modified_time( 'c', true, $id ),
					];
				},
				$this->ids( $n )
			)
		);
	}

	/**
	 * Delete one batch.
	 *
	 * @param int              $limit   Batch size.
	 * @param list<int|string> $exclude IDs to skip.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		return $this->delete_ids( $this->ids( $limit, $exclude ), $limit, static fn ( int $id ) => wp_delete_post( $id, true ) );
	}
}
