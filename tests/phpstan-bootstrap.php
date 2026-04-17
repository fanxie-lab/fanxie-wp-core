<?php
/**
 * PHPStan-only bootstrap stub.
 *
 * This file is loaded by PHPStan via `bootstrapFiles` in `phpstan.neon.dist`
 * to declare the runtime constants that `fanxie-wp-core.php` defines lazily
 * at request time (which PHPStan cannot observe during static analysis).
 *
 * Not executed at runtime. Also reused as the bootstrap for the Phase 0.3
 * PHPUnit harness once that lands.
 *
 * @package FanxieLab\WPCore
 */

declare( strict_types=1 );

if ( ! defined( 'FANXIE_WP_CORE_VERSION' ) ) {
	define( 'FANXIE_WP_CORE_VERSION', '0.1.0-dev' );
}

if ( ! defined( 'FANXIE_WP_CORE_FILE' ) ) {
	define( 'FANXIE_WP_CORE_FILE', __FILE__ );
}

if ( ! defined( 'FANXIE_WP_CORE_PATH' ) ) {
	define( 'FANXIE_WP_CORE_PATH', __DIR__ . '/' );
}

if ( ! defined( 'FANXIE_WP_CORE_URL' ) ) {
	define( 'FANXIE_WP_CORE_URL', 'http://example.test/wp-content/plugins/fanxie-wp-core/' );
}

if ( ! defined( 'FANXIE_WP_CORE_MIN_PHP' ) ) {
	define( 'FANXIE_WP_CORE_MIN_PHP', '8.1' );
}

if ( ! defined( 'FANXIE_WP_CORE_MIN_WP' ) ) {
	define( 'FANXIE_WP_CORE_MIN_WP', '6.4' );
}
