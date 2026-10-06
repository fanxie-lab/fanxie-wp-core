<?php
/**
 * Integration tests for the WP-Cron cleanup schedule.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use Closure;
use FanxieLab\Warden\Modules\DatabaseMaintenance\ScheduledCleanup;

/**
 * Scheduling and continuation behaviour.
 */
final class ScheduledCleanupIntegrationTest extends DatabaseMaintenanceTestCase {

	public function test_saving_enabled_schedule_creates_one_recurring_event(): void {
		$this->dispatch(
			'database-maintenance/save-config',
			[
				'settings' => [
					'schedule_enabled'   => true,
					'schedule_frequency' => 'daily',
					'schedule_hour'      => 4,
				],
			]
		);

		$event = wp_get_scheduled_event( ScheduledCleanup::RUN_HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 'daily', $event->schedule );
		$this->assertSame( '04', wp_date( 'H', $event->timestamp ) );

		$config = $this->dispatch( 'database-maintenance/get-config' )['data'];
		$this->assertNotNull( $config['next_run'] );
	}

	public function test_disabling_clears_both_events(): void {
		$this->dispatch( 'database-maintenance/save-config', [ 'settings' => [ 'schedule_enabled' => true ] ] );
		wp_schedule_single_event( time() + 300, ScheduledCleanup::CONTINUE_HOOK, [ [ 'revisions' ] ] );

		$this->dispatch( 'database-maintenance/save-config', [ 'settings' => [ 'schedule_enabled' => false ] ] );

		$this->assertFalse( wp_next_scheduled( ScheduledCleanup::RUN_HOOK ) );
		$this->assertFalse( wp_get_scheduled_event( ScheduledCleanup::CONTINUE_HOOK, [ [ 'revisions' ] ] ) );
	}

	public function test_run_purges_only_scheduled_tasks(): void {
		$post  = self::factory()->post->create();
		$spam  = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => '2020-01-01 00:00:00',
			]
		);
		$trash = self::factory()->post->create();
		wp_trash_post( $trash );
		update_post_meta( $trash, '_wp_trash_meta_time', time() - 90 * DAY_IN_SECONDS );

		$this->module()->update_config( [ 'schedule_enabled' => true ] );
		do_action( ScheduledCleanup::RUN_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$this->assertNull( get_comment( $spam ), 'spam-comments is scheduled by default' );
		$this->assertNotNull( get_post( $trash ), 'trashed-posts is not scheduled by default' );
	}

	public function test_exhausted_budget_schedules_a_continuation(): void {
		$this->module()->update_config(
			[
				'schedule_enabled' => true,
				'schedule_tasks'   => [ 'spam-comments' => true ],
			]
		);
		$clock     = static function (): float {
			static $t = 0.0;
			$t       += 25.0;
			return $t;
		};
		$scheduler = new ScheduledCleanup( $this->module(), Closure::fromCallable( $clock ) );

		$scheduler->run();

		$this->assertNotFalse(
			wp_next_scheduled(
				ScheduledCleanup::CONTINUE_HOOK,
				[ array_keys( $this->module()->task_factory()->scheduled_tasks() ) ]
			)
		);
	}

	public function test_ensure_scheduled_self_heals_after_deactivation(): void {
		$this->module()->update_config( [ 'schedule_enabled' => true ] );
		wp_clear_scheduled_hook( ScheduledCleanup::RUN_HOOK );

		$this->module()->scheduler()->ensure_scheduled( $this->module()->settings() );

		$this->assertNotFalse( wp_next_scheduled( ScheduledCleanup::RUN_HOOK ) );
	}

	public function test_ensure_scheduled_corrects_a_drifted_hour(): void {
		$this->module()->update_config(
			[
				'schedule_enabled'   => true,
				'schedule_frequency' => 'weekly',
				'schedule_hour'      => 4,
			]
		);
		$this->module()->scheduler()->reschedule( $this->module()->settings() );

		$drifted = ScheduledCleanup::next_occurrence( 5, time(), wp_timezone() );
		wp_clear_scheduled_hook( ScheduledCleanup::RUN_HOOK );
		wp_schedule_event( $drifted, 'weekly', ScheduledCleanup::RUN_HOOK );
		$this->assertSame( '05', wp_date( 'H', (int) wp_next_scheduled( ScheduledCleanup::RUN_HOOK ) ) );

		$this->module()->scheduler()->ensure_scheduled( $this->module()->settings() );

		$this->assertSame( '04', wp_date( 'H', (int) wp_next_scheduled( ScheduledCleanup::RUN_HOOK ) ) );
	}

	/**
	 * Create an old spam comment and an old trashed post.
	 *
	 * @return array{0:int,1:int} Comment id, trashed post id.
	 */
	private function make_old_spam_and_trash(): array {
		$post  = self::factory()->post->create();
		$spam  = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => '2020-01-01 00:00:00',
			]
		);
		$trash = self::factory()->post->create();
		wp_trash_post( $trash );
		update_post_meta( $trash, '_wp_trash_meta_time', time() - 90 * DAY_IN_SECONDS );

		return [ $spam, $trash ];
	}

	public function test_continue_run_only_runs_ids_that_are_still_scheduled(): void {
		[ $spam, $trash ] = $this->make_old_spam_and_trash();
		$this->module()->update_config(
			[
				'schedule_enabled' => true,
				'schedule_tasks'   => [ 'spam-comments' => true ],
			]
		);

		do_action( ScheduledCleanup::CONTINUE_HOOK, [ 'spam-comments', 'trashed-posts' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$this->assertNull( get_comment( $spam ) );
		$this->assertNotNull( get_post( $trash ) );
	}

	public function test_continue_run_does_nothing_when_schedule_disabled(): void {
		[ $spam ] = $this->make_old_spam_and_trash();
		$this->module()->update_config( [ 'schedule_enabled' => false ] );

		$this->module()->scheduler()->continue_run( [ 'spam-comments' ] );

		$this->assertNotNull( get_comment( $spam ) );
	}

	public function test_continue_run_keeps_valid_transients(): void {
		set_transient( 'fanxie_warden_t9_valid', 'x', HOUR_IN_SECONDS );
		$this->module()->update_config(
			[
				'schedule_enabled' => true,
				'schedule_tasks'   => [ 'all-transients' => true ],
			]
		);

		$this->module()->scheduler()->continue_run( [ 'all-transients' ] );

		$this->assertSame( 'x', get_transient( 'fanxie_warden_t9_valid' ) );
	}
}
