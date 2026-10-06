<?php
/**
 * Contract every Database Maintenance cleanup implements.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

interface CleanupTask {

	/**
	 * Task identifier for locking and state tracking.
	 */
	public function id(): string;

	/**
	 * Human-readable task name for UI display.
	 */
	public function label(): string;

	/**
	 * Rows a full purge would delete right now.
	 */
	public function count(): int;

	/**
	 * Approximate bytes those rows occupy (display only).
	 */
	public function estimate_bytes(): int;

	/**
	 * Up to $n human-readable rows for the dry-run preview.
	 *
	 * @param int $n Maximum number of samples to return.
	 * @return list<array{label: string, detail: string, date: ?string}>
	 */
	public function sample( int $n ): array;

	/**
	 * Delete up to $limit rows, skipping identifiers in $exclude (rows that
	 * failed earlier in the same run). `exhausted` is true when fewer than
	 * $limit candidates were found — i.e. nothing is left after this batch.
	 *
	 * @param int              $limit   Maximum rows to delete in this batch.
	 * @param list<int|string> $exclude Identifiers that failed previously.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult;
}
