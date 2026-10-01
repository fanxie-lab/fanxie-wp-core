<?php
/**
 * Excess post revisions beyond the newest N per parent.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Removes revisions beyond the newest N per parent post.
 */
final class RevisionsTask implements CleanupTask {

	use DeletesById;

	// MySQL needs a row count with OFFSET; this is the documented "all rows" idiom.
	private const ALL_ROWS = '18446744073709551615';

	/**
	 * Constructor.
	 *
	 * @param int $keep Newest revisions to keep per parent.
	 */
	public function __construct( private readonly int $keep ) {}

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'revisions';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'Post revisions', 'fanxie-warden' );
	}

	/**
	 * LIKE pattern matching core autosave revisions ("{parent}-autosave-v1").
	 *
	 * Core's own trimming skips autosaves, so they never count toward the limit and are never deleted.
	 */
	private function autosave_pattern(): string {
		global $wpdb;

		return '%' . $wpdb->esc_like( '-autosave-v' ) . '%';
	}

	/**
	 * Revision counts for parents over the limit.
	 *
	 * @return array<int, int> parent ID => revision count.
	 */
	private function parents_over_limit(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts for a maintenance screen; caching would show stale numbers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_parent, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0 AND post_name NOT LIKE %s GROUP BY post_parent HAVING c > %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$this->autosave_pattern(),
				$this->keep
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_parent'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Revision IDs past the newest $keep for one parent.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return list<int>
	 */
	private function excess_ids( int $parent_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- see parents_over_limit(); ALL_ROWS is a constant.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND post_name NOT LIKE %s ORDER BY post_date DESC, ID DESC LIMIT %d, " . self::ALL_ROWS, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$parent_id,
				$this->autosave_pattern(),
				$this->keep
			)
		);

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Revisions a full purge would delete.
	 */
	public function count(): int {
		$total = 0;
		foreach ( $this->parents_over_limit() as $count ) {
			$total += $count - $this->keep;
		}
		return $total;
	}

	/**
	 * Estimated bytes reclaimed.
	 */
	public function estimate_bytes(): int {
		global $wpdb;

		$excess = $this->count();
		if ( 0 === $excess ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see parents_over_limit().
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)), 0) AS bytes FROM {$wpdb->posts} WHERE post_type = 'revision'",
			ARRAY_A
		);

		$n = (int) ( $row['n'] ?? 0 );

		return $n > 0 ? (int) round( (int) $row['bytes'] * ( $excess / $n ) ) : 0;
	}

	/**
	 * Per-parent sample rows.
	 *
	 * @param int $n Max rows.
	 */
	public function sample( int $n ): array {
		$out = [];
		foreach ( $this->parents_over_limit() as $parent_id => $count ) {
			if ( count( $out ) >= $n ) {
				break;
			}
			$title    = get_the_title( $parent_id );
			$modified = get_post_modified_time( 'c', true, $parent_id );
			$out[]    = [
				'label'  => '' !== $title ? $title : sprintf(
					/* translators: %d: post ID */
					__( 'Post #%d', 'fanxie-warden' ),
					$parent_id
				),
				'detail' => sprintf(
					/* translators: 1: revisions that will be removed, 2: revisions stored */
					__( '%1$d of %2$d revisions', 'fanxie-warden' ),
					$count - $this->keep,
					$count
				),
				'date'   => is_string( $modified ) && '' !== $modified ? $modified : null,
			];
		}
		return $out;
	}

	/**
	 * Delete up to $limit excess revisions.
	 *
	 * @param int              $limit   Max rows.
	 * @param list<int|string> $exclude IDs to skip.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		$targets = [];

		foreach ( array_keys( $this->parents_over_limit() ) as $parent_id ) {
			foreach ( $this->excess_ids( $parent_id ) as $id ) {
				if ( in_array( $id, $exclude, true ) ) {
					continue;
				}
				$targets[] = $id;
				if ( count( $targets ) >= $limit ) {
					break 2;
				}
			}
		}

		return $this->delete_ids( $targets, $limit, static fn ( int $id ): bool => wp_delete_post_revision( $id ) instanceof \WP_Post );
	}
}
