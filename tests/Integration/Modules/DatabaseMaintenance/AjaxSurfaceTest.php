<?php
/**
 * Integration tests for the Database Maintenance AJAX surface.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\TaskFactory;

/**
 * Covers nonce, capability and payload contracts of every sub-action.
 */
final class AjaxSurfaceTest extends DatabaseMaintenanceTestCase {

	public function test_get_status_lists_every_admin_task_in_order(): void {
		$response = $this->dispatch( 'database-maintenance/get-status' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( [ 'items', 'total_bytes', 'object_cache' ], array_keys( $response['data'] ) );
		$this->assertSame( TaskFactory::ADMIN_IDS, array_column( $response['data']['items'], 'id' ) );
		$this->assertSame( [ 'id', 'label', 'count', 'bytes' ], array_keys( $response['data']['items'][0] ) );
	}

	public function test_preview_returns_sample_and_deletes_nothing(): void {
		$post = self::factory()->post->create();
		$c    = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => '2020-01-01 00:00:00',
			]
		);

		$response = $this->dispatch( 'database-maintenance/preview', [ 'task' => 'spam-comments' ] );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['count'] );
		$this->assertCount( 1, $response['data']['sample'] );
		$this->assertNotNull( get_comment( $c ) );
	}

	public function test_purge_step_deletes_and_reports_done(): void {
		$post = self::factory()->post->create();
		$c    = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_date_gmt' => '2020-01-01 00:00:00',
			]
		);

		$response = $this->dispatch( 'database-maintenance/purge-step', [ 'task' => 'spam-comments' ] );

		$this->assertSame(
			[
				'id'        => 'spam-comments',
				'deleted'   => 1,
				'failed'    => 0,
				'remaining' => 0,
				'done'      => true,
				'busy'      => false,
			],
			$response['data']
		);
		$this->assertNull( get_comment( $c ) );
	}

	/**
	 * @dataProvider rejected_tasks
	 *
	 * @param string $task Task id that must be refused.
	 */
	public function test_unknown_or_cli_only_task_is_rejected( string $task ): void {
		$response = $this->dispatch( 'database-maintenance/purge-step', [ 'task' => $task ] );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'unknown_task', $response['data']['code'] );
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function rejected_tasks(): array {
		return [ [ 'all-transients' ], [ 'nope' ], [ '' ] ];
	}

	public function test_get_and_save_config_round_trip_with_clamping(): void {
		$get = $this->dispatch( 'database-maintenance/get-config' );
		$this->assertSame( [ 'settings', 'revisions_constant', 'next_run' ], array_keys( $get['data'] ) );
		$this->assertNull( $get['data']['revisions_constant'] );

		$settings                   = $get['data']['settings'];
		$settings['revisions_keep'] = 999;
		$saved                      = $this->dispatch( 'database-maintenance/save-config', [ 'settings' => $settings ] );

		$this->assertTrue( $saved['success'] );
		$this->assertSame( 50, $saved['data']['settings']['revisions_keep'] );
	}

	public function test_save_config_rejects_a_non_object(): void {
		$response = $this->dispatch( 'database-maintenance/save-config', [ 'settings' => 'x' ] );
		$this->assertFalse( $response['success'] );
	}

	public function test_subscriber_is_forbidden(): void {
		$this->admin_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$response            = $this->dispatch( 'database-maintenance/get-status' );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'forbidden', $response['data']['code'] );
	}

	public function test_bad_nonce_is_rejected(): void {
		$response = $this->dispatch( 'database-maintenance/get-status', [], 'not-a-nonce' );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['data']['code'] );
	}
}
