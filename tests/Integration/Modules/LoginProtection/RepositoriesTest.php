<?php
/**
 * Integration tests for the Login Protection custom-table repositories.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;

/**
 * Integration tests for LoginLogRepository and BanRepository.
 *
 * Extends {@see LoginProtectionTableTestCase} so both custom tables are
 * truncated before each test, keeping the count assertions below
 * order-independent within the combined integration suite.
 */
final class RepositoriesTest extends LoginProtectionTableTestCase {
	public function test_log_install_record_query_prune(): void {
		$repo = new LoginLogRepository();
		$repo->install();
		global $wpdb;
		$this->assertSame( $repo->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $repo->table_name() ) ) );

		$repo->record( 'failed_login', '203.0.113.5', 'admin', null, [ 'tier' => 1 ] );
		$out = $repo->query( [], 1, 25 );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 'failed_login', $out['rows'][0]['event_type'] );
		$this->assertSame( '203.0.113.5', $out['rows'][0]['ip'] );
	}

	public function test_ban_add_lookup_remove_and_expiry(): void {
		$repo = new BanRepository();
		$repo->install();

		$repo->add( 'ip', '203.0.113.9', 'manual', null ); // indefinite.
		$this->assertTrue( $repo->is_banned( 'ip', '203.0.113.9' ) );
		$this->assertFalse( $repo->is_banned( 'ip', '203.0.113.10' ) );

		$this->assertSame( 1, $repo->remove( 'ip', '203.0.113.9' ) );
		$this->assertFalse( $repo->is_banned( 'ip', '203.0.113.9' ) );

		// Expired ban is not "banned" and is pruned.
		$repo->add( 'username', 'bob', 'auto', -1 ); // already-expired (ttl in the past).
		$this->assertFalse( $repo->is_banned( 'username', 'bob' ) );
		$this->assertGreaterThanOrEqual( 1, $repo->prune_expired() );
	}

	public function test_log_prune_deletes_only_old_rows(): void {
		$repo = new LoginLogRepository();
		$repo->install();

		$repo->record( 'failed_login', '198.51.100.1', 'admin', null, [] );

		// Back-date the first row so it falls outside the prune window.
		global $wpdb;
		$table = $repo->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$table} SET created_at = '2000-01-01 00:00:00'" );

		$repo->record( 'failed_login', '198.51.100.2', 'admin', null, [] );

		$this->assertSame( 1, $repo->prune( 1 ) );

		$out = $repo->query( [], 1, 25 );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( '198.51.100.2', $out['rows'][0]['ip'] );
	}

	public function test_ban_query_paginates_active_and_expired_rows(): void {
		$repo = new BanRepository();
		$repo->install();

		$repo->add( 'ip', '198.51.100.20', 'manual', null );
		$repo->add( 'username', 'carol', 'auto', 60 );

		$all = $repo->query( 1, 25 );
		$this->assertSame( 2, $all['total'] );
		$this->assertCount( 2, $all['rows'] );

		$paged = $repo->query( 1, 1 );
		$this->assertSame( 2, $paged['total'] );
		$this->assertCount( 1, $paged['rows'] );
	}

	public function test_ban_readd_upserts_in_place(): void {
		$repo = new BanRepository();
		$repo->install();

		// First ban is time-boxed and already expired.
		$repo->add( 'ip', '203.0.113.50', 'auto', -1 );
		$this->assertFalse( $repo->is_banned( 'ip', '203.0.113.50' ) );

		// Re-banning the same subject indefinitely upserts the existing row
		// (unique key) and restores a real NULL expiry on the UPDATE path.
		$repo->add( 'ip', '203.0.113.50', 'manual', null );
		$this->assertTrue( $repo->is_banned( 'ip', '203.0.113.50' ) );

		$out = $repo->query( 1, 25 );
		$this->assertSame( 1, $out['total'] );
	}
}
