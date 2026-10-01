<?php
/**
 * Every transient row, valid or not. CLI-only (`--all`).
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Removes every transient and site transient, expired or not.
 */
final class AllTransientsTask implements CleanupTask {

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'all-transients';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return __( 'All transients', 'fanxie-warden' );
	}

	/**
	 * Value-row option names (timeout rows excluded).
	 *
	 * @param int              $limit   Maximum rows.
	 * @param list<int|string> $exclude Option names to skip.
	 * @return list<string>
	 */
	private function names( int $limit, array $exclude = [] ): array {
		global $wpdb;

		[ $not_in, $not_in_args ] = SqlHelpers::not_in( 'option_name', array_map( 'strval', $exclude ) );

		$sql  = "SELECT option_name FROM {$wpdb->options}
			WHERE ( option_name LIKE %s OR option_name LIKE %s )
			AND option_name NOT LIKE %s AND option_name NOT LIKE %s{$not_in}
			ORDER BY option_id ASC LIMIT %d";
		$args = array_merge(
			[
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			],
			$not_in_args,
			[ $limit ]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $not_in is placeholders only; core table name.
		return array_values( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ) );
	}

	/**
	 * Number of transient rows.
	 */
	public function count(): int {
		return count( $this->names( PHP_INT_MAX ) );
	}

	/**
	 * Approximate bytes held by all transient rows.
	 */
	public function estimate_bytes(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- live estimate; core table name.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);
	}

	/**
	 * Preview rows for the confirmation dialog.
	 *
	 * @param int $n Maximum number of rows.
	 * @return list<array{label: string, detail: string, date: string|null}>
	 */
	public function sample( int $n ): array {
		return array_map(
			static fn ( string $name ): array => [
				'label'  => (string) preg_replace( '/^_(site_)?transient_/', '', $name ),
				'detail' => str_starts_with( $name, '_site_transient_' ) ? __( 'Site transient', 'fanxie-warden' ) : __( 'Transient', 'fanxie-warden' ),
				'date'   => null,
			],
			$this->names( $n )
		);
	}

	/**
	 * Delete a batch of transients together with their timeout rows.
	 *
	 * @param int              $limit   Maximum rows to delete.
	 * @param list<int|string> $exclude Option names that previously failed.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		$names   = $this->names( $limit, $exclude );
		$deleted = 0;
		$failed  = [];

		foreach ( $names as $name ) {
			$timeout = str_starts_with( $name, '_site_transient_' )
				? '_site_transient_timeout_' . substr( $name, strlen( '_site_transient_' ) )
				: '_transient_timeout_' . substr( $name, strlen( '_transient_' ) );

			if ( delete_option( $name ) ) {
				delete_option( $timeout );
				++$deleted;
			} else {
				$failed[] = $name;
			}
		}

		return new BatchResult( $deleted, $failed, count( $names ) < $limit );
	}
}
