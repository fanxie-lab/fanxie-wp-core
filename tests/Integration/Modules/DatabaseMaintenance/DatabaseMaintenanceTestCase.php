<?php
/**
 * Shared base class for Database Maintenance integration tests.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\DatabaseMaintenance\DatabaseMaintenance;
use FanxieLab\Warden\Plugin;
use WP_UnitTestCase;
use WPAjaxDieContinueException;

/**
 * Shared base class for Database Maintenance integration tests.
 */
abstract class DatabaseMaintenanceTestCase extends WP_UnitTestCase {

	protected const OPTION_KEY = 'fanxie_warden_database-maintenance_settings';

	protected int $admin_user_id = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		delete_option( self::OPTION_KEY );
		$this->admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function tear_down(): void {
		delete_option( self::OPTION_KEY );
		wp_clear_scheduled_hook( 'fanxie_warden_database_maintenance_run' );
		wp_clear_scheduled_hook( 'fanxie_warden_database_maintenance_continue' );
		parent::tear_down();
	}

	protected function module(): DatabaseMaintenance {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin );

		$module = $plugin->get( DatabaseMaintenance::class );
		$this->assertInstanceOf( DatabaseMaintenance::class, $module );

		return $module;
	}

	/**
	 * Dispatch a sub-action through the AJAX router as an administrator.
	 *
	 * @param string               $sub_action Router sub-action slug.
	 * @param array<string, mixed> $payload    Extra POST fields.
	 * @param string|null          $nonce      Nonce override; a valid one when null.
	 * @return array<string, mixed>
	 */
	protected function dispatch( string $sub_action, array $payload = [], ?string $nonce = null ): array {
		wp_set_current_user( $this->admin_user_id );

		$_POST = array_merge(
			$payload,
			[
				'action'      => AjaxRouter::AJAX_ACTION,
				'_action'     => $sub_action,
				'_ajax_nonce' => $nonce ?? wp_create_nonce( AjaxRouter::NONCE_ACTION ),
			]
		);

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );
		ob_start();

		try {
			do_action( 'wp_ajax_' . AjaxRouter::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected termination.
		} finally {
			remove_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST = [];
		}

		$decoded = json_decode( (string) ob_get_clean(), true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	public function ajax_die_handler(): callable {
		return static function (): void {
			throw new WPAjaxDieContinueException( 'ajax-dispatched' );
		};
	}
}
