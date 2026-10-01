<?php
/**
 * Auto-draft posts older than N days.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes stale auto-draft posts.
 */
final class AutoDraftsTask implements CleanupTask {

	use DeletesById;

	/**
	 * Constructor.
	 *
	 * @param int $days Minimum age in days.
	 */
	public function __construct( private readonly int $days ) {}

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'auto-drafts';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'Auto-drafts', 'fanxie-warden' );
	}

	/**
	 * Site-local cutoff: core zeroes post_date_gmt on auto-drafts.
	 */
	private function cutoff(): string {
		$timestamp = time() - $this->days * DAY_IN_SECONDS;

		// wp_date() returns false only on an invalid timezone; fall back to GMT.
		$local = wp_date( 'Y-m-d H:i:s', $timestamp );

		return false !== $local ? $local : gmdate( 'Y-m-d H:i:s', $timestamp );
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
		[ $not_in, $args ] = SqlHelpers::not_in( 'ID', array_map( 'intval', $exclude ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- placeholders only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s{$not_in} ORDER BY ID ASC LIMIT %d", array_merge( [ $this->cutoff() ], $args, [ $limit ] ) ) );

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Number of matching posts.
	 */
	public function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s", $this->cutoff() ) );
	}

	/**
	 * Estimated reclaimable bytes.
	 */
	public function estimate_bytes(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live estimate.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)), 0) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s", $this->cutoff() ) );
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
				fn ( int $id ): array => [
					'label'  => $this->post_label( $id ),
					'detail' => (string) get_post_type( $id ),
					'date'   => (string) get_post_time( 'c', false, $id ),
				],
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
