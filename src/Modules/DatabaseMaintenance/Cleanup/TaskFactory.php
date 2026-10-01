<?php
/**
 * Builds cleanup task sets from settings.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds cleanup task sets from settings.
 *
 * Not final: DbCommandTest substitutes it with a PHPUnit mock.
 */
class TaskFactory {

	/**
	 * Task IDs that appear in the admin UI.
	 *
	 * @var array<int, string>
	 */
	public const ADMIN_IDS = [
		'revisions',
		'expired-transients',
		'orphaned-postmeta',
		'orphaned-usermeta',
		'orphaned-termmeta',
		'orphaned-commentmeta',
		'auto-drafts',
		'trashed-posts',
		'spam-comments',
	];

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings value object.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Instantiate a task by ID, optionally with parameter overrides.
	 *
	 * @param string               $id        Task ID.
	 * @param array<string, mixed> $overrides Parameter overrides.
	 * @return CleanupTask|null Task instance, or null if $id is unrecognized.
	 */
	private function build( string $id, array $overrides = [] ): ?CleanupTask {
		$keep = isset( $overrides['keep'] ) ? Settings::clamp( 'revisions_keep', $overrides['keep'] ) : $this->settings->revisions_keep;
		$days = static fn ( string $key, int $fallback ): int => isset( $overrides['days'] ) ? Settings::clamp( $key, $overrides['days'] ) : $fallback;

		return match ( $id ) {
			'revisions'            => new RevisionsTask( $keep ),
			'expired-transients'   => new ExpiredTransientsTask(),
			'all-transients'       => new AllTransientsTask(),
			'orphaned-postmeta'    => new OrphanedMetaTask( 'post' ),
			'orphaned-usermeta'    => new OrphanedMetaTask( 'user' ),
			'orphaned-termmeta'    => new OrphanedMetaTask( 'term' ),
			'orphaned-commentmeta' => new OrphanedMetaTask( 'comment' ),
			'auto-drafts'          => new AutoDraftsTask( $days( 'auto_draft_days', $this->settings->auto_draft_days ) ),
			'trashed-posts'        => new TrashedPostsTask( $days( 'trash_days', $this->settings->trash_days ) ),
			'spam-comments'        => new SpamCommentsTask( $days( 'spam_days', $this->settings->spam_days ) ),
			default                => null,
		};
	}

	/**
	 * All tasks available in the admin UI, in order of ADMIN_IDS.
	 *
	 * @return array<string, CleanupTask> Map of task ID to task instance.
	 */
	public function admin_tasks(): array {
		$out = [];
		foreach ( self::ADMIN_IDS as $id ) {
			$task = $this->build( $id );
			if ( null !== $task ) {
				$out[ $id ] = $task;
			}
		}
		return $out;
	}

	/**
	 * Get a task by ID if it exists in the admin set.
	 *
	 * @param string $id Task ID.
	 * @return CleanupTask|null Task instance, or null if not in ADMIN_IDS.
	 */
	public function admin_task( string $id ): ?CleanupTask {
		return in_array( $id, self::ADMIN_IDS, true ) ? $this->build( $id ) : null;
	}

	/**
	 * Tasks in the admin set that are enabled for automatic scheduling.
	 *
	 * @return array<string, CleanupTask> Map of task ID to task instance.
	 */
	public function scheduled_tasks(): array {
		return array_filter(
			$this->admin_tasks(),
			fn ( string $id ): bool => ! empty( $this->settings->schedule_tasks[ $id ] ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Get a task by ID for CLI execution, with optional parameter overrides.
	 *
	 * Allows all-transients, unlike admin_task().
	 *
	 * @param string               $id        Task ID.
	 * @param array<string, mixed> $overrides Parameter overrides (keep, days).
	 * @return CleanupTask|null Task instance, or null if unrecognized.
	 */
	public function cli_task( string $id, array $overrides = [] ): ?CleanupTask {
		return $this->build( $id, $overrides );
	}
}
