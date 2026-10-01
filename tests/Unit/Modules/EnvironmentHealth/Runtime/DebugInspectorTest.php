<?php
/**
 * Unit tests for the debug-mode exposure checks.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\DebugInspector;
use PHPUnit\Framework\TestCase;

/**
 * Every finding here hinges on a PHP constant or an ini setting, so the
 * constant-driven cases run in their own process: `WP_DEBUG` and friends can
 * only be defined once per PHP lifetime, and a test that leaked one would
 * silently change the answer for every test after it.
 */
final class DebugInspectorTest extends TestCase {

	/**
	 * Saved ini values restored in tearDown.
	 *
	 * @var array<string, string>
	 */
	private array $ini_backup = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_normalize_path' )->alias( static fn ( $path ) => str_replace( '\\', '/', (string) $path ) );

		$this->ini_backup = [ 'display_errors' => (string) ini_get( 'display_errors' ) ];
	}

	protected function tearDown(): void {
		foreach ( $this->ini_backup as $key => $value ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the value this test saved in setUp().
			ini_set( $key, $value );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Locate a check by its stable id.
	 *
	 * @param array<int, HealthCheck> $checks Checks returned by the inspector.
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

	/**
	 * All six checks from a fresh inspector.
	 *
	 * @return array<int, HealthCheck>
	 */
	private function checks(): array {
		return ( new DebugInspector() )->checks();
	}

	public function test_every_check_carries_the_debug_group_and_a_stable_id(): void {
		$ids = [];
		foreach ( $this->checks() as $check ) {
			$this->assertSame( HealthCheck::GROUP_DEBUG, $check->group );
			$this->assertContains( $check->status, HealthCheck::ALL_STATUSES );
			$ids[] = $check->id;
		}

		$this->assertSame(
			[ 'wp_debug', 'wp_debug_display', 'wp_debug_log', 'script_debug', 'php_display_errors', 'php_error_reporting' ],
			$ids
		);
	}

	public function test_a_clean_production_config_passes_every_constant_check(): void {
		// The unit bootstrap defines none of the debug constants — which is
		// exactly the shape of a correctly-configured production site.
		$checks = $this->checks();

		$this->assertSame( HealthCheck::STATUS_OK, $this->find( $checks, 'wp_debug' )->status );
		$this->assertSame( HealthCheck::STATUS_OK, $this->find( $checks, 'wp_debug_display' )->status );
		$this->assertSame( HealthCheck::STATUS_OK, $this->find( $checks, 'wp_debug_log' )->status );
		$this->assertSame( HealthCheck::STATUS_OK, $this->find( $checks, 'script_debug' )->status );
	}

	public function test_display_errors_on_is_critical(): void {
		// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- driving the very setting under test; restored in tearDown().
		ini_set( 'display_errors', '1' );

		$check = $this->find( $this->checks(), 'php_display_errors' );

		$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
		$this->assertNotSame( [], $check->remediation );
	}

	public function test_display_errors_off_is_ok(): void {
		// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- driving the very setting under test; restored in tearDown().
		ini_set( 'display_errors', '0' );

		$this->assertSame(
			HealthCheck::STATUS_OK,
			$this->find( $this->checks(), 'php_display_errors' )->status
		);
	}

	public function test_display_errors_to_stderr_does_not_reach_a_visitor(): void {
		// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- driving the very setting under test; restored in tearDown().
		ini_set( 'display_errors', 'stderr' );

		$this->assertSame(
			HealthCheck::STATUS_OK,
			$this->find( $this->checks(), 'php_display_errors' )->status,
			'stderr output never enters the HTTP response.'
		);
	}

	public function test_error_reporting_including_notices_warns(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- driving the very setting under test; restored in the finally block.
		$previous = error_reporting( E_ALL );

		try {
			$check = $this->find( $this->checks(), 'php_error_reporting' );
			$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
			$this->assertSame( 'E_ALL', $check->value );
		} finally {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- restoring the mask this test changed.
			error_reporting( $previous );
		}
	}

	public function test_error_reporting_limited_to_real_errors_is_ok(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- driving the very setting under test; restored in the finally block.
		$previous = error_reporting( E_ERROR | E_PARSE );

		try {
			$this->assertSame(
				HealthCheck::STATUS_OK,
				$this->find( $this->checks(), 'php_error_reporting' )->status
			);
		} finally {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- restoring the mask this test changed.
			error_reporting( $previous );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_debug_on_warns(): void {
		define( 'WP_DEBUG', true );

		$check = $this->find( $this->checks(), 'wp_debug' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertNotSame( [], $check->remediation );
		$this->assertSame( 'php', $check->remediation[0]['language'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_debug_display_defaults_on_and_is_critical_while_debugging(): void {
		// Core's default for WP_DEBUG_DISPLAY is true, so leaving it undefined
		// while WP_DEBUG is on still puts errors in front of visitors.
		define( 'WP_DEBUG', true );

		$this->assertSame(
			HealthCheck::STATUS_CRITICAL,
			$this->find( $this->checks(), 'wp_debug_display' )->status
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_debug_display_explicitly_off_is_ok_even_while_debugging(): void {
		define( 'WP_DEBUG', true );
		define( 'WP_DEBUG_DISPLAY', false );

		$this->assertSame(
			HealthCheck::STATUS_OK,
			$this->find( $this->checks(), 'wp_debug_display' )->status
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_debug_on_warns(): void {
		define( 'SCRIPT_DEBUG', true );

		$this->assertSame(
			HealthCheck::STATUS_WARNING,
			$this->find( $this->checks(), 'script_debug' )->status
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_existing_debug_log_inside_the_web_root_is_critical(): void {
		$path = ABSPATH . 'fanxie-test-debug.log';
		file_put_contents( $path, '' );
		define( 'WP_DEBUG_LOG', $path );

		try {
			$check = $this->find( $this->checks(), 'wp_debug_log' );

			$this->assertSame( HealthCheck::STATUS_CRITICAL, $check->status );
			$this->assertTrue( $check->meta['in_webroot'] );
			$this->assertTrue( $check->meta['exists'] );
			$this->assertCount( 2, $check->remediation, 'Both a wp-config move and an nginx deny are offered.' );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_not_yet_created_log_inside_the_web_root_only_warns(): void {
		define( 'WP_DEBUG_LOG', ABSPATH . 'fanxie-never-written.log' );

		$check = $this->find( $this->checks(), 'wp_debug_log' );

		$this->assertSame( HealthCheck::STATUS_WARNING, $check->status );
		$this->assertFalse( $check->meta['exists'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_log_outside_the_web_root_is_ok(): void {
		define( 'WP_DEBUG_LOG', '/var/log/fanxie-debug.log' );

		$check = $this->find( $this->checks(), 'wp_debug_log' );

		$this->assertSame( HealthCheck::STATUS_OK, $check->status );
		$this->assertFalse( $check->meta['in_webroot'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_debug_log_true_resolves_to_the_core_default_path(): void {
		define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		define( 'WP_DEBUG_LOG', true );

		$check = $this->find( $this->checks(), 'wp_debug_log' );

		$this->assertSame( ABSPATH . 'wp-content/debug.log', $check->meta['path'] );
		$this->assertTrue( $check->meta['in_webroot'] );
	}
}
