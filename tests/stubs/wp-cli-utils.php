<?php
/**
 * Minimal `WP_CLI\Utils` function stubs (see wp-cli.php).
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

namespace WP_CLI\Utils;

require_once __DIR__ . '/wp-cli-progress-bar.php';

if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
	/**
	 * Record a formatted-items call as a log line.
	 *
	 * @param string                          $format Output format.
	 * @param array<int, array<string,mixed>> $items  Rows.
	 * @param array<int, string>              $fields Columns.
	 */
	function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI::log( 'format:' . $format . ' ' . wp_json_encode( array_map( static fn ( $i ) => array_intersect_key( (array) $i, array_flip( $fields ) ), $items ) ) );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\make_progress_bar' ) ) {
	/**
	 * No-op progress bar.
	 *
	 * @param string $message Label.
	 * @param int    $count   Total ticks.
	 */
	function make_progress_bar( string $message, int $count ): \cli\progress\Bar {
		unset( $message, $count );
		return new \cli\progress\Bar();
	}
}
