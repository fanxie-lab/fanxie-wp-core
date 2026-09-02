<?php
/**
 * Integration tests for the Login Protection AJAX controller.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\LoginProtection\AjaxController;
use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginProtection;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use WP_Error;

/**
 * Exercises every AJAX handler against the real custom tables + transients.
 *
 * Extends {@see LoginProtectionTableTestCase} so both the login-log and
 * login-bans tables are truncated before each test (order-independent), and
 * short-circuits `wp_mail()` through the `pre_wp_mail` filter so the slug-change
 * notice can be asserted without a live mailer.
 */
final class LoginProtectionAjaxControllerTest extends LoginProtectionTableTestCase {

	/**
	 * Cron hook the controller schedules.
	 */
	private const PRUNE_HOOK = 'fanxie_wp_core_login_protection_prune';

	private LoginProtection $module;

	private LoginLogRepository $log;

	private BanRepository $bans;

	private AjaxController $controller;

	/**
	 * Captured `wp_mail()` payloads (subject/body/to), one entry per send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent_mail = [];

	protected function setUp(): void {
		parent::setUp();

		$this->module     = new LoginProtection( new AjaxRouter() );
		$this->log        = new LoginLogRepository();
		$this->bans       = new BanRepository();
		$this->controller = new AjaxController( $this->module, $this->log, $this->bans );

		// Start every test from a clean stored config + no scheduled prune.
		delete_option( 'fanxie_wp_core_login-protection_settings' );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );

		$this->sent_mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
		delete_option( 'fanxie_wp_core_login-protection_settings' );
		parent::tearDown();
	}

	/**
	 * Short-circuit wp_mail(), recording the attempt instead of sending it.
	 *
	 * @param mixed                $short_circuit Filter short-circuit value.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ): bool {
		unset( $short_circuit );
		$this->sent_mail[] = is_array( $atts ) ? $atts : [];
		return true;
	}

	public function test_get_config_returns_contract_shape(): void {
		$result = $this->controller->handle_get_config();

		$this->assertArrayHasKey( 'config', $result );
		$this->assertArrayHasKey( 'slug_source', $result );
		$this->assertArrayHasKey( 'effective_slug', $result );
		$this->assertArrayHasKey( 'hide_login_active', $result );
		$this->assertIsArray( $result['config'] );
		$this->assertArrayHasKey( 'hide_login', $result['config'] );
		// No FX_CORE_LOGIN_SLUG constant in the test runtime.
		$this->assertSame( 'stored', $result['slug_source'] );
		// Hide-login ships disabled, so it is not enforcing at defaults.
		$this->assertFalse( $result['hide_login_active'], 'Hide-login is inactive at defaults.' );
	}

	public function test_get_config_reports_hide_login_active_when_enabled(): void {
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => true,
					'slug'    => 'secret-door',
				],
			]
		);

		$result = $this->controller->handle_get_config();

		$this->assertTrue( $result['hide_login_active'], 'Enabling hide-login with a valid slug is active.' );
		$this->assertSame( 'secret-door', $result['effective_slug'] );
	}

	public function test_save_config_persists_and_returns_contract_shape(): void {
		$result = $this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => true,
						'slug'    => 'my-secret-door',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'my-secret-door', $result['config']['hide_login']['slug'] );
		$this->assertSame( 'my-secret-door', $result['effective_slug'] );
		$this->assertSame( 'stored', $result['slug_source'] );

		// Round-trips through storage.
		$this->assertSame( 'my-secret-door', $this->module->get_config()['hide_login']['slug'] );
	}

	public function test_save_config_requires_config_object(): void {
		$err = $this->controller->handle_save_config( [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_config', $err->get_error_code() );

		$err = $this->controller->handle_save_config( [ 'config' => 'nope' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_config', $err->get_error_code() );
	}

	public function test_save_config_rejects_empty_slug_when_hide_login_enabled(): void {
		$err = $this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => true,
						'slug'    => 'wp-admin', // Reserved → sanitises to ''.
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_slug', $err->get_error_code() );
		$this->assertSame( [ 'field' => 'hide_login.slug' ], $err->get_error_data() );

		// Nothing was persisted, nothing was mailed.
		$this->assertSame( '', $this->module->get_config()['hide_login']['slug'] );
		$this->assertSame( [], $this->sent_mail );
	}

	public function test_save_config_allows_empty_slug_when_hide_login_disabled(): void {
		$result = $this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => false,
						'slug'    => '',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['effective_slug'] );
	}

	public function test_save_config_emails_admin_when_effective_slug_changes(): void {
		// Seed a baseline slug directly so the controller save is the change.
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => true,
					'slug'    => 'door-one',
				],
			]
		);
		$this->sent_mail = [];

		$this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => true,
						'slug'    => 'door-two',
					],
				],
			]
		);

		$this->assertCount( 1, $this->sent_mail, 'A slug change notifies the admin exactly once.' );
		$this->assertSame( get_option( 'admin_email' ), $this->sent_mail[0]['to'] );
		$this->assertStringContainsString( 'door-two', (string) $this->sent_mail[0]['message'] );
	}

	public function test_save_config_does_not_email_when_slug_unchanged(): void {
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => true,
					'slug'    => 'door-one',
				],
			]
		);
		$this->sent_mail = [];

		$this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => true,
						'slug'    => 'door-one',
					],
				],
			]
		);

		$this->assertSame( [], $this->sent_mail, 'An unchanged slug sends no notice.' );
	}

	public function test_save_config_emails_admin_when_slug_is_cleared(): void {
		// Baseline: a hidden slug is active.
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => true,
					'slug'    => 'door-one',
				],
			]
		);
		$this->sent_mail = [];

		// Clear it: disabling hide-login drops the effective slug to ''.
		$this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => false,
						'slug'    => '',
					],
				],
			]
		);

		$this->assertCount( 1, $this->sent_mail, 'Removing the custom slug notifies the admin.' );
		$this->assertStringContainsString( 'default WordPress login', (string) $this->sent_mail[0]['message'] );
	}

	public function test_save_config_emails_admin_when_hide_login_is_enabled(): void {
		// Baseline: hide-login off, so there is no active login address.
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => false,
					'slug'    => '',
				],
			]
		);
		$this->sent_mail = [];

		// Enabling with a valid slug moves the active address '' -> 'new-door'.
		$this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => true,
						'slug'    => 'new-door',
					],
				],
			]
		);

		$this->assertCount( 1, $this->sent_mail, 'Enabling hide-login notifies the admin of the new address.' );
		$this->assertStringContainsString( 'new-door', (string) $this->sent_mail[0]['message'] );
	}

	public function test_save_config_does_not_email_on_slug_change_while_disabled(): void {
		// Baseline: hide-login disabled but a slug is stored (the guard is not
		// enforcing, so the active login address is '').
		$this->module->update_config(
			[
				'hide_login' => [
					'enabled' => false,
					'slug'    => 'door-one',
				],
			]
		);
		$this->sent_mail = [];

		// Change only the slug while still disabled: the active address is '' both
		// before and after, so no lock-out notice should fire.
		$this->controller->handle_save_config(
			[
				'config' => [
					'hide_login' => [
						'enabled' => false,
						'slug'    => 'door-two',
					],
				],
			]
		);

		$this->assertSame( [], $this->sent_mail, 'A slug change while hide-login is off sends no notice.' );
	}

	public function test_get_log_returns_paginated_filtered_rows(): void {
		$this->log->record( 'failed_login', '203.0.113.5', 'admin', null, [] );
		$this->log->record( 'failed_login', '203.0.113.6', 'editor', null, [] );
		$this->log->record( 'lockout', '203.0.113.5', 'admin', null, [] );

		$result = $this->controller->handle_get_log(
			[
				'event_type' => 'failed_login',
				'page'       => 1,
				'per_page'   => 10,
			]
		);

		$this->assertArrayHasKey( 'rows', $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 10, $result['per_page'] );
		$this->assertCount( 2, $result['rows'] );
	}

	public function test_get_log_defaults_pagination(): void {
		$this->log->record( 'failed_login', '203.0.113.5', 'admin', null, [] );

		$result = $this->controller->handle_get_log( [] );

		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 25, $result['per_page'] );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_get_bans_returns_seeded_rows(): void {
		$this->bans->add( 'ip', '198.51.100.30', 'brute force', null );
		$this->bans->add( 'username', 'mallory', null, 60 );

		$result = $this->controller->handle_get_bans( [] );

		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['rows'] );

		// Repository returns rows newest-first (id DESC) and casts `id` to int.
		$this->assertIsInt( $result['rows'][0]['id'] );
		$this->assertSame( 'username', $result['rows'][0]['subject_type'] );
		$this->assertSame( 'mallory', $result['rows'][0]['subject_value'] );
		$this->assertSame( 'ip', $result['rows'][1]['subject_type'] );
		$this->assertSame( '198.51.100.30', $result['rows'][1]['subject_value'] );
	}

	public function test_add_ban_round_trips_and_logs(): void {
		$result = $this->controller->handle_add_ban(
			[
				'subject_type'  => 'ip',
				'subject_value' => '198.51.100.10',
				'reason'        => 'brute force',
				'ttl_minutes'   => 60,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertTrue( $this->bans->is_banned( 'ip', '198.51.100.10' ) );

		$log = $this->log->query( [ 'event_type' => 'ban_added' ], 1, 25 );
		$this->assertSame( 1, $log['total'] );
		$this->assertSame( '198.51.100.10', $log['rows'][0]['ip'] );
	}

	public function test_add_ban_username_records_username_dimension(): void {
		$this->controller->handle_add_ban(
			[
				'subject_type'  => 'username',
				'subject_value' => 'bob',
			]
		);

		$log = $this->log->query( [ 'event_type' => 'ban_added' ], 1, 25 );
		$this->assertSame( 'bob', $log['rows'][0]['username'] );
		$this->assertSame( '', $log['rows'][0]['ip'] );
	}

	public function test_add_ban_rejects_invalid_ip(): void {
		$err = $this->controller->handle_add_ban(
			[
				'subject_type'  => 'ip',
				'subject_value' => 'not-an-ip',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_ip', $err->get_error_code() );
		$this->assertSame( 0, $this->bans->query()['total'] );
	}

	public function test_add_ban_rejects_unknown_subject_type(): void {
		$err = $this->controller->handle_add_ban(
			[
				'subject_type'  => 'email',
				'subject_value' => 'bob@example.test',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_type', $err->get_error_code() );
	}

	public function test_add_ban_rejects_missing_value(): void {
		$err = $this->controller->handle_add_ban(
			[
				'subject_type'  => 'username',
				'subject_value' => '',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_value', $err->get_error_code() );
	}

	public function test_remove_ban_round_trips_and_logs(): void {
		$this->bans->add( 'ip', '198.51.100.20', 'manual', null );
		$this->assertTrue( $this->bans->is_banned( 'ip', '198.51.100.20' ) );

		$result = $this->controller->handle_remove_ban(
			[
				'subject_type'  => 'ip',
				'subject_value' => '198.51.100.20',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertFalse( $this->bans->is_banned( 'ip', '198.51.100.20' ) );

		$log = $this->log->query( [ 'event_type' => 'ban_removed' ], 1, 25 );
		$this->assertSame( 1, $log['total'] );
	}

	public function test_remove_ban_rejects_unknown_subject_type(): void {
		$err = $this->controller->handle_remove_ban(
			[
				'subject_type'  => 'nope',
				'subject_value' => 'x',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_type', $err->get_error_code() );
	}

	public function test_remove_ban_rejects_missing_value(): void {
		$err = $this->controller->handle_remove_ban(
			[
				'subject_type'  => 'ip',
				'subject_value' => '',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_value', $err->get_error_code() );
	}

	public function test_clear_lockout_rejects_missing_value(): void {
		$err = $this->controller->handle_clear_lockout(
			[
				'subject_type'  => 'username',
				'subject_value' => '',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_value', $err->get_error_code() );
	}

	public function test_clear_lockout_deletes_ip_transients(): void {
		$ip      = '203.0.113.55';
		$cnt_key = 'fanxie_wp_core_lp_cnt_ip_' . md5( $ip );
		$lock    = 'fanxie_wp_core_lp_lock_ip_' . md5( $ip );
		$applied = 'fanxie_wp_core_lp_applied_ip_' . md5( $ip );

		set_transient( $cnt_key, 5, HOUR_IN_SECONDS );
		set_transient( $lock, 5, HOUR_IN_SECONDS );
		set_transient( $applied, 5, HOUR_IN_SECONDS );

		$result = $this->controller->handle_clear_lockout(
			[
				'subject_type'  => 'ip',
				'subject_value' => $ip,
			]
		);

		$this->assertSame( [ 'cleared' => true ], $result );
		$this->assertFalse( get_transient( $cnt_key ) );
		$this->assertFalse( get_transient( $lock ) );
		$this->assertFalse( get_transient( $applied ) );
	}

	public function test_clear_lockout_maps_username_to_user_dimension(): void {
		$lock = 'fanxie_wp_core_lp_lock_user_' . md5( 'bob' );
		set_transient( $lock, 1, HOUR_IN_SECONDS );

		$this->controller->handle_clear_lockout(
			[
				'subject_type'  => 'username',
				'subject_value' => 'bob',
			]
		);

		$this->assertFalse( get_transient( $lock ) );
	}

	public function test_clear_lockout_rejects_unknown_subject_type(): void {
		$err = $this->controller->handle_clear_lockout(
			[
				'subject_type'  => 'nope',
				'subject_value' => 'x',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_subject_type', $err->get_error_code() );
	}

	public function test_register_schedules_daily_prune(): void {
		$this->assertFalse( wp_next_scheduled( self::PRUNE_HOOK ) );

		$this->controller->register( new AjaxRouter() );

		$this->assertIsInt( wp_next_scheduled( self::PRUNE_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( self::PRUNE_HOOK ) );
	}
}
