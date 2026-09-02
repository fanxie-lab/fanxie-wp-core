<?php
/**
 * Unit tests for the Environment Health module shell.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\EnvironmentHealth;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use PHPUnit\Framework\TestCase;

/**
 * Covers the module contract (id, name, defaults, schema), the schema-driven
 * sanitiser round-trip, and the hook wiring — in particular that the recurring
 * scan is armed exactly once and that the dashboard widget honours its toggle.
 */
final class EnvironmentHealthTest extends TestCase {

	/**
	 * Option store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Cron events registered by the stubbed scheduler: hook => timestamp.
	 *
	 * @var array<string, int>
	 */
	private array $scheduled = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		$this->options   = [];
		$this->scheduled = [];

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn ( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ?? '' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->alias(
			fn ( $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$changed               = ( $this->options[ $key ] ?? null ) !== $value;
				$this->options[ $key ] = $value;
				return $changed;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			fn ( $hook, $args = [] ) => $this->scheduled[ $this->key( (string) $hook, $args ) ] ?? false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook, $args = [] ) {
				$this->scheduled[ $this->key( (string) $hook, $args ) ] = (int) $timestamp;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->scheduled[ $this->key( (string) $hook, $args ) ] = (int) $timestamp;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Compose the scheduler key for a hook + args pair.
	 *
	 * @param string            $hook Hook name.
	 * @param array<int, mixed> $args Event args.
	 */
	private function key( string $hook, array $args ): string {
		return $hook . '|' . implode( ',', array_map( 'strval', $args ) );
	}

	private function module(): EnvironmentHealth {
		return new EnvironmentHealth( new AjaxRouter() );
	}

	public function test_module_identity(): void {
		$module = $this->module();

		$this->assertSame( 'environment-health', $module->id() );
		$this->assertSame( 'environment-health', EnvironmentHealth::MODULE_ID );
		$this->assertSame( 'Environment Health', $module->name() );
	}

	public function test_defaults_switch_every_group_on(): void {
		$defaults = $this->module()->get_default_config();

		$this->assertTrue( $defaults['checks']['versions'] );
		$this->assertTrue( $defaults['checks']['cron'] );
		$this->assertTrue( $defaults['checks']['debug'] );
		$this->assertTrue( $defaults['checks']['plugins_themes'] );
		$this->assertTrue( $defaults['dashboard_widget'] );
	}

	public function test_the_wporg_scan_ships_on_with_an_explicit_opt_out(): void {
		$module = $this->module();

		$this->assertTrue( $module->get_default_config()['wporg_scan_enabled'] );
		$this->assertTrue( $module->wporg_scan_enabled() );

		$ids = array_column( $module->get_settings_fields(), 'id' );
		$this->assertContains( 'wporg_scan_enabled', $ids, 'The opt-out must be a real, documented setting.' );
	}

	public function test_thresholds_match_the_prd_defaults(): void {
		$thresholds = $this->module()->get_default_config()['thresholds'];

		$this->assertSame( 30, $thresholds['ssl_expiry_warning_days'] );
		$this->assertSame( 60, $thresholds['cron_overdue_minutes'] );
		$this->assertSame( 365, $thresholds['abandoned_warning_days'] );
		$this->assertSame( 730, $thresholds['abandoned_critical_days'] );
	}

	public function test_every_schema_field_addresses_a_real_default(): void {
		$module   = $this->module();
		$defaults = $module->get_default_config();

		foreach ( $module->get_settings_fields() as $field ) {
			$cursor = $defaults;
			foreach ( explode( '.', (string) $field['id'] ) as $segment ) {
				$this->assertIsArray( $cursor, "path `{$field['id']}` runs past a leaf" );
				$this->assertArrayHasKey( $segment, $cursor, "default missing for `{$field['id']}`" );
				$cursor = $cursor[ $segment ];
			}

			$this->assertSame( $field['default'], $cursor, "schema default drifted for `{$field['id']}`" );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertTrue(
				isset( $field['sanitizer'] ) || isset( $field['sanitizer_callback'] ),
				"no sanitiser declared for `{$field['id']}`"
			);
		}
	}

	public function test_config_round_trips_through_the_schema_sanitiser(): void {
		$module = $this->module();

		$module->update_config(
			[
				'wporg_scan_enabled' => false,
				'thresholds'         => [ 'ssl_expiry_warning_days' => '45' ],
			]
		);

		$config = $module->get_config();

		$this->assertFalse( $config['wporg_scan_enabled'] );
		$this->assertSame( 45, $config['thresholds']['ssl_expiry_warning_days'], 'Numeric strings are coerced.' );
		$this->assertTrue( $config['checks']['versions'], 'Untouched branches keep their defaults.' );
	}

	public function test_unknown_keys_are_dropped_on_save(): void {
		$module = $this->module();
		$module->update_config( [ 'evil' => 'payload' ] );

		$this->assertArrayNotHasKey( 'evil', $module->get_config() );
	}

	public function test_every_threshold_publishes_its_bounds_to_the_admin_ui(): void {
		$fields = [];
		foreach ( $this->module()->get_settings_fields() as $field ) {
			$fields[ (string) $field['id'] ] = $field;
		}

		foreach ( EnvironmentHealth::THRESHOLD_RANGES as $key => $range ) {
			$field = $fields[ 'thresholds.' . $key ] ?? null;

			$this->assertIsArray( $field, "no schema field for threshold `{$key}`" );
			$this->assertSame( $range['min'], $field['min'] );
			$this->assertSame( $range['max'], $field['max'] );
			$this->assertArrayHasKey( 'sanitizer_callback', $field, 'bare absint cannot express a range' );
		}
	}

	public function test_every_default_sits_inside_its_own_range(): void {
		foreach ( EnvironmentHealth::THRESHOLD_RANGES as $key => $range ) {
			$this->assertGreaterThanOrEqual( $range['min'], $range['default'], "default below min for `{$key}`" );
			$this->assertLessThanOrEqual( $range['max'], $range['default'], "default above max for `{$key}`" );
			$this->assertLessThan( $range['max'], $range['min'], "empty range for `{$key}`" );
		}
	}

	/**
	 * Threshold clamping cases: key, raw input, expected stored value.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: int}>
	 */
	public function threshold_clamping_provider(): array {
		return [
			// Below the floor clamps up to the floor — never to zero, which is
			// the value that silently breaks each of these checks.
			'ssl: zero clamps to floor'            => [ 'ssl_expiry_warning_days', 0, 1 ],
			'ssl: negative clamps to floor'        => [ 'ssl_expiry_warning_days', -14, 1 ],
			'cron: zero clamps to floor'           => [ 'cron_overdue_minutes', 0, 1 ],
			'cron: negative clamps to floor'       => [ 'cron_overdue_minutes', -30, 1 ],
			'warning: below floor clamps up'       => [ 'abandoned_warning_days', 5, 30 ],

			// Above the ceiling clamps down — the user wants "the maximum",
			// not a silent reset to the default.
			'ssl: above ceiling clamps down'       => [ 'ssl_expiry_warning_days', 9000, 365 ],
			'cron: above ceiling clamps down'      => [ 'cron_overdue_minutes', 100000, 1440 ],
			'warning: absurd ceiling clamps down'  => [ 'abandoned_warning_days', 99999999, 3650 ],
			'critical: absurd ceiling clamps down' => [ 'abandoned_critical_days', 99999999, 3650 ],

			// Exactly on a bound is accepted untouched.
			'ssl: exactly the floor'               => [ 'ssl_expiry_warning_days', 1, 1 ],
			'ssl: exactly the ceiling'             => [ 'ssl_expiry_warning_days', 365, 365 ],
			'cron: exactly the floor'              => [ 'cron_overdue_minutes', 1, 1 ],
			'cron: exactly the ceiling'            => [ 'cron_overdue_minutes', 1440, 1440 ],
			'warning: exactly the floor'           => [ 'abandoned_warning_days', 30, 30 ],
			'warning: exactly the ceiling'         => [ 'abandoned_warning_days', 3650, 3650 ],

			// In-range values pass through, including numeric strings.
			'ssl: in range'                        => [ 'ssl_expiry_warning_days', 45, 45 ],
			'ssl: numeric string'                  => [ 'ssl_expiry_warning_days', '45', 45 ],
			'ssl: padded numeric string'           => [ 'ssl_expiry_warning_days', ' 45 ', 45 ],
			'ssl: float rounds'                    => [ 'ssl_expiry_warning_days', 45.6, 46 ],

			// Not a number at all: no nearest bound exists, so fall back to the
			// default rather than to absint()'s zero.
			'ssl: empty string'                    => [ 'ssl_expiry_warning_days', '', 30 ],
			'ssl: non-numeric string'              => [ 'ssl_expiry_warning_days', 'garbage', 30 ],
			'cron: null'                           => [ 'cron_overdue_minutes', null, 60 ],
			'cron: boolean'                        => [ 'cron_overdue_minutes', true, 60 ],
			'warning: array'                       => [ 'abandoned_warning_days', [ 1, 2 ], 365 ],
		];
	}

	/**
	 * @dataProvider threshold_clamping_provider
	 *
	 * @param string $key      Threshold key.
	 * @param mixed  $raw      Value posted to save-config.
	 * @param int    $expected Value that should be stored.
	 */
	public function test_thresholds_are_clamped_on_save( string $key, mixed $raw, int $expected ): void {
		$module = $this->module();
		$module->update_config( [ 'thresholds' => [ $key => $raw ] ] );

		$this->assertSame( $expected, $module->get_config()['thresholds'][ $key ] );
	}

	public function test_a_critical_window_below_the_warning_window_is_raised_to_match(): void {
		// Both values pass their own bounds check while the pair stays
		// incoherent: nothing would ever be flagged critical, because anything
		// old enough trips the warning branch first.
		$module = $this->module();
		$module->update_config(
			[
				'thresholds' => [
					'abandoned_warning_days'  => 700,
					'abandoned_critical_days' => 100,
				],
			]
		);

		$thresholds = $module->get_config()['thresholds'];

		$this->assertSame( 700, $thresholds['abandoned_warning_days'], 'The warning threshold is honoured as given.' );
		$this->assertSame( 700, $thresholds['abandoned_critical_days'], 'Critical is raised to meet warning.' );
	}

	public function test_the_ordering_rule_runs_after_clamping_not_before(): void {
		// 20 clamps up to 30 (the floor) and 10 clamps up to 30 too, so the
		// pair is coherent afterwards and must be left alone.
		$module = $this->module();
		$module->update_config(
			[
				'thresholds' => [
					'abandoned_warning_days'  => 20,
					'abandoned_critical_days' => 10,
				],
			]
		);

		$thresholds = $module->get_config()['thresholds'];

		$this->assertSame( 30, $thresholds['abandoned_warning_days'] );
		$this->assertSame( 30, $thresholds['abandoned_critical_days'] );
	}

	public function test_a_coherent_pair_is_left_untouched(): void {
		$module = $this->module();
		$module->update_config(
			[
				'thresholds' => [
					'abandoned_warning_days'  => 200,
					'abandoned_critical_days' => 400,
				],
			]
		);

		$thresholds = $module->get_config()['thresholds'];

		$this->assertSame( 200, $thresholds['abandoned_warning_days'] );
		$this->assertSame( 400, $thresholds['abandoned_critical_days'] );
	}

	public function test_an_incoherent_option_written_by_older_code_self_heals_on_read(): void {
		// `absint` let this shape through before the bounds existed; reading it
		// back must not hand a broken config to the inspectors.
		$this->options['fanxie_wp_core_environment-health_settings'] = [
			'thresholds' => [
				'ssl_expiry_warning_days' => 0,
				'cron_overdue_minutes'    => 0,
				'abandoned_warning_days'  => 900,
				'abandoned_critical_days' => 100,
			],
		];

		$thresholds = $this->module()->get_config()['thresholds'];

		$this->assertSame( 1, $thresholds['ssl_expiry_warning_days'] );
		$this->assertSame( 1, $thresholds['cron_overdue_minutes'] );
		$this->assertSame( 900, $thresholds['abandoned_critical_days'] );
	}

	public function test_omitted_thresholds_keep_their_defaults(): void {
		$module = $this->module();
		$module->update_config( [ 'thresholds' => [ 'cron_overdue_minutes' => 90 ] ] );

		$thresholds = $module->get_config()['thresholds'];

		$this->assertSame( 90, $thresholds['cron_overdue_minutes'] );
		$this->assertSame( 30, $thresholds['ssl_expiry_warning_days'] );
		$this->assertSame( 365, $thresholds['abandoned_warning_days'] );
		$this->assertSame( 730, $thresholds['abandoned_critical_days'] );
	}

	public function test_wporg_scan_enabled_reflects_the_stored_option(): void {
		$module = $this->module();
		$this->assertTrue( $module->wporg_scan_enabled() );

		$module->update_config( [ 'wporg_scan_enabled' => false ] );

		$this->assertFalse( $module->wporg_scan_enabled() );
	}

	public function test_register_hooks_arms_the_recurring_scan_and_the_dashboard_widget(): void {
		$module = $this->module();

		Actions\expectAdded( EnvironmentHealth::SCAN_HOOK )->once();
		Actions\expectAdded( 'wp_dashboard_setup' )->once();

		$module->register_hooks();

		$this->assertArrayHasKey(
			$this->key( EnvironmentHealth::SCAN_HOOK, [] ),
			$this->scheduled,
			'The recurring scan is the only unattended path that touches the network.'
		);
	}

	public function test_the_dashboard_widget_can_be_switched_off(): void {
		$module = $this->module();
		$module->update_config( [ 'dashboard_widget' => false ] );

		Actions\expectAdded( EnvironmentHealth::SCAN_HOOK )->once();
		Actions\expectAdded( 'wp_dashboard_setup' )->never();

		$module->register_hooks();

		$this->assertFalse( $module->get_config()['dashboard_widget'] );
	}

	public function test_an_already_scheduled_scan_is_not_re_armed(): void {
		$this->scheduled[ $this->key( EnvironmentHealth::SCAN_HOOK, [] ) ] = 999;

		Actions\expectAdded( EnvironmentHealth::SCAN_HOOK )->once();
		Actions\expectAdded( 'wp_dashboard_setup' )->once();

		$this->module()->register_hooks();

		$this->assertSame( 999, $this->scheduled[ $this->key( EnvironmentHealth::SCAN_HOOK, [] ) ] );
	}

	public function test_follow_up_scans_do_not_stack(): void {
		$module = $this->module();

		$module->schedule_follow_up_scan();
		$first = $this->scheduled[ $this->key( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] ) ];

		$module->schedule_follow_up_scan();

		$this->assertSame(
			$first,
			$this->scheduled[ $this->key( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] ) ],
			'A queued follow-up must not be replaced by a second one.'
		);
	}

	public function test_collaborators_are_lazily_built_and_memoised(): void {
		$module = $this->module();

		$this->assertSame( $module->ssl_probe(), $module->ssl_probe() );
		$this->assertSame( $module->wporg_scanner(), $module->wporg_scanner() );
		$this->assertSame( $module->status_inspector(), $module->status_inspector() );
	}

	public function test_injected_collaborators_win(): void {
		$scanner = new WporgScanner();
		$module  = new EnvironmentHealth( new AjaxRouter(), null, $scanner );

		$this->assertSame( $scanner, $module->wporg_scanner() );
	}
}
