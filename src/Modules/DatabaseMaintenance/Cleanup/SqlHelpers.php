<?php
/**
 * Shared SQL fragments for cleanup tasks.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Shared SQL fragments for cleanup tasks.
 */
final class SqlHelpers {

	/**
	 * Build an ` AND col NOT IN (...)` fragment with placeholders.
	 *
	 * @param string           $column Column name to check.
	 * @param list<int|string> $ids    Identifiers to exclude from results.
	 * @return array{0: string, 1: list<int|string>} SQL fragment and values.
	 */
	public static function not_in( string $column, array $ids ): array {
		if ( [] === $ids ) {
			return [ '', [] ];
		}

		$placeholders = implode( ',', array_map( static fn ( $id ): string => is_int( $id ) ? '%d' : '%s', $ids ) );

		return [ " AND {$column} NOT IN ({$placeholders})", array_values( $ids ) ];
	}
}
