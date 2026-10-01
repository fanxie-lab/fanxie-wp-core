<?php
/**
 * Database Maintenance settings value object.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, always-clamped view of the module settings. Every consumer
 * (tasks, scheduler, CLI, AJAX) reads parameters through this class so a
 * hand-edited option can never push a cleanup out of range.
 */
final class Settings {

	/**
	 * Numeric setting ranges.
	 *
	 * @var array<string, array{0:int,1:int,2:int}> key => [min, max, default]
	 */
	public const RANGES = [
		'revisions_keep'  => [ 0, 50, 20 ],
		'auto_draft_days' => [ 1, 365, 7 ],
		'trash_days'      => [ 1, 365, 30 ],
		'spam_days'       => [ 1, 365, 15 ],
		'schedule_hour'   => [ 0, 23, 3 ],
	];

	/**
	 * Schedulable cleanup task ids and whether each is enabled by default.
	 *
	 * @var array<string, bool>
	 */
	public const SCHEDULABLE_DEFAULTS = [
		'revisions'            => false,
		'expired-transients'   => true,
		'orphaned-postmeta'    => true,
		'orphaned-usermeta'    => true,
		'orphaned-termmeta'    => true,
		'orphaned-commentmeta' => true,
		'auto-drafts'          => true,
		'trashed-posts'        => false,
		'spam-comments'        => true,
	];

	/**
	 * Allowed schedule frequencies.
	 *
	 * @var string[]
	 */
	public const FREQUENCIES = [ 'daily', 'weekly' ];

	/**
	 * Build a settings object from already-sanitised values.
	 *
	 * @param bool                $revision_limit_enabled Whether the revision cap is on.
	 * @param int                 $revisions_keep         Revisions to keep per post.
	 * @param int                 $auto_draft_days        Auto-draft retention in days.
	 * @param int                 $trash_days             Trash retention in days.
	 * @param int                 $spam_days              Spam retention in days.
	 * @param bool                $schedule_enabled       Whether scheduled runs are on.
	 * @param string              $schedule_frequency     Schedule frequency.
	 * @param int                 $schedule_hour          Hour of day (site time).
	 * @param array<string, bool> $schedule_tasks         Task id => enabled.
	 */
	private function __construct(
		public readonly bool $revision_limit_enabled,
		public readonly int $revisions_keep,
		public readonly int $auto_draft_days,
		public readonly int $trash_days,
		public readonly int $spam_days,
		public readonly bool $schedule_enabled,
		public readonly string $schedule_frequency,
		public readonly int $schedule_hour,
		public readonly array $schedule_tasks,
	) {}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'revision_limit_enabled' => false,
			'revisions_keep'         => self::RANGES['revisions_keep'][2],
			'auto_draft_days'        => self::RANGES['auto_draft_days'][2],
			'trash_days'             => self::RANGES['trash_days'][2],
			'spam_days'              => self::RANGES['spam_days'][2],
			'schedule_enabled'       => false,
			'schedule_frequency'     => 'weekly',
			'schedule_hour'          => self::RANGES['schedule_hour'][2],
			'schedule_tasks'         => self::SCHEDULABLE_DEFAULTS,
		];
	}

	/**
	 * Build a clamped settings object from a raw stored array.
	 *
	 * @param array<string, mixed> $raw Raw option value.
	 */
	public static function from_array( array $raw ): self {
		return new self(
			(bool) ( $raw['revision_limit_enabled'] ?? false ),
			self::clamp( 'revisions_keep', $raw['revisions_keep'] ?? null ),
			self::clamp( 'auto_draft_days', $raw['auto_draft_days'] ?? null ),
			self::clamp( 'trash_days', $raw['trash_days'] ?? null ),
			self::clamp( 'spam_days', $raw['spam_days'] ?? null ),
			(bool) ( $raw['schedule_enabled'] ?? false ),
			self::sanitize_frequency( $raw['schedule_frequency'] ?? null ),
			self::clamp( 'schedule_hour', $raw['schedule_hour'] ?? null ),
			self::sanitize_schedule_tasks( $raw['schedule_tasks'] ?? [] ),
		);
	}

	/**
	 * Clamp a numeric setting into its allowed range.
	 *
	 * @param string $key   Setting key (a key of RANGES).
	 * @param mixed  $value Raw value.
	 */
	public static function clamp( string $key, mixed $value ): int {
		$range = self::RANGES[ $key ] ?? null;
		if ( null === $range ) {
			return 0;
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_numeric( $value ) ) {
			return $range[2];
		}

		$number = (int) round( (float) $value );

		return max( $range[0], min( $range[1], $number ) );
	}

	/**
	 * Restrict a frequency value to the allowed list.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_frequency( mixed $value ): string {
		return is_string( $value ) && in_array( $value, self::FREQUENCIES, true ) ? $value : 'weekly';
	}

	/**
	 * Normalise the scheduled-task map to the known task ids.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, bool>
	 */
	public static function sanitize_schedule_tasks( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];
		$clean = [];

		foreach ( self::SCHEDULABLE_DEFAULTS as $id => $default ) {
			$clean[ $id ] = array_key_exists( $id, $value ) ? (bool) $value[ $id ] : $default;
		}

		return $clean;
	}

	/**
	 * Export as a plain array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'revision_limit_enabled' => $this->revision_limit_enabled,
			'revisions_keep'         => $this->revisions_keep,
			'auto_draft_days'        => $this->auto_draft_days,
			'trash_days'             => $this->trash_days,
			'spam_days'              => $this->spam_days,
			'schedule_enabled'       => $this->schedule_enabled,
			'schedule_frequency'     => $this->schedule_frequency,
			'schedule_hour'          => $this->schedule_hour,
			'schedule_tasks'         => $this->schedule_tasks,
		];
	}
}
