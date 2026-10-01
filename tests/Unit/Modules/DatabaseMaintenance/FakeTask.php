<?php
/**
 * Test double for cleanup task.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\BatchResult;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupTask;

/**
 * In-memory cleanup task: rows are integers; ids listed in $poison always fail.
 */
final class FakeTask implements CleanupTask {
	/** @var list<int> */
	public array $rows;
	/** @var list<int> */
	public array $poison;
	/** @var list<array<int,int|string>> */
	public array $seen_excludes = [];

	/**
	 * Create a fake task with specified row count.
	 *
	 * @param int            $rows   Number of fake rows to create.
	 * @param list<int>|null $poison Row IDs that will fail to delete.
	 */
	public function __construct( int $rows, array $poison = [] ) {
		$this->rows   = range( 1, $rows );
		$this->poison = $poison;
	}

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function count(): int {
		return count( $this->rows );
	}

	public function estimate_bytes(): int {
		return 10 * count( $this->rows );
	}

	public function sample( int $n ): array {
		return [];
	}

	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		$this->seen_excludes[] = $exclude;
		$candidates = array_values( array_diff( $this->rows, $exclude ) );
		$batch      = array_slice( $candidates, 0, $limit );
		$deleted    = 0;
		$failed     = [];
		foreach ( $batch as $id ) {
			if ( in_array( $id, $this->poison, true ) ) {
				$failed[] = $id;
				continue;
			}
			$this->rows = array_values( array_diff( $this->rows, [ $id ] ) );
			++$deleted;
		}
		return new BatchResult( $deleted, $failed, count( $batch ) < $limit );
	}
}
