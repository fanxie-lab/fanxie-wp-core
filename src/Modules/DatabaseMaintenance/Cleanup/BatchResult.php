<?php
/**
 * Outcome of one purge batch.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one purge batch operation.
 */
final class BatchResult {

	/**
	 * Create a batch result.
	 *
	 * @param int              $deleted   Rows successfully deleted.
	 * @param list<int|string> $failed    Identifiers whose delete call failed.
	 * @param bool             $exhausted True if fewer than $limit candidates found.
	 */
	public function __construct(
		public readonly int $deleted,
		public readonly array $failed,
		public readonly bool $exhausted,
	) {}
}
