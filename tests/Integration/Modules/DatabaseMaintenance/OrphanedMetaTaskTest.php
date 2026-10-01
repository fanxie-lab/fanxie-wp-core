<?php
/**
 * Integration tests for the orphaned metadata cleanup task.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\OrphanedMetaTask;

/**
 * Orphaned metadata task tests.
 */
final class OrphanedMetaTaskTest extends DatabaseMaintenanceTestCase {

	/**
	 * Insert a meta row pointing at a parent ID that cannot exist.
	 *
	 * @param string $type Meta type.
	 */
	private function orphan( string $type ): void {
		global $wpdb;
		$table = _get_meta_table( $type );
		$col   = sanitize_key( $type . '_id' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
			$table,
			[
				$col         => 99999999,
				'meta_key'   => 'fx_orphan', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture.
				'meta_value' => str_repeat( 'x', 40 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test fixture.
			]
		);
	}

	/**
	 * Only orphaned rows are counted and deleted.
	 *
	 * @dataProvider types
	 *
	 * @param string $type Meta type.
	 */
	public function test_only_orphans_are_counted_and_deleted( string $type ): void {
		$this->orphan( $type );
		$this->orphan( $type );

		$attached = match ( $type ) {
			'post'    => self::factory()->post->create(),
			'user'    => self::factory()->user->create(),
			'term'    => self::factory()->term->create(),
			'comment' => self::factory()->comment->create(),
		};
		add_metadata( $type, $attached, 'fx_keep', 'keep' );

		$task = new OrphanedMetaTask( $type );
		$this->assertSame( "orphaned-{$type}meta", $task->id() );
		$this->assertSame( 2, $task->count() );
		$this->assertGreaterThanOrEqual( 80, $task->estimate_bytes() );
		$this->assertSame( 'fx_orphan', $task->sample( 10 )[0]['label'] );

		$result = ( new CleanupRunner() )->run( $task, null, 1 );

		$this->assertSame( 2, $result->deleted );
		$this->assertSame( 0, $task->count() );
		$this->assertSame( 'keep', get_metadata( $type, $attached, 'fx_keep', true ) );
	}

	/**
	 * Meta types.
	 *
	 * @return array<int, array{0: string}>
	 */
	public static function types(): array {
		return [ [ 'post' ], [ 'user' ], [ 'term' ], [ 'comment' ] ];
	}
}
