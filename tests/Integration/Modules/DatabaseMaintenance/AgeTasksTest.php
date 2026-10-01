<?php
/**
 * Integration tests for the age-based auto-draft, trash and spam tasks.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\AutoDraftsTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\SpamCommentsTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\TrashedPostsTask;

/**
 * Age-based task tests.
 */
final class AgeTasksTest extends DatabaseMaintenanceTestCase {

	/**
	 * Site-local timestamp N days ago.
	 *
	 * @param int $days Days.
	 */
	private function local_days_ago( int $days ): string {
		return wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Auto-drafts compare local post_date because GMT is zeroed.
	 */
	public function test_auto_drafts_use_local_post_date_because_gmt_is_zeroed(): void {
		global $wpdb;
		$old   = self::factory()->post->create(
			[
				'post_status' => 'auto-draft',
				'post_date'   => $this->local_days_ago( 8 ),
			]
		);
		$young = self::factory()->post->create(
			[
				'post_status' => 'auto-draft',
				'post_date'   => $this->local_days_ago( 6 ),
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => '0000-00-00 00:00:00' ], [ 'post_status' => 'auto-draft' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		clean_post_cache( $old );
		clean_post_cache( $young );

		$task = new AutoDraftsTask( 7 );
		$this->assertSame( 1, $task->count() );

		( new CleanupRunner() )->run( $task, null );

		$this->assertNull( get_post( $old ) );
		$this->assertNotNull( get_post( $young ) );
	}

	/**
	 * Trash age comes from the trash meta, falling back to post_modified_gmt.
	 */
	public function test_trash_age_comes_from_trash_time_not_last_edit(): void {
		global $wpdb;
		$edited_long_ago_trashed_now = self::factory()->post->create( [ 'post_modified' => $this->local_days_ago( 400 ) ] );
		wp_trash_post( $edited_long_ago_trashed_now );

		$trashed_long_ago = self::factory()->post->create();
		wp_trash_post( $trashed_long_ago );
		update_post_meta( $trashed_long_ago, '_wp_trash_meta_time', time() - 31 * DAY_IN_SECONDS );

		$no_meta = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ], [ 'ID' => $no_meta ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		clean_post_cache( $no_meta );

		$task = new TrashedPostsTask( 30 );
		$this->assertSame( 2, $task->count() );

		( new CleanupRunner() )->run( $task, null );

		$this->assertNotNull( get_post( $edited_long_ago_trashed_now ) );
		$this->assertNull( get_post( $trashed_long_ago ) );
		$this->assertNull( get_post( $no_meta ) );
	}

	/**
	 * Only old spam is deleted.
	 */
	public function test_spam_older_than_threshold_is_deleted_and_younger_or_approved_kept(): void {
		$post  = self::factory()->post->create();
		$old   = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 16 * DAY_IN_SECONDS ),
			]
		);
		$young = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ),
			]
		);
		$ham   = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 99 * DAY_IN_SECONDS ),
			]
		);

		$task = new SpamCommentsTask( 15 );
		$this->assertSame( 1, $task->count() );
		$this->assertNotEmpty( $task->sample( 10 ) );

		( new CleanupRunner() )->run( $task, null );

		$this->assertNull( get_comment( $old ) );
		$this->assertNotNull( get_comment( $young ) );
		$this->assertNotNull( get_comment( $ham ) );
	}
}
