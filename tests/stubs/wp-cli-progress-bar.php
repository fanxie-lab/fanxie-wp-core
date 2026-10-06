<?php
/**
 * Minimal `cli\progress\Bar` stub (see wp-cli.php).
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

namespace cli\progress;

if ( ! class_exists( Bar::class, false ) ) {

	/**
	 * No-op stand-in for the WP-CLI progress bar.
	 */
	class Bar {

		/**
		 * Advance the bar.
		 *
		 * @param int $n Ticks.
		 */
		public function tick( int $n = 1 ): void {
			unset( $n );
		}

		/**
		 * Finish the bar.
		 */
		public function finish(): void {}
	}
}
