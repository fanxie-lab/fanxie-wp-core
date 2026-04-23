<?php
/**
 * Integration tests for the REST users endpoint guard.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\Runtime\UserEnumerationGuard;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Asserts `/wp/v2/users` 401s for guests and 200s for admins.
 */
final class RestUsersEndpointTest extends WP_UnitTestCase {

	protected function set_up(): void {
		parent::set_up();

		$guard = new UserEnumerationGuard(
			[
				'user_enumeration' => [
					'block_author_archive'      => true,
					'block_rest_users_endpoint' => true,
				],
			]
		);
		$guard->register_hooks();
	}

	public function test_guest_gets_401_on_users_endpoint(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_admin_gets_200_on_users_endpoint(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
