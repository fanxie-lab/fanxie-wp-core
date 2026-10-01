<?php
/**
 * Unit tests for the scheduled cleanup time math.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

use DateTimeImmutable;
use DateTimeZone;
use FanxieLab\Warden\Modules\DatabaseMaintenance\ScheduledCleanup;
use PHPUnit\Framework\TestCase;

/**
 * Pure time-math tests for ScheduledCleanup::next_occurrence().
 */
final class ScheduledCleanupTest extends TestCase {

	/**
	 * Format a timestamp in a zone.
	 *
	 * @param int          $timestamp Unix timestamp.
	 * @param DateTimeZone $tz        Zone.
	 * @param string       $format    Date format.
	 */
	private function local( int $timestamp, DateTimeZone $tz, string $format ): string {
		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz )->format( $format );
	}

	public function test_hour_later_today_schedules_today(): void {
		$tz  = new DateTimeZone( 'America/Chicago' );
		$now = ( new DateTimeImmutable( '2026-10-01 01:30:00', $tz ) )->getTimestamp();

		$next = ScheduledCleanup::next_occurrence( 3, $now, $tz );

		$this->assertSame( '2026-10-01 03:00', $this->local( $next, $tz, 'Y-m-d H:i' ) );
	}

	public function test_hour_already_passed_schedules_tomorrow(): void {
		$tz  = new DateTimeZone( 'Asia/Kolkata' );
		$now = ( new DateTimeImmutable( '2026-10-01 03:00:00', $tz ) )->getTimestamp();

		$next = ScheduledCleanup::next_occurrence( 3, $now, $tz );

		$this->assertSame( '2026-10-02 03:00', $this->local( $next, $tz, 'Y-m-d H:i' ) );
	}

	public function test_dst_change_keeps_local_wall_clock_hour(): void {
		$tz  = new DateTimeZone( 'America/New_York' );
		$now = ( new DateTimeImmutable( '2026-11-01 05:00:00', $tz ) )->getTimestamp(); // After fall-back.

		$next = ScheduledCleanup::next_occurrence( 3, $now, $tz );

		$this->assertSame( '03:00', $this->local( $next, $tz, 'H:i' ) );
	}

	public function test_crossing_the_fall_back_boundary_keeps_the_hour(): void {
		$tz  = new DateTimeZone( 'America/New_York' );
		$now = ( new DateTimeImmutable( '2026-10-31 04:00:00', $tz ) )->getTimestamp();

		$next = ScheduledCleanup::next_occurrence( 3, $now, $tz );

		$this->assertSame( '2026-11-01 03:00', $this->local( $next, $tz, 'Y-m-d H:i' ) );
	}
}
