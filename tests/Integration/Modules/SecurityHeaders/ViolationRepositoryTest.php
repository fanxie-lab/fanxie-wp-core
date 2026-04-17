<?php
/**
 * Integration tests for ViolationRepository.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\SecurityHeaders;

use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRecord;
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository;
use WP_UnitTestCase;

/**
 * Integration tests for ViolationRepository.
 */
final class ViolationRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var ViolationRepository
	 */
	private ViolationRepository $repo;

	protected function set_up(): void {
		parent::set_up();

		delete_option( ViolationRepository::SCHEMA_VERSION_OPTION );
		$this->repo = new ViolationRepository();
		$this->repo->install();
	}

	protected function tear_down(): void {
		global $wpdb;
		$table = $this->repo->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( ViolationRepository::SCHEMA_VERSION_OPTION );
		parent::tear_down();
	}

	public function test_install_is_idempotent(): void {
		$before = get_option( ViolationRepository::SCHEMA_VERSION_OPTION );
		$this->repo->install();
		$after = get_option( ViolationRepository::SCHEMA_VERSION_OPTION );

		$this->assertSame( ViolationRepository::SCHEMA_VERSION, $before );
		$this->assertSame( $before, $after );
	}

	public function test_record_inserts_a_row(): void {
		$violation = new ViolationRecord( null, 'script-src', 'https://evil.test/x.js', 'https://example.test/page' );

		$this->repo->record( $violation );

		$result = $this->repo->query( [], 1, 25 );

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'script-src', $result['rows'][0]->directive );
		$this->assertSame( 1, $result['rows'][0]->count );
	}

	public function test_record_dedups_within_one_hour_window(): void {
		$violation = new ViolationRecord( null, 'script-src', 'https://evil.test/x.js', 'https://example.test/page' );

		$this->repo->record( $violation );
		$this->repo->record( $violation );
		$this->repo->record( $violation );

		$result = $this->repo->query( [], 1, 25 );

		$this->assertSame( 1, $result['total'], 'Expected a single deduped row.' );
		$this->assertSame( 3, $result['rows'][0]->count );
	}

	public function test_query_filters_by_directive_and_paginates(): void {
		$this->repo->record( new ViolationRecord( null, 'script-src', 'https://a.test/a', 'https://example.test/1' ) );
		$this->repo->record( new ViolationRecord( null, 'script-src', 'https://b.test/b', 'https://example.test/2' ) );
		$this->repo->record( new ViolationRecord( null, 'img-src', 'https://c.test/c', 'https://example.test/3' ) );

		$script = $this->repo->query( [ 'directive' => 'script-src' ], 1, 25 );
		$this->assertSame( 2, $script['total'] );

		$paged = $this->repo->query( [], 1, 1 );
		$this->assertSame( 3, $paged['total'] );
		$this->assertCount( 1, $paged['rows'] );
	}

	public function test_prune_only_deletes_old_rows(): void {
		$this->repo->record( new ViolationRecord( null, 'script-src', 'https://a.test/a', 'https://example.test/1' ) );

		// Back-date the row to be prune-able.
		global $wpdb;
		$table = $this->repo->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$table} SET last_seen_at = '2000-01-01 00:00:00'" );

		$this->repo->record( new ViolationRecord( null, 'img-src', 'https://b.test/b', 'https://example.test/2' ) );

		$deleted = $this->repo->prune( 1 );
		$this->assertSame( 1, $deleted );

		$remaining = $this->repo->query( [], 1, 25 );
		$this->assertSame( 1, $remaining['total'] );
		$this->assertSame( 'img-src', $remaining['rows'][0]->directive );
	}

	public function test_purge_all_empties_table(): void {
		$this->repo->record( new ViolationRecord( null, 'script-src', 'https://a.test/a', 'https://example.test/1' ) );
		$this->repo->record( new ViolationRecord( null, 'img-src', 'https://b.test/b', 'https://example.test/2' ) );

		$deleted = $this->repo->purge_all();

		$this->assertSame( 2, $deleted );
		$this->assertSame( 0, $this->repo->query( [], 1, 25 )['total'] );
	}
}
