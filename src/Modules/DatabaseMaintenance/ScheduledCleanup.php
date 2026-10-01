<?php
/**
 * WP-Cron driven cleanup schedule with time-budgeted continuation.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the recurring cleanup.
 */
final class ScheduledCleanup {

	public const RUN_HOOK       = 'fanxie_warden_database_maintenance_run';
	public const CONTINUE_HOOK  = 'fanxie_warden_database_maintenance_continue';
	public const BUDGET         = 20.0;
	public const CONTINUE_DELAY = 300;

	/**
	 * Monotonic-ish clock in seconds.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param DatabaseMaintenance $module Owning module.
	 * @param Closure|null        $clock  Clock override for tests.
	 */
	public function __construct( private readonly DatabaseMaintenance $module, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): float => microtime( true );
	}

	/**
	 * Attach the cron callbacks.
	 */
	public function register(): void {
		add_action( self::RUN_HOOK, [ $this, 'run' ] );
		add_action( self::CONTINUE_HOOK, [ $this, 'continue_run' ], 10, 1 );
	}

	/**
	 * Next timestamp at which the site-local wall clock reads $hour:00.
	 *
	 * @param int          $hour Local hour, 0-23.
	 * @param int          $now  Current Unix timestamp.
	 * @param DateTimeZone $tz   Site timezone.
	 */
	public static function next_occurrence( int $hour, int $now, DateTimeZone $tz ): int {
		$local     = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$candidate = $local->setTime( $hour, 0 );

		if ( $candidate->getTimestamp() <= $now ) {
			$candidate = $local->modify( '+1 day' )->setTime( $hour, 0 );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Self-heal on every boot: deactivation clears our hooks.
	 *
	 * @param Settings $s Current settings.
	 */
	public function ensure_scheduled( Settings $s ): void {
		$event = wp_get_scheduled_event( self::RUN_HOOK );

		if ( ! $s->schedule_enabled ) {
			if ( false !== $event ) {
				$this->clear();
			}
			return;
		}

		if ( false === $event || $event->schedule !== $s->schedule_frequency ) {
			$this->reschedule( $s );
		}
	}

	/**
	 * Replace the schedule to match the settings.
	 *
	 * @param Settings $s Current settings.
	 */
	public function reschedule( Settings $s ): void {
		$this->clear();

		if ( ! $s->schedule_enabled ) {
			return;
		}

		wp_schedule_event(
			self::next_occurrence( $s->schedule_hour, time(), wp_timezone() ),
			$s->schedule_frequency,
			self::RUN_HOOK
		);
	}

	/**
	 * Remove both the recurring and continuation events.
	 */
	private function clear(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_unschedule_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Cron callback: run every scheduled task.
	 */
	public function run(): void {
		$this->run_tasks( array_keys( $this->module->task_factory()->scheduled_tasks() ) );
	}

	/**
	 * Cron callback: resume the tasks a previous run did not finish.
	 *
	 * @param mixed $task_ids Task ids from the event args.
	 */
	public function continue_run( $task_ids ): void {
		$allowed = array_keys( $this->module->task_factory()->scheduled_tasks() );
		$ids     = is_array( $task_ids ) ? array_values( array_intersect( array_map( 'strval', $task_ids ), $allowed ) ) : [];

		$this->run_tasks( $ids );
	}

	/**
	 * Run tasks within the time budget, scheduling a continuation for the rest.
	 *
	 * @param string[] $ids Task ids, in run order.
	 */
	private function run_tasks( array $ids ): void {
		if ( [] === $ids || ! $this->module->settings()->schedule_enabled ) {
			return;
		}

		$tasks    = $this->module->task_factory()->scheduled_tasks();
		$runner   = new CleanupRunner( $this->clock );
		$start    = ( $this->clock )();
		$leftover = [];

		foreach ( $ids as $index => $id ) {
			$left = self::BUDGET - ( ( $this->clock )() - $start );

			if ( $left <= 0 || ! isset( $tasks[ $id ] ) ) {
				$leftover = array_slice( $ids, $index );
				break;
			}

			$result = $runner->run( $tasks[ $id ], $left );

			if ( ! $result->done ) {
				$leftover = array_slice( $ids, $index );
				break;
			}
		}

		$leftover = array_values( array_filter( $leftover, static fn ( string $id ): bool => isset( $tasks[ $id ] ) ) );

		if ( [] !== $leftover && false === wp_next_scheduled( self::CONTINUE_HOOK, [ $leftover ] ) ) {
			wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK, [ $leftover ] );
		}
	}

	/**
	 * Next scheduled run as ISO 8601, or null when nothing is scheduled.
	 */
	public function next_run_iso(): ?string {
		$timestamp = wp_next_scheduled( self::RUN_HOOK );

		if ( false === $timestamp ) {
			return null;
		}

		$iso = wp_date( 'c', $timestamp );

		return false === $iso ? null : $iso;
	}
}
