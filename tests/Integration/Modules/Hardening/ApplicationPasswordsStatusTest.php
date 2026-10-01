<?php
/**
 * Integration test — StatusInspector reports Application Password holders.
 *
 * @package FanxieLab\Warden\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\Hardening;

use FanxieLab\Warden\Modules\Hardening\StatusInspector;
use FanxieLab\Warden\Modules\Hardening\UploadsProtector;
use WP_Application_Passwords;
use WP_UnitTestCase;

/**
 * Verifies the snapshot enumerates which users hold Application Passwords, so
 * the admin UI can name them instead of showing a dead-end "revoke them" note.
 */
final class ApplicationPasswordsStatusTest extends WP_UnitTestCase {

	public function test_snapshot_lists_holders_and_matches_total(): void {
		$editor = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'app_pw_editor',
			]
		);

		WP_Application_Passwords::create_new_application_password( $editor, [ 'name' => 'CLI one' ] );
		WP_Application_Passwords::create_new_application_password( $editor, [ 'name' => 'CLI two' ] );

		$inspector = new StatusInspector( new UploadsProtector( [] ) );
		$snapshot  = $inspector->snapshot( true );

		$this->assertSame( 2, $snapshot['application_passwords_count'] );
		$this->assertContains(
			[
				'user_login' => 'app_pw_editor',
				'count'      => 2,
			],
			$snapshot['application_passwords_users']
		);
	}

	public function test_snapshot_reports_empty_holders_when_none_exist(): void {
		$inspector = new StatusInspector( new UploadsProtector( [] ) );
		$snapshot  = $inspector->snapshot( true );

		$this->assertSame( 0, $snapshot['application_passwords_count'] );
		$this->assertSame( [], $snapshot['application_passwords_users'] );
	}
}
