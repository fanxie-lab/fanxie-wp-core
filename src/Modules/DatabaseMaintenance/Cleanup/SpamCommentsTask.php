<?php
/**
 * Spam comments older than N days.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes old spam comments.
 */
final class SpamCommentsTask implements CleanupTask {

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
		return 'spam-comments';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'Spam comments', 'fanxie-warden' );
	}

	/**
	 * GMT cutoff.
	 */
	private function cutoff(): string {
		return gmdate( 'Y-m-d H:i:s', time() - $this->days * DAY_IN_SECONDS );
	}

	/**
	 * Matching comment IDs.
	 *
	 * @param int              $limit   Max rows.
	 * @param list<int|string> $exclude IDs to skip.
	 * @return list<int>
	 */
	private function ids( int $limit, array $exclude = [] ): array {
		global $wpdb;
		[ $not_in, $args ] = SqlHelpers::not_in( 'comment_ID', array_map( 'intval', $exclude ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- placeholders only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_date_gmt < %s{$not_in} ORDER BY comment_ID ASC LIMIT %d", array_merge( [ $this->cutoff() ], $args, [ $limit ] ) ) );

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Number of matching comments.
	 */
	public function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_date_gmt < %s", $this->cutoff() ) );
	}

	/**
	 * Estimated reclaimable bytes.
	 */
	public function estimate_bytes(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live estimate.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(comment_content) + LENGTH(comment_author) + LENGTH(comment_author_email) + LENGTH(comment_author_url)), 0) FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_date_gmt < %s", $this->cutoff() ) );
	}

	/**
	 * Sample rows for the preview.
	 *
	 * @param int $n Max rows.
	 * @return list<array{label: string, detail: string, date: string}>
	 */
	public function sample( int $n ): array {
		$out = [];
		foreach ( $this->ids( $n ) as $id ) {
			$comment = get_comment( $id );
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$out[] = [
				'label'  => '' !== $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'fanxie-warden' ),
				'detail' => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 12 ),
				'date'   => gmdate( 'c', (int) strtotime( $comment->comment_date_gmt . ' UTC' ) ),
			];
		}
		return $out;
	}

	/**
	 * Delete one batch.
	 *
	 * @param int              $limit   Batch size.
	 * @param list<int|string> $exclude IDs to skip.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		return $this->delete_ids( $this->ids( $limit, $exclude ), $limit, static fn ( int $id ) => wp_delete_comment( $id, true ) );
	}
}
