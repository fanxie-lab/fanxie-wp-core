<?php
/**
 * Shared delete-by-id loop and post label helper for cleanup tasks.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by tasks that delete rows one id at a time.
 */
trait DeletesById {

	/**
	 * Delete each id through the callback and build the batch result.
	 *
	 * @param list<int> $ids    IDs selected for this batch.
	 * @param int       $limit  Batch size the ids were selected with.
	 * @param callable  $delete Receives one int id; truthy on success.
	 */
	protected function delete_ids( array $ids, int $limit, callable $delete ): BatchResult { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<int> narrows the plain array hint for PHPStan.
		$deleted = 0;
		$failed  = [];

		foreach ( $ids as $id ) {
			if ( $delete( $id ) ) {
				++$deleted;
			} else {
				$failed[] = $id;
			}
		}

		return new BatchResult( $deleted, $failed, count( $ids ) < $limit );
	}

	/**
	 * Post title, or an "Untitled #ID" fallback.
	 *
	 * @param int $id Post ID.
	 */
	protected function post_label( int $id ): string {
		$title = get_the_title( $id );

		/* translators: %d: post ID */
		return '' !== $title ? $title : sprintf( __( 'Untitled #%d', 'fanxie-warden' ), $id );
	}
}
