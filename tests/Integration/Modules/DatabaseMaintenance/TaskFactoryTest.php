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

	public function test_cli_set_allows_all_transients_and_clamps_overrides(): void {
		$f = new TaskFactory( Settings::from_array( [] ) );

		$this->assertInstanceOf( AllTransientsTask::class, $f->cli_task( 'all-transients' ) );
		$this->assertNotNull( $f->cli_task( 'revisions', [ 'keep' => 999 ] ) );
	}
}
