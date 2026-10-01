<?php
/**
 * `wp fx-warden db` — database cleanup from the command line.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cli;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\BatchResult;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\OrphanedMetaTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\TaskFactory;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Settings;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Database cleanup commands.
 */
final class DbCommand {

	/**
	 * Constructor.
	 *
	 * @param TaskFactory   $factory Task factory.
	 * @param CleanupRunner $runner  Batch runner.
	 */
	public function __construct(
		private readonly TaskFactory $factory,
		private readonly CleanupRunner $runner,
	) {}

	/**
	 * Show counts and approximate sizes for every cleanup.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function status( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$rows = [];
		foreach ( TaskFactory::ADMIN_IDS as $id ) {
			$task = $this->factory->cli_task( $id );
			if ( null === $task ) {
				continue;
			}
			$rows[] = [
				'id'    => $id,
				'label' => $task->label(),
				'count' => $task->count(),
				'size'  => size_format( $task->estimate_bytes() ),
			];
		}

		$format = in_array( $assoc_args['format'] ?? 'table', [ 'table', 'json', 'csv' ], true ) ? (string) ( $assoc_args['format'] ?? 'table' ) : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'label', 'count', 'size' ] );
	}

	/**
	 * Run every cleanup the admin screen offers (never deletes valid transients).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Required, to make the intent explicit.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function clean( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		if ( empty( $assoc_args['all'] ) ) {
			WP_CLI::error( __( 'Pass --all to run every cleanup.', 'fanxie-warden' ) );
			return;
		}
		$this->execute( TaskFactory::ADMIN_IDS, [], $assoc_args );
	}

	/**
	 * Delete old post revisions.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<n>]
	 * : Revisions to keep per post (0-50). Defaults to the saved setting.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function revisions( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$overrides = $this->range_flag( $assoc_args, 'keep', 'revisions_keep' );
		if ( null === $overrides ) {
			return;
		}
		$this->execute( [ 'revisions' ], $overrides, $assoc_args );
	}

	/**
	 * Delete expired transients.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete every transient, not only expired ones.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function transients( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$this->execute( [ ! empty( $assoc_args['all'] ) ? 'all-transients' : 'expired-transients' ], [], $assoc_args );
	}

	/**
	 * Delete orphaned meta rows.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : post, user, term or comment. Defaults to all four.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function orphans( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$types = OrphanedMetaTask::TYPES;
		if ( isset( $assoc_args['type'] ) ) {
			if ( ! in_array( $assoc_args['type'], OrphanedMetaTask::TYPES, true ) ) {
				WP_CLI::error( __( '--type must be post, user, term or comment.', 'fanxie-warden' ) );
				return;
			}
			$types = [ (string) $assoc_args['type'] ];
		}
		$this->execute( array_map( static fn ( string $t ): string => "orphaned-{$t}meta", $types ), [], $assoc_args );
	}

	/**
	 * Permanently delete old trashed posts.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Minimum age in days (1-365). Defaults to the saved setting.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function trash( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$this->days_command( 'trashed-posts', 'trash_days', $assoc_args );
	}

	/**
	 * Permanently delete old spam comments.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Minimum age in days (1-365). Defaults to the saved setting.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function spam( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$this->days_command( 'spam-comments', 'spam_days', $assoc_args );
	}

	/**
	 * Permanently delete old auto-drafts.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Minimum age in days (1-365). Defaults to the saved setting.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function autodrafts( array $args = [], array $assoc_args = [] ): void {
		unset( $args );
		$this->days_command( 'auto-drafts', 'auto_draft_days', $assoc_args );
	}

	/**
	 * Run a single-task command that takes an optional --days flag.
	 *
	 * @param string               $id         Task id.
	 * @param string               $range_key  Settings::RANGES key.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function days_command( string $id, string $range_key, array $assoc_args ): void {
		$overrides = $this->range_flag( $assoc_args, 'days', $range_key );
		if ( null === $overrides ) {
			return;
		}
		$this->execute( [ $id ], $overrides, $assoc_args );
	}

	/**
	 * Validate an optional numeric flag strictly (no silent clamping on the CLI).
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 * @param string               $flag       Flag name.
	 * @param string               $range_key  Settings::RANGES key.
	 * @return array<string, int>|null Null after reporting an error.
	 */
	private function range_flag( array $assoc_args, string $flag, string $range_key ): ?array {
		if ( ! isset( $assoc_args[ $flag ] ) ) {
			return [];
		}

		[ $min, $max ] = Settings::RANGES[ $range_key ];
		$raw           = is_scalar( $assoc_args[ $flag ] ) ? (string) $assoc_args[ $flag ] : '';

		if ( ! ctype_digit( $raw ) || (int) $raw < $min || (int) $raw > $max ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: flag name, 2: minimum, 3: maximum */
					__( '--%1$s must be a whole number from %2$d to %3$d.', 'fanxie-warden' ),
					$flag,
					$min,
					$max
				)
			);
			return null;
		}

		return [ $flag => (int) $raw ];
	}

	/**
	 * Count, confirm and purge the given tasks.
	 *
	 * @param array<int, string>   $ids        Task ids.
	 * @param array<string, int>   $overrides  Task overrides.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function execute( array $ids, array $overrides, array $assoc_args ): void {
		/**
		 * Tasks to run, keyed by id.
		 *
		 * @var array<string, CleanupTask> $tasks
		 */
		$tasks = [];
		foreach ( $ids as $id ) {
			$task = $this->factory->cli_task( $id, $overrides );
			if ( null !== $task ) {
				$tasks[ $id ] = $task;
			}
		}

		$total = 0;
		foreach ( $tasks as $task ) {
			$count  = $task->count();
			$total += $count;
			WP_CLI::log( sprintf( '%s: %d (≈ %s)', $task->label(), $count, size_format( $task->estimate_bytes() ) ) );

			if ( ! empty( $assoc_args['dry-run'] ) ) {
				foreach ( $task->sample( 10 ) as $row ) {
					WP_CLI::log( sprintf( '  - %s — %s', $row['label'], $row['detail'] ) );
				}
			}
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( __( 'Dry run: nothing was deleted.', 'fanxie-warden' ) );
			return;
		}

		if ( 0 === $total ) {
			WP_CLI::success( __( 'Nothing to clean.', 'fanxie-warden' ) );
			return;
		}

		WP_CLI::confirm(
			/* translators: %d: number of rows */
			sprintf( _n( 'Permanently delete %d row?', 'Permanently delete %d rows?', $total, 'fanxie-warden' ), $total ),
			$assoc_args
		);

		$deleted = 0;
		$failed  = 0;
		$bar     = \WP_CLI\Utils\make_progress_bar( __( 'Cleaning', 'fanxie-warden' ), $total );

		foreach ( $tasks as $task ) {
			$result   = $this->runner->run( $task, null, CleanupRunner::DEFAULT_BATCH, static fn ( BatchResult $b ) => $bar->tick( $b->deleted + count( $b->failed ) ) );
			$deleted += $result->deleted;
			$failed  += $result->failed;
		}

		$bar->finish();

		if ( $failed > 0 ) {
			WP_CLI::warning(
				/* translators: %d: rows that could not be deleted */
				sprintf( _n( '%d row could not be deleted.', '%d rows could not be deleted.', $failed, 'fanxie-warden' ), $failed )
			);
		}

		WP_CLI::success(
			/* translators: %d: rows deleted */
			sprintf( _n( 'Deleted %d row.', 'Deleted %d rows.', $deleted, 'fanxie-warden' ), $deleted )
		);
	}
}
