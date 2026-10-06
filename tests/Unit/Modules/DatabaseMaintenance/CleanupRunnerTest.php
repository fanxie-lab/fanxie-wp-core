<?php
/**
 * Tests for the CleanupRunner class.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CleanupRunner.
 */
final class CleanupRunnerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = [];
		Functions\when( 'get_transient' )->alias( fn ( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $k ) {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_unlimited_budget_runs_to_completion_in_batches(): void {
		$task    = new FakeTask( 1250 );
		$batches = 0;
		$result  = ( new CleanupRunner() )->run(
			$task,
			null,
			500,
			function () use ( &$batches ) {
				++$batches;
			}
		);

		$this->assertTrue( $result->done );
		$this->assertSame( 1250, $result->deleted );
		$this->assertSame( 0, $result->remaining );
		$this->assertSame( 3, $batches );
	}

	public function test_budget_stops_early_and_reports_remaining(): void {
		$t     = 0.0;
		$clock = function () use ( &$t ): float {
			$t += 3.0; // Each clock read advances 3 s.
			return $t;
		};
		$task   = new FakeTask( 2000 );
		$result = ( new CleanupRunner( \Closure::fromCallable( $clock ) ) )->run( $task, 5.0, 500 );

		$this->assertFalse( $result->done );
		$this->assertGreaterThan( 0, $result->deleted );
		$this->assertSame( 2000 - $result->deleted, $result->remaining );
	}

	public function test_failed_rows_are_excluded_so_the_loop_terminates(): void {
		$task   = new FakeTask( 10, [ 2, 4 ] );
		$result = ( new CleanupRunner() )->run( $task, null, 3 );

		$this->assertTrue( $result->done );
		$this->assertSame( 8, $result->deleted );
		$this->assertSame( 2, $result->failed );
		$this->assertSame( 0, $result->remaining, 'Failed rows are not counted as remaining work.' );
		$this->assertContains( 2, end( $task->seen_excludes ) );
	}

	public function test_held_lock_returns_busy_without_touching_rows(): void {
		$this->transients[ CleanupRunner::LOCK_PREFIX . 'fake' ] = 1;
		$task   = new FakeTask( 5 );
		$result = ( new CleanupRunner() )->run( $task, null );

		$this->assertTrue( $result->busy );
		$this->assertFalse( $result->done );
		$this->assertSame( 5, $result->remaining );
		$this->assertSame( 5, $task->count() );
	}

	public function test_lock_is_released_after_the_run(): void {
		( new CleanupRunner() )->run( new FakeTask( 3 ), null );
		$this->assertArrayNotHasKey( CleanupRunner::LOCK_PREFIX . 'fake', $this->transients );
	}

	public function test_to_array_shape_is_the_ajax_contract(): void {
		$result = ( new CleanupRunner() )->run( new FakeTask( 1 ), null );
		$this->assertSame(
			[ 'id', 'deleted', 'failed', 'remaining', 'done', 'busy' ],
			array_keys( $result->to_array( 'fake' ) )
		);
	}
}
