<?php
/**
 * Integration tests for the version-gated custom-table upgrade path.
 *
 * Runs inside the @wordpress/env `tests` container with a real WordPress core.
 *
 * `WP_UnitTestCase` normally rewrites every `CREATE TABLE` / `DROP TABLE` into
 * its `TEMPORARY` form (via two `query` filters) and wraps the test in a
 * transaction, so plugin schema changes live only as session-local shadows and
 * never touch the real base tables or show up in `SHOW TABLES`. This migration
 * is specifically about *real base tables* being (re)created on upgrade, so
 * {@see self::set_up()} drops those two filters for the class; {@see self::tear_down()}
 * restores the tables and the current DB version so a dropped-table test cannot
 * leak a missing schema into later suites.
 *
 * @package FanxieLab\Warden\Tests\Integration\Plugin
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Plugin;

use FanxieLab\Warden\Modules\LoginProtection\BanRepository;
use FanxieLab\Warden\Modules\LoginProtection\LoginLogRepository;
use FanxieLab\Warden\Plugin;
use WP_UnitTestCase;

/**
 * Exercises {@see Plugin::maybe_upgrade()} — the self-healing migration that
 * (re)creates module-owned tables when an already-active plugin is updated to
 * new code (activation does not run on upgrade).
 */
final class DbUpgradeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Operate on real base tables (not WP's transactional TEMPORARY shadows)
		// so DROP/CREATE and SHOW TABLES reflect the schema the migration manages.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		// Restore both Login Protection tables and the current DB version so a
		// dropped-table test cannot break later classes in the combined suite.
		delete_option( LoginLogRepository::SCHEMA_VERSION_OPTION );
		delete_option( BanRepository::SCHEMA_VERSION_OPTION );

		( new LoginLogRepository() )->install();
		( new BanRepository() )->install();

		update_option( Plugin::DB_VERSION_OPTION, Plugin::DB_VERSION );

		parent::tear_down();
	}

	/**
	 * An upgrade (missing login tables + stale DB version) re-creates the tables
	 * and records the current DB version.
	 */
	public function test_upgrade_creates_missing_login_tables(): void {
		$log  = new LoginLogRepository();
		$bans = new BanRepository();

		// Simulate a site that predates the login tables: drop them, clear the
		// per-table schema gates (so install() actually runs rather than
		// short-circuiting), and roll the stored DB version back a notch.
		$this->drop_table( $log->table_name() );
		$this->drop_table( $bans->table_name() );
		delete_option( LoginLogRepository::SCHEMA_VERSION_OPTION );
		delete_option( BanRepository::SCHEMA_VERSION_OPTION );
		update_option( Plugin::DB_VERSION_OPTION, '1' );

		$this->assertNull( $this->table_exists( $log->table_name() ), 'Login log table should be absent before the upgrade.' );
		$this->assertNull( $this->table_exists( $bans->table_name() ), 'Login bans table should be absent before the upgrade.' );

		$this->run_upgrade();

		$this->assertSame( $log->table_name(), $this->table_exists( $log->table_name() ), 'Login log table should exist after the upgrade.' );
		$this->assertSame( $bans->table_name(), $this->table_exists( $bans->table_name() ), 'Login bans table should exist after the upgrade.' );
		$this->assertSame( Plugin::DB_VERSION, (string) get_option( Plugin::DB_VERSION_OPTION ), 'Stored DB version should advance to the current version.' );
	}

	/**
	 * A second upgrade call, once the version already matches, is a clean no-op:
	 * it must not run the table installs again. We prove that by dropping a table
	 * *after* the version is current and confirming the no-op leaves it dropped.
	 */
	public function test_second_upgrade_is_a_noop(): void {
		$log = new LoginLogRepository();

		// First run brings the schema current and stamps DB_VERSION.
		update_option( Plugin::DB_VERSION_OPTION, '1' );
		delete_option( LoginLogRepository::SCHEMA_VERSION_OPTION );
		delete_option( BanRepository::SCHEMA_VERSION_OPTION );
		$this->run_upgrade();
		$this->assertSame( Plugin::DB_VERSION, (string) get_option( Plugin::DB_VERSION_OPTION ) );

		// Now break the schema behind the version gate's back and re-run. Because
		// the stored version already equals DB_VERSION, maybe_upgrade() must
		// short-circuit before install_tables() and leave the table missing.
		$this->drop_table( $log->table_name() );
		delete_option( LoginLogRepository::SCHEMA_VERSION_OPTION );

		$this->run_upgrade();

		$this->assertNull( $this->table_exists( $log->table_name() ), 'A no-op upgrade must not re-create tables when the version already matches.' );
		$this->assertSame( Plugin::DB_VERSION, (string) get_option( Plugin::DB_VERSION_OPTION ), 'The DB version should be unchanged by the no-op.' );
	}

	/**
	 * Invoke the migration through the booted plugin instance.
	 */
	private function run_upgrade(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin, 'Plugin::boot() should have run on plugins_loaded.' );
		$plugin->maybe_upgrade();
	}

	/**
	 * Drop a base table if present (DDL — forces an implicit commit).
	 *
	 * @param string $table Fully-qualified table name from a repository.
	 */
	private function drop_table( string $table ): void {
		global $wpdb;

		// Table name comes from a repository constant, not user input; DDL against
		// an internal test-owned table has nothing to prepare or cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * The table name when the base table exists, or null when it does not.
	 *
	 * @param string $table Fully-qualified table name from a repository.
	 * @return string|null
	 */
	private function table_exists( string $table ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) ? $found : null;
	}
}
