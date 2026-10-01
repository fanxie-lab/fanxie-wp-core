<?php
/**
 * Unit tests for the date-driven support matrix.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth;

use FanxieLab\Warden\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\Warden\Modules\EnvironmentHealth\SupportMatrix;
use PHPUnit\Framework\TestCase;

/**
 * The matrix is the module's only piece of genuinely time-dependent logic, so
 * every assertion here pins an explicit "now" rather than relying on the clock.
 * That is also what proves the design goal: the same code returns a different
 * verdict as time passes, with no edit.
 */
final class SupportMatrixTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
	}

	/**
	 * Convert a UTC datetime to a timestamp.
	 *
	 * @param string $utc Datetime in a `strtotime`-parseable form, without a zone.
	 */
	private function ts( string $utc ): int {
		$timestamp = strtotime( $utc . ' UTC' );
		$this->assertIsInt( $timestamp );

		return $timestamp;
	}

	public function test_branch_reduces_a_version_to_major_minor(): void {
		$this->assertSame( '8.2', SupportMatrix::branch( '8.2.14' ) );
		$this->assertSame( '8.1', SupportMatrix::branch( '8.1.2-1ubuntu2.14' ) );
		$this->assertSame( '10.6', SupportMatrix::branch( '10.6.16-MariaDB-1:10.6.16+maria~ubu2004' ) );
		$this->assertSame( '', SupportMatrix::branch( 'not-a-version' ) );
	}

	public function test_is_mariadb_detects_the_server_regardless_of_case(): void {
		$this->assertTrue( SupportMatrix::is_mariadb( '5.5.5-10.11.6-MariaDB' ) );
		$this->assertTrue( SupportMatrix::is_mariadb( '11.4.2-mariadb-log' ) );
		$this->assertFalse( SupportMatrix::is_mariadb( '8.0.36' ) );
	}

	public function test_php_81_is_critical_today_even_though_the_plugin_supports_it(): void {
		// The plugin's own floor is PHP 8.1, which went EOL on 2025-12-31. The
		// check must be willing to flag the very version it minimally runs on.
		$verdict = SupportMatrix::php( '8.1.31', $this->ts( '2026-09-02 12:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $verdict['status'] );
		$this->assertSame( SupportMatrix::PHASE_EOL, $verdict['phase'] );
		$this->assertSame( '2025-12-31', $verdict['security_until'] );
	}

	public function test_php_82_flips_from_security_only_to_eol_on_the_published_date(): void {
		$last_supported = $this->ts( '2026-12-31 23:59:00' );
		$first_eol_day  = $this->ts( '2027-01-01 00:00:01' );

		$before = SupportMatrix::php( '8.2.30', $last_supported );
		$after  = SupportMatrix::php( '8.2.30', $first_eol_day );

		$this->assertSame( HealthCheck::STATUS_WARNING, $before['status'], 'Still receiving security fixes on the final day.' );
		$this->assertSame( SupportMatrix::PHASE_SECURITY, $before['phase'] );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $after['status'], 'EOL the moment the window closes.' );
		$this->assertSame( SupportMatrix::PHASE_EOL, $after['phase'] );
	}

	public function test_php_84_is_ok_while_in_active_support_and_warns_once_security_only(): void {
		$active   = SupportMatrix::php( '8.4.1', $this->ts( '2026-06-01 00:00:00' ) );
		$security = SupportMatrix::php( '8.4.1', $this->ts( '2027-01-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_OK, $active['status'] );
		$this->assertSame( SupportMatrix::PHASE_ACTIVE, $active['phase'] );

		$this->assertSame( HealthCheck::STATUS_WARNING, $security['status'] );
		$this->assertSame( SupportMatrix::PHASE_SECURITY, $security['phase'] );
	}

	public function test_a_branch_outside_the_matrix_is_unknown_not_a_guess(): void {
		$verdict = SupportMatrix::php( '9.4.0', $this->ts( '2026-09-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $verdict['status'] );
		$this->assertSame( SupportMatrix::PHASE_UNKNOWN, $verdict['phase'] );
		$this->assertNull( $verdict['security_until'] );
		$this->assertNull( $verdict['days_remaining'] );
	}

	public function test_an_unparseable_version_is_unknown(): void {
		$verdict = SupportMatrix::mysql( 'unknown', $this->ts( '2026-09-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $verdict['status'] );
		$this->assertSame( '', $verdict['branch'] );
	}

	public function test_mysql_80_is_past_extended_support_today(): void {
		$verdict = SupportMatrix::mysql( '8.0.36', $this->ts( '2026-09-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $verdict['status'] );
		$this->assertSame( '2026-04-30', $verdict['security_until'] );
	}

	public function test_mysql_84_lts_is_comfortably_supported(): void {
		$verdict = SupportMatrix::mysql( '8.4.3', $this->ts( '2026-09-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_OK, $verdict['status'] );
		$this->assertGreaterThan( SupportMatrix::APPROACHING_EOL_DAYS, (int) $verdict['days_remaining'] );
	}

	public function test_mariadb_warns_inside_the_approaching_eol_window(): void {
		// MariaDB publishes a single end date, so without the window a branch
		// would jump straight from `ok` to `critical` overnight.
		$comfortable = SupportMatrix::mariadb( '11.8.2-MariaDB', $this->ts( '2027-06-01 00:00:00' ) );
		$approaching = SupportMatrix::mariadb( '11.8.2-MariaDB', $this->ts( '2028-01-15 00:00:00' ) );
		$expired     = SupportMatrix::mariadb( '11.8.2-MariaDB', $this->ts( '2028-06-05 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_OK, $comfortable['status'] );
		$this->assertSame( HealthCheck::STATUS_WARNING, $approaching['status'] );
		$this->assertSame( SupportMatrix::PHASE_ACTIVE, $approaching['phase'], 'Still supported — just not for much longer.' );
		$this->assertSame( HealthCheck::STATUS_CRITICAL, $expired['status'] );
	}

	public function test_mariadb_106_is_eol_as_of_today(): void {
		$verdict = SupportMatrix::mariadb( '10.6.16-MariaDB-log', $this->ts( '2026-09-02 00:00:00' ) );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $verdict['status'] );
		$this->assertSame( '2026-07-06', $verdict['security_until'] );
	}

	public function test_support_window_includes_its_final_day(): void {
		// A branch whose support ends on 2026-07-06 is supported *on* the 6th.
		$on_the_day  = SupportMatrix::mariadb( '10.6.16-MariaDB', $this->ts( '2026-07-06 23:00:00' ) );
		$the_day_after = SupportMatrix::mariadb( '10.6.16-MariaDB', $this->ts( '2026-07-07 00:30:00' ) );

		$this->assertNotSame( HealthCheck::STATUS_CRITICAL, $on_the_day['status'] );
		$this->assertSame( HealthCheck::STATUS_CRITICAL, $the_day_after['status'] );
	}

	public function test_reviewed_on_is_a_parseable_date(): void {
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', SupportMatrix::REVIEWED_ON );
		$this->assertIsInt( strtotime( SupportMatrix::REVIEWED_ON ) );
	}
}
