<?php
/**
 * Minimal `wpdb` shim for the unit suite.
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital -- the shim must mirror the real WordPress class name exactly, which is lowercase.
/**
 * Test-scope stand-in for the WordPress `wpdb` class.
 *
 * Implements only the two version accessors the Environment Health module
 * reads. Tests set the reported server string directly.
 */
class wpdb {

	/**
	 * Value returned by `db_server_info()`.
	 *
	 * @var string
	 */
	public string $server_info = '';

	/**
	 * Raw server version string, as `mysqli_get_server_info()` would report it.
	 */
	public function db_server_info(): string {
		return $this->server_info;
	}

	/**
	 * Numeric-only version, mirroring core's lossy `wpdb::db_version()`.
	 */
	public function db_version(): string {
		return (string) preg_replace( '/[^0-9.].*/', '', $this->server_info );
	}
}
// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital
