<?php
/**
 * Unit tests for the WP-Cron health checks.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\CronInspector;
use PHPUnit\Framework\TestCase;

/**
 * The interesting behaviour is the grading: `DISABLE_WP_CRON` is a
 * configuration rather than a fault, so it is judged by whether the queue is
 * actually moving, and an overdue *core* event is treated more severely than
 * an overdue plugin task because it proves the scheduler is not running at all.
 */
final class CronInspectorTest extends TestCase {

	/**
	 * Fixed "now" every assertion is written against.
	 */
	private const NOW = 1756800000;

	/**
	 * Cron array returned by the stubbed `_get_cron_array()`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cron = [];

	/**
	 * Value returned by the stubbed `get_transient( 'doing_cron' )`.
	 *
	 * @var mixed
	 */
	private $doing_cron = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cron       = [];
		$this->doing_cron = false;

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( 'site_url' )->alias( static fn ( $path = '' ) => 'https://example.test/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( '_get_cron_array' )->alias( fn () => $this->cron );
		Functions\when( 'get_transient' )->alias( fn () => $this->doing_cron );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an inspector pinned to the fixed clock.
	 *
	 * @param array<string, mixed> $config Module config.
	 */
	private function inspector( array $config = [] ): CronInspector {
		return new CronInspector( $config, self::NOW );
	}

	/**
	 * Locate a check by id.
	 *
	 * @param array<int, HealthCheck> $checks Checks.
	 * @param string                  $id     Stable check id.
	 */
	private function find( array $checks, string $id ): HealthCheck {
		foreach ( $checks as $check ) {
			if ( $check->id === $id ) {
				return $check;
			}
		}

		$this->fail( "No check with id `{$id}`." );
	}

	public function test_a_healthy_queue_passes_every_check(): void {
		$this->cron = [ self::NOW + 600 => [ 'wp_version_check' => [] ] ];

		$checks = $this->inspector()->checks();

		foreach ( $checks as $check ) {
			$this->assertSame( HealthCheck::GROUP_CRON, $check->group );
			$this->assertSame( HealthCheck::STATUS_OK, $check->status, "check `{$check->id}` should pass" );
		}
	}

	public function test_the_page_load_scheduler_passes_but_still_offers_the_crontab_recipe(): void {
		$check = $this->find( $this->inspector()->checks(), 'cron_disabled' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertFalse( $check->meta['disable_wp_cron'] );
		$this->assertCount( 2, $check->remediation, 'Both halves of the real-cron setup are offered together.' );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $check->remediation[0]['code'] );
		$this->assertStringContainsString( 'wp-cron.php', $check->remediation[1]['code'] );
	}

	public function test_remediation_snippets_are_never_translated(): void {
		$check = $this->find( $this->inspector()->checks(), 'cron_disabled' );

		foreach ( $check->remediation as $entry ) {
			$this->assertSame( HealthCheck::REMEDIATION_SNIPPET, $entry['kind'] );
			$this->assertNotSame( '', $entry['code'] );
			$this->assertContains( $entry['language'], [ 'php', 'bash', 'nginx' ] );
		}
	}

	public function test_an_overdue_core_event_is_critical(): void {
		$this->cron = [ self::NOW - 7200 => [ 'wp_version_check' => [] ] ];

		$check = $this->find( $this->inspector()->checks(), 'cron_overdue_events' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( 1, $check->meta['core_overdue'] );
		$this->assertSame( 'wp_version_check', $check->meta['core_hooks'] );
		$this->assertSame( self::NOW - 7200, $check->meta['oldest_due_at'] );
	}

	public function test_an_overdue_plugin_event_only_warns(): void {
		$this->cron = [ self::NOW - 7200 => [ 'acme_nightly_sync' => [] ] ];

		$check = $this->find( $this->inspector()->checks(), 'cron_overdue_events' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertSame( 0, $check->meta['core_overdue'] );
		$this->assertSame( 'acme_nightly_sync', $check->meta['other_hooks'] );
	}

	public function test_an_event_inside_the_tolerance_window_is_not_overdue(): void {
		$this->cron = [ self::NOW - 1800 => [ 'wp_version_check' => [] ] ];

		$check = $this->find( $this->inspector()->checks(), 'cron_overdue_events' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( '0', $check->value );
	}

	public function test_the_overdue_tolerance_is_configurable(): void {
		$this->cron = [ self::NOW - 1800 => [ 'wp_version_check' => [] ] ];

		$check = $this->find(
			$this->inspector( [ 'thresholds' => [ 'cron_overdue_minutes' => 10 ] ] )->checks(),
			'cron_overdue_events'
		);

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertSame( 10, $check->meta['threshold_minutes'] );
	}

	public function test_a_fresh_cron_lock_is_a_run_in_progress_not_a_fault(): void {
		$this->doing_cron = (string) ( self::NOW - 10 );

		$check = $this->find( $this->inspector()->checks(), 'cron_lock' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertSame( self::NOW - 10, $check->meta['locked_since'] );
	}

	public function test_a_stale_cron_lock_warns(): void {
		$this->doing_cron = (string) ( self::NOW - CronInspector::STALE_LOCK_SECONDS - 1 );

		$check = $this->find( $this->inspector()->checks(), 'cron_lock' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertStringContainsString( 'wp transient delete doing_cron', $check->remediation[0]['code'] );
	}

	public function test_an_empty_cron_array_does_not_explode(): void {
		$this->cron = [];

		$check = $this->find( $this->inspector()->checks(), 'cron_overdue_events' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_wp_cron_with_a_moving_queue_is_the_recommended_setup(): void {
		define( 'DISABLE_WP_CRON', true );
		$this->cron = [ self::NOW + 600 => [ 'wp_version_check' => [] ] ];

		$check = $this->find( $this->inspector()->checks(), 'cron_disabled' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertTrue( $check->meta['disable_wp_cron'] );
		$this->assertSame( [], $check->remediation, 'Nothing to fix — this is the target state.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_wp_cron_with_a_stalled_queue_is_critical(): void {
		define( 'DISABLE_WP_CRON', true );
		$this->cron = [ self::NOW - 7200 => [ 'wp_version_check' => [] ] ];

		$check = $this->find( $this->inspector()->checks(), 'cron_disabled' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertStringContainsString( 'wp-cron.php', $check->remediation[0]['code'] );
	}
}
