<?php
/**
 * Integration tests for the revisions cleanup task.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\RevisionsTask;

/**
 * Revisions task behavior.
 */
final class RevisionsTaskTest extends DatabaseMaintenanceTestCase {

	private function post_with_revisions( int $n ): int {
		$post_id = self::factory()->post->create();
		for ( $i = 1; $i <= $n; $i++ ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => "rev {$i}",
				]
			);
		}
		return $post_id;
	}

	/**
	 * Revision IDs for a post.
	 *
	 * @param int $post_id Parent post ID.
	 * @return list<int> newest first
	 */
	private function revision_ids( int $post_id ): array {
		return array_map(
			'intval',
			array_keys(
				wp_get_post_revisions(
					$post_id,
					[
						'order'   => 'DESC',
						'orderby' => 'date ID',
					]
				)
			)
		);
	}

	public function test_count_is_the_excess_over_keep(): void {
		$a = $this->post_with_revisions( 30 );
		$this->post_with_revisions( 3 );

		$task = new RevisionsTask( 20 );
		$this->assertSame( count( $this->revision_ids( $a ) ) - 20, $task->count() );
	}

	public function test_purge_keeps_exactly_the_newest_n_and_leaves_other_posts_alone(): void {
		$a      = $this->post_with_revisions( 30 );
		$b      = $this->post_with_revisions( 3 );
		$keep_a = array_slice( $this->revision_ids( $a ), 0, 5 );
		$all_b  = $this->revision_ids( $b );

		$result = ( new CleanupRunner() )->run( new RevisionsTask( 5 ), null, 7 );

		$this->assertTrue( $result->done );
		$this->assertSame( $keep_a, $this->revision_ids( $a ) );
		$this->assertSame( array_slice( $all_b, 0, 5 ), $this->revision_ids( $b ) );
		$this->assertNotNull( get_post( $a ), 'The parent post must survive.' );
	}

	public function test_keep_zero_removes_all_revisions_but_not_the_post(): void {
		$a = $this->post_with_revisions( 4 );

		( new CleanupRunner() )->run( new RevisionsTask( 0 ), null );

		$this->assertSame( [], $this->revision_ids( $a ) );
		$this->assertSame( 'publish', get_post_status( $a ) );
	}

	public function test_sample_lists_parent_titles_and_count_matches_purge(): void {
		$a = $this->post_with_revisions( 12 );
		wp_update_post(
			[
				'ID'         => $a,
				'post_title' => 'Pricing page',
			]
		);
		$task   = new RevisionsTask( 2 );
		$before = $task->count();
		$sample = $task->sample( 10 );

		$this->assertNotEmpty( $sample );
		$this->assertStringContainsString( 'Pricing page', $sample[0]['label'] );

		$result = ( new CleanupRunner() )->run( $task, null );
		$this->assertSame( $before, $result->deleted );
		$this->assertSame( 0, $task->count() );
	}

	public function test_estimate_is_positive_when_there_is_excess(): void {
		$this->post_with_revisions( 6 );
		$this->assertGreaterThan( 0, ( new RevisionsTask( 1 ) )->estimate_bytes() );
		$this->assertSame( 0, ( new RevisionsTask( 50 ) )->estimate_bytes() );
	}

	public function test_autosaves_are_neither_counted_nor_deleted(): void {
		$a = $this->post_with_revisions( 3 );
		wp_insert_post(
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $a,
				'post_name'   => $a . '-autosave-v1',
				'post_title'  => 'Autosave',
			]
		);
		$autosave = (int) get_posts(
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'name'        => $a . '-autosave-v1',
				'fields'      => 'ids',
			]
		)[0];

		$task = new RevisionsTask( 0 );
		$this->assertSame( count( $this->revision_ids( $a ) ) - 1, $task->count() );

		( new CleanupRunner() )->run( $task, null );

		$this->assertNotNull( get_post( $autosave ), 'Autosave must survive a keep=0 purge.' );
		$this->assertSame( 0, $task->count() );
	}

	public function test_sample_label_is_raw_plain_text(): void {
		$a = $this->post_with_revisions( 4 );
		wp_update_post(
			[
				'ID'          => $a,
				'post_title'  => 'Tom & Jerry\'s "big" -- day',
				'post_status' => 'private',
			]
		);

		$sample = ( new RevisionsTask( 1 ) )->sample( 10 );

		$this->assertSame( 'Tom & Jerry\'s "big" -- day', $sample[0]['label'] );
	}
}
