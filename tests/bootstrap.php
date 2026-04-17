<?php
/**
 * PHPUnit bootstrap for Fanxie WP Core.
 *
 * Dual-mode bootstrap — the file supports two different test runs:
 *
 *   1. UNIT MODE (fast, no WordPress runtime).
 *      - Triggered when neither `WP_TESTS_DIR` nor `$_SERVER['WP_PHPUNIT__DIR']`
 *        is set.
 *      - Loads the Composer autoloader, the PHPStan constant stubs, and
 *        initialises Brain Monkey for per-test WordPress function mocks.
 *      - Test bases: `PHPUnit\Framework\TestCase`.
 *
 *   2. INTEGRATION MODE (real WordPress, inside wp-env's tests container).
 *      - Triggered when `WP_TESTS_DIR` (or `$_SERVER['WP_PHPUNIT__DIR']`) points
 *        at the WP test library that ships with the wp-env image.
 *      - Loads the full WordPress test suite and mounts `fanxie-wp-core.php`
 *        on `muplugins_loaded` so hooks are in place before the suite boots.
 *      - Test bases: `WP_UnitTestCase`.
 *
 * The plugin is launched via `npm run test:php`, which calls wp-env's
 * `tests-cli` runner with the plugin directory as its CWD and PHPUnit as
 * the entry point. See `package.json` `scripts.test:php`.
 *
 * @package FanxieLab\WPCore\Tests
 */

declare( strict_types=1 );

// Composer autoloader — unit and integration modes both need this.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Reuse the PHPStan constant stubs so we don't maintain two copies of the
// `FANXIE_WP_CORE_*` defines. Both files are idempotent via `defined()` guards.
require_once __DIR__ . '/phpstan-bootstrap.php';

/**
 * Resolve the WordPress test suite directory, if any.
 *
 * Preference order:
 *   1. `WP_TESTS_DIR` environment variable (set by `@wordpress/env`).
 *   2. `$_SERVER['WP_PHPUNIT__DIR']` (legacy / manual overrides).
 */
$fanxie_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $fanxie_tests_dir || '' === $fanxie_tests_dir ) {
	$fanxie_tests_dir = $_SERVER['WP_PHPUNIT__DIR'] ?? '';
}
$fanxie_tests_dir = is_string( $fanxie_tests_dir ) ? rtrim( $fanxie_tests_dir, '/\\' ) : '';

if ( '' !== $fanxie_tests_dir && file_exists( $fanxie_tests_dir . '/includes/functions.php' ) ) {
	// -------------------------------------------------------------------------
	// Integration mode — load the WordPress test suite.
	// -------------------------------------------------------------------------
	require_once $fanxie_tests_dir . '/includes/functions.php';

	/**
	 * Load the plugin itself before WordPress core finishes booting so that
	 * activation/upgrade hooks fire against a fully-wired plugin.
	 */
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require dirname( __DIR__ ) . '/fanxie-wp-core.php';
		}
	);

	// Hand the rest of the bootstrap to WordPress — this call never returns
	// until the WP test suite is fully initialised.
	require $fanxie_tests_dir . '/includes/bootstrap.php';
} else {
	// -------------------------------------------------------------------------
	// Unit mode — no WordPress runtime, Brain Monkey drives WP function mocks
	// inside individual tests.
	//
	// Plugin source files begin with `defined( 'ABSPATH' ) || exit;` as a
	// direct-access guard. In unit mode there is no WordPress to define
	// `ABSPATH`, so we fake one pointing at the plugin directory. The fake
	// value is never dereferenced — nothing in `src/` uses `ABSPATH` beyond
	// that single guard.
	// -------------------------------------------------------------------------
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( class_exists( \Brain\Monkey::class ) ) {
		\Brain\Monkey\setUp();
	}
}

unset( $fanxie_tests_dir );
