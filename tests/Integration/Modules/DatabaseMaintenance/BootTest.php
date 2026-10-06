<?php
/**
 * Integration tests for Database Maintenance module boot and revision cap.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Modules\DatabaseMaintenance\DatabaseMaintenance;

/**
 * Integration tests for Database Maintenance module boot and revision cap.
 */
final class DatabaseMaintenanceBootTest extends DatabaseMaintenanceTestCase {

	public function test_module_is_registered_with_spec_defaults(): void {
		$config = $this->module()->get_config();

		$this->assertFalse( $config['revision_limit_enabled'] );
		$this->assertSame( 20, $config['revisions_keep'] );
		$this->assertSame( 'weekly', $config['schedule_frequency'] );
	}

	public function test_core_default_revisions_constant_does_not_count_as_locked(): void {
		// Core defines WP_POST_REVISIONS = true when wp-config does not.
		$this->assertTrue( WP_POST_REVISIONS );
		$this->assertNull( DatabaseMaintenance::revisions_constant() );
	}

	public function test_revision_cap_is_off_by_default(): void {
		$post = self::factory()->post->create_and_get();
		$this->assertSame( -1, wp_revisions_to_keep( $post ) );
	}

	public function test_enabled_cap_limits_future_revisions(): void {
		$this->module()->update_config( [ 'revision_limit_enabled' => true, 'revisions_keep' => 5 ] );
		$post = self::factory()->post->create_and_get();

		$this->assertSame( 5, wp_revisions_to_keep( $post ) );
	}

	public function test_saved_out_of_range_keep_is_clamped(): void {
		$this->module()->update_config( [ 'revision_limit_enabled' => true, 'revisions_keep' => 900 ] );
		$this->assertSame( 50, $this->module()->get_config()['revisions_keep'] );
	}
}
