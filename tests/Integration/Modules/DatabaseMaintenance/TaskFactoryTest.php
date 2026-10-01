<?php
/**
 * Tests for TaskFactory.
 *
 * @package FanxieLab\Warden\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\AllTransientsTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\TaskFactory;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Settings;

/**
 * TaskFactory test suite.
 */
final class TaskFactoryTest extends DatabaseMaintenanceTestCase {

	public function test_admin_set_is_ordered_and_never_contains_all_transients(): void {
		$f = new TaskFactory( Settings::from_array( [] ) );

		$this->assertSame( TaskFactory::ADMIN_IDS, array_keys( $f->admin_tasks() ) );
		$this->assertNull( $f->admin_task( 'all-transients' ) );
		$this->assertNull( $f->admin_task( 'nope' ) );
	}

	public function test_scheduled_set_follows_settings(): void {
		$f = new TaskFactory( Settings::from_array( [ 'schedule_tasks' => [ 'revisions' => true, 'spam-comments' => false ] ] ) );
		$ids = array_keys( $f->scheduled_tasks() );

		$this->assertContains( 'revisions', $ids );
		$this->assertNotContains( 'spam-comments', $ids );
		$this->assertNotContains( 'trashed-posts', $ids );
	}

	public function test_default_scheduled_tasks_excludes_revisions_and_includes_core_cleanups(): void {
		$f = new TaskFactory( Settings::from_array( [] ) );
		$ids = array_keys( $f->scheduled_tasks() );

		$this->assertSame(
			[ 'expired-transients', 'orphaned-postmeta', 'orphaned-usermeta', 'orphaned-termmeta', 'orphaned-commentmeta', 'auto-drafts', 'spam-comments' ],
			$ids
		);
	}

	public function test_cli_task_allows_all_transients(): void {
		$f = new TaskFactory( Settings::from_array( [] ) );

		$this->assertInstanceOf( AllTransientsTask::class, $f->cli_task( 'all-transients' ) );
	}

	public function test_cli_task_clamps_keep_override_to_max(): void {
		$post_id = self::factory()->post->create();
		for ( $i = 1; $i <= 60; $i++ ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => "rev {$i}",
				]
			);
		}

		$f = new TaskFactory( Settings::from_array( [] ) );

		// Clamping: 999 should be reduced to max 50.
		$count_clamped   = $f->cli_task( 'revisions', [ 'keep' => 999 ] )->count();
		$count_explicit  = $f->cli_task( 'revisions', [ 'keep' => 50 ] )->count();
		$this->assertSame( $count_explicit, $count_clamped );
		// 60 total revisions, keep 50 → 10 excess.
		$this->assertSame( 10, $count_clamped );
	}

	public function test_cli_task_clamps_days_override_to_max(): void {
		global $wpdb;
		self::factory()->post->create(
			[
				'post_status' => 'auto-draft',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ),
			]
		);
		// Auto-drafts use local post_date, but post_date_gmt is zeroed.
		$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => '0000-00-00 00:00:00' ], [ 'post_status' => 'auto-draft' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		wp_cache_flush();

		$f = new TaskFactory( Settings::from_array( [] ) );

		// Clamping: 9999 should be reduced to max 365.
		// 400 > 365, so the 400-day-old draft is included.
		$count = $f->cli_task( 'auto-drafts', [ 'days' => 9999 ] )->count();
		$this->assertSame( 1, $count );
	}

	public function test_cli_task_uses_default_settings_when_no_override(): void {
		$post_id = self::factory()->post->create();
		for ( $i = 1; $i <= 60; $i++ ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => "rev {$i}",
				]
			);
		}

		$f = new TaskFactory( Settings::from_array( [] ) );

		// Default keep is 20, so 60 - 20 = 40 excess.
		$count = $f->cli_task( 'revisions' )->count();
		$this->assertSame( 40, $count );
	}
}
