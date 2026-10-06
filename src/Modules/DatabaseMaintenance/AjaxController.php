<?php
/**
 * AJAX sub-actions for the Database Maintenance admin module.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupTask;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\ExpiredTransientsTask;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Status, preview, stepwise purge and config sub-actions.
 */
final class AjaxController {

	public const STEP_BUDGET = 5.0;
	public const SAMPLE_SIZE = 10;

	/**
	 * Constructor.
	 *
	 * @param DatabaseMaintenance $module Module instance.
	 */
	public function __construct( private readonly DatabaseMaintenance $module ) {}

	/**
	 * Attach every sub-action to the shared router.
	 *
	 * @param AjaxRouter $router Shared AJAX router.
	 */
	public function register( AjaxRouter $router ): void {
		$router->register( 'database-maintenance/get-status', [ $this, 'handle_get_status' ] );
		$router->register( 'database-maintenance/preview', [ $this, 'handle_preview' ] );
		$router->register( 'database-maintenance/purge-step', [ $this, 'handle_purge_step' ] );
		$router->register( 'database-maintenance/get-config', [ $this, 'handle_get_config' ] );
		$router->register( 'database-maintenance/save-config', [ $this, 'handle_save_config' ] );
	}

	/**
	 * Handler: `database-maintenance/get-status`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_status( array $payload = [] ): array {
		unset( $payload );

		$items = [];
		$total = 0;

		foreach ( $this->module->task_factory()->admin_tasks() as $id => $task ) {
			$bytes   = $task->estimate_bytes();
			$total  += $bytes;
			$items[] = [
				'id'    => $id,
				'label' => $task->label(),
				'count' => $task->count(),
				'bytes' => $bytes,
			];
		}

		return [
			'items'        => $items,
			'total_bytes'  => $total,
			'object_cache' => ExpiredTransientsTask::uses_object_cache(),
		];
	}

	/**
	 * Handler: `database-maintenance/preview`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_preview( array $payload ): array|WP_Error {
		$task = $this->task_from( $payload );
		if ( $task instanceof WP_Error ) {
			return $task;
		}

		return [
			'id'     => $task->id(),
			'count'  => $task->count(),
			'bytes'  => $task->estimate_bytes(),
			'sample' => $task->sample( self::SAMPLE_SIZE ),
		];
	}

	/**
	 * Handler: `database-maintenance/purge-step`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_purge_step( array $payload ): array|WP_Error {
		$task = $this->task_from( $payload );
		if ( $task instanceof WP_Error ) {
			return $task;
		}

		return $this->module->runner()->run( $task, self::STEP_BUDGET )->to_array( $task->id() );
	}

	/**
	 * Handler: `database-maintenance/get-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_get_config( array $payload = [] ): array {
		unset( $payload );

		return [
			'settings'           => $this->module->settings()->to_array(),
			'revisions_constant' => DatabaseMaintenance::revisions_constant(),
			'next_run'           => $this->module->next_run_iso(),
		];
	}

	/**
	 * Handler: `database-maintenance/save-config`.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle_save_config( array $payload ): array|WP_Error {
		if ( ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Settings payload must be an object.', 'fanxie-warden' ) );
		}

		$this->module->update_config( $payload['settings'] );
		$this->module->on_settings_saved();

		return $this->handle_get_config();
	}

	/**
	 * Resolve the requested admin-exposed task.
	 *
	 * @param array<string, mixed> $payload Raw AJAX payload.
	 */
	private function task_from( array $payload ): CleanupTask|WP_Error {
		$id   = isset( $payload['task'] ) && is_string( $payload['task'] ) ? sanitize_key( $payload['task'] ) : '';
		$task = '' === $id ? null : $this->module->task_factory()->admin_task( $id );

		return $task ?? new WP_Error( 'unknown_task', __( 'Unknown cleanup task.', 'fanxie-warden' ) );
	}
}
