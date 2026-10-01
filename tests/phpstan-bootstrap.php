<?php
/**
 * PHPStan-only bootstrap stub.
 *
 * This file is loaded by PHPStan via `bootstrapFiles` in `phpstan.neon.dist`
 * to declare the runtime constants that `fanxie-warden.php` defines lazily
 * at request time (which PHPStan cannot observe during static analysis).
 *
 * Not executed at runtime. Also reused as the bootstrap for the Phase 0.3
 * PHPUnit harness once that lands.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

if ( ! defined( 'FANXIE_WARDEN_VERSION' ) ) {
	define( 'FANXIE_WARDEN_VERSION', '0.1.0-dev' );
}

if ( ! defined( 'FANXIE_WARDEN_FILE' ) ) {
	// Point at the real plugin bootstrap, not this file. Several places
	// (`register_activation_hook`, `plugin_dir_path`) derive the plugin root
	// from this constant — pointing it at `tests/phpstan-bootstrap.php` would
	// shift `FANXIE_WARDEN_PATH` one directory too deep.
	define( 'FANXIE_WARDEN_FILE', dirname( __DIR__ ) . '/fanxie-warden.php' );
}

if ( ! defined( 'FANXIE_WARDEN_PATH' ) ) {
	define( 'FANXIE_WARDEN_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'FANXIE_WARDEN_URL' ) ) {
	define( 'FANXIE_WARDEN_URL', 'http://example.test/wp-content/plugins/fanxie-warden/' );
}

if ( ! defined( 'FANXIE_WARDEN_MIN_PHP' ) ) {
	define( 'FANXIE_WARDEN_MIN_PHP', '8.1' );
}

if ( ! defined( 'FANXIE_WARDEN_MIN_WP' ) ) {
	define( 'FANXIE_WARDEN_MIN_WP', '6.4' );
}
