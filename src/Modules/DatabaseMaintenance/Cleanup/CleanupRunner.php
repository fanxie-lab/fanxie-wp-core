<?php
/**
 * Runs one cleanup task under a time budget and a per-task lock.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one cleanup task under a time budget and a per-task lock.
 */
final class CleanupRunner {

	public const LOCK_PREFIX   = 'fanxie_warden_db_lock_';
	public const LOCK_TTL      = 60;
	public const DEFAULT_BATCH = 100;

	/**
	 * Clock function for testing (injects time).
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Create a cleanup runner.
	 *
	 * @param Closure|null $clock Optional clock for testing (returns float seconds).
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): float => microtime( true );
	}

	/**
	 * Run a cleanup task under time budget.
	 *
	 * @param CleanupTask   $task             The task to run.
	 * @param float|null    $budget_seconds   Time budget in seconds; null = run to completion.
	 * @param int           $batch_size       Rows per purge batch.
	 * @param callable|null $on_batch         Optional callback receiving each BatchResult.
	 */
	public function run( CleanupTask $task, ?float $budget_seconds, int $batch_size = self::DEFAULT_BATCH, ?callable $on_batch = null ): RunResult {
		$lock = self::LOCK_PREFIX . $task->id();

		if ( false !== get_transient( $lock ) ) {
			return new RunResult( 0, 0, $task->count(), false, true );
		}

		set_transient( $lock, 1, self::LOCK_TTL );

		$start   = ( $this->clock )();
		$deleted = 0;
		$failed  = [];
		$done    = false;

		try {
			do {
				$batch    = $task->purge_batch( max( 1, $batch_size ), $failed );
				$deleted += $batch->deleted;
				$failed   = array_merge( $failed, $batch->failed );

				set_transient( $lock, 1, self::LOCK_TTL );

				if ( null !== $on_batch ) {
					$on_batch( $batch );
				}

				if ( $batch->exhausted || ( 0 === $batch->deleted && [] === $batch->failed ) ) {
					$done = true;
					break;
				}
			} while ( null === $budget_seconds || ( ( $this->clock )() - $start ) < $budget_seconds );
		} finally {
			delete_transient( $lock );
		}

		$remaining = $done ? 0 : max( 0, $task->count() - count( $failed ) );

		return new RunResult( $deleted, count( $failed ), $remaining, $done, false );
	}
}
