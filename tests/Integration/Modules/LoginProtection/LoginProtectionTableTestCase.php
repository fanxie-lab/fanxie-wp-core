<?php
/**
 * Shared base for Login Protection custom-table integration tests.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use WP_UnitTestCase;

/**
 * Guarantees each test begins with empty `fanxie_core_login_log` and
 * `fanxie_core_login_bans` tables, independent of suite execution order.
 *
 * `WP_UnitTestCase` wraps every test in a database transaction that is rolled
 * back on teardown, which resets WordPress' own tables. The Login Protection
 * repositories, however, create their tables with `dbDelta`, whose
 * `CREATE TABLE` (and the `TRUNCATE`/`DROP` DDL other tests issue) forces an
 * *implicit COMMIT* in MySQL. Those commits end the surrounding test
 * transaction, so state written to these plugin-owned custom tables can survive
 * the rollback — and WordPress never resets custom tables it does not know
 * about. Two order-dependent failure modes result once another class (e.g.
 * `PluginBootTest`, which runs `Plugin::activate()`) has committed its schema:
 *
 *   1. Rows from one test leak into the next and break count assertions (a test
 *      expecting `total === 1` sees more).
 *   2. A `SCHEMA_VERSION_OPTION` gets committed while its table does not, so a
 *      later `install()` short-circuits on the version gate and the table is
 *      never (re)created — INSERTs then hit "table doesn't exist".
 *
 * Deleting the version options (forcing `dbDelta` to actually run), installing,
 * and then TRUNCATEing both tables in `setUp()` neutralises both modes and
 * gives every test a clean, order-independent slate. This is
 * test-infrastructure only and never touches production code.
 */
abstract class LoginProtectionTableTestCase extends WP_UnitTestCase {

	/**
	 * Ensure both Login Protection tables exist and are empty before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$log  = new LoginLogRepository();
		$bans = new BanRepository();

		// Clear any schema-version option a prior test committed, so install()
		// cannot short-circuit on the gate and leave the table missing; dbDelta
		// then (re)creates the table when absent and no-ops when it already
		// matches.
		delete_option( LoginLogRepository::SCHEMA_VERSION_OPTION );
		delete_option( BanRepository::SCHEMA_VERSION_OPTION );

		$log->install();
		$bans->install();

		global $wpdb;
		foreach ( [ $log->table_name(), $bans->table_name() ] as $table ) {
			// TRUNCATE is DDL against an internal, test-owned custom table; there
			// is nothing to prepare or cache and the table name is derived from a
			// trusted constant via the repository, not from user input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}
}
