<?php
/**
 * Outcome of one runner invocation (one AJAX step, one cron slice, one CLI run).
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one runner invocation.
 */
final class RunResult {

	/**
	 * Create a run result.
	 *
	 * @param int  $deleted   Total rows deleted in this run.
	 * @param int  $failed    Total rows that failed to delete.
	 * @param int  $remaining Rows still pending deletion.
	 * @param bool $done      True if purge is complete.
	 * @param bool $busy      True if another run is in progress.
	 */
	public function __construct(
		public readonly int $deleted,
		public readonly int $failed,
		public readonly int $remaining,
		public readonly bool $done,
		public readonly bool $busy,
	) {}

	/**
	 * Convert result to array form for AJAX response.
	 *
	 * @param string $id Task identifier.
	 * @return array{id: string, deleted: int, failed: int, remaining: int, done: bool, busy: bool}
	 */
	public function to_array( string $id ): array {
		return [
			'id'        => $id,
			'deleted'   => $this->deleted,
			'failed'    => $this->failed,
			'remaining' => $this->remaining,
			'done'      => $this->done,
			'busy'      => $this->busy,
		];
	}
}
