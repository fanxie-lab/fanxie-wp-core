<?php
/**
 * Unit tests for the Database Maintenance revision limit filter.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\DatabaseMaintenance\DatabaseMaintenance;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database Maintenance revision limit filter.
 */
final class DatabaseMaintenanceRevisionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( [ 'revision_limit_enabled' => true, 'revisions_keep' => 5 ] );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_config_value_wins_over_the_setting(): void {
		define( 'WP_POST_REVISIONS', 3 );
		$module = new DatabaseMaintenance( new AjaxRouter() );

		$this->assertSame( 3, DatabaseMaintenance::revisions_constant() );
		$this->assertSame( 3, $module->filter_revisions_to_keep( 3 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_default_true_applies_the_setting(): void {
		define( 'WP_POST_REVISIONS', true );
		$module = new DatabaseMaintenance( new AjaxRouter() );

		$this->assertNull( DatabaseMaintenance::revisions_constant() );
		$this->assertSame( 5, $module->filter_revisions_to_keep( -1 ) );
	}
}
