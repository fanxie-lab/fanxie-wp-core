<?php
/**
 * Integration tests for the transient cleanup tasks.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\AllTransientsTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\ExpiredTransientsTask;

/**
 * Integration tests for the transient cleanup tasks.
 */
final class TransientTasksTest extends DatabaseMaintenanceTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transients live in the object cache on this environment.' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup of an internal table.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);
		wp_cache_flush();
	}

	private function expire( string $name, bool $site = false ): void {
		$prefix = $site ? '_site_transient_timeout_' : '_transient_timeout_';
		update_option( $prefix . $name, time() - 60, false );
	}

	public function test_counts_and_purges_only_expired(): void {
		set_transient( 'fx_old', 'x', 3600 );
		set_transient( 'fx_fresh', 'y', 3600 );
		set_site_transient( 'fx_site_old', 'z', 3600 );
		$this->expire( 'fx_old' );
		$this->expire( 'fx_site_old', true );

		$task = new ExpiredTransientsTask();
		$this->assertSame( 2, $task->count() );
		$this->assertGreaterThan( 0, $task->estimate_bytes() );
		$this->assertContains( 'fx_old', array_column( $task->sample( 10 ), 'label' ) );

		$result = ( new CleanupRunner() )->run( $task, null );

		$this->assertTrue( $result->done );
		$this->assertFalse( get_option( '_transient_fx_old' ) );
		$this->assertSame( 'y', get_transient( 'fx_fresh' ) );
		$this->assertSame( 0, $task->count() );
	}

	public function test_all_transients_removes_valid_ones_too(): void {
		set_transient( 'fx_a', 'a', 3600 );
		set_transient( 'fx_b', 'b' );
		set_site_transient( 'fx_c', 'c', 3600 );

		$task = new AllTransientsTask();
		$this->assertSame( 3, $task->count() );

		( new CleanupRunner() )->run( $task, null, 2 );

		$this->assertFalse( get_transient( 'fx_a' ) );
		$this->assertFalse( get_transient( 'fx_b' ) );
		$this->assertFalse( get_site_transient( 'fx_c' ) );
		$this->assertFalse( get_option( '_transient_timeout_fx_a' ) );
		$this->assertSame( 0, $task->count() );
	}
}
