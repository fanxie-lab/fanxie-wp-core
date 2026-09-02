<?php
/**
 * Plugin Name:       Fanxie WP Core
 * Plugin URI:        https://fanxielab.com/plugins/fanxie-wp-core
 * Description:       Modular WordPress security, hardening, maintenance, and performance toolkit by Fanxie Lab.
 * Version:           0.1.0-dev
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:            Fanxie Lab
 * Author URI:        https://fanxielab.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fanxie-wp-core
 * Domain Path:       /languages
 *
 * @package FanxieLab\WPCore
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Plugin constants
 *
 * All six constants are re-asserted via `defined() || define()` so that the
 * plugin file is safe to re-require (the PHPUnit integration bootstrap and the
 * PHPStan bootstrap both predefine a subset of these constants before this
 * file runs).
 * -----------------------------------------------------------------------------
 */
defined( 'FANXIE_WP_CORE_VERSION' ) || define( 'FANXIE_WP_CORE_VERSION', '0.1.0-dev' );
defined( 'FANXIE_WP_CORE_FILE' ) || define( 'FANXIE_WP_CORE_FILE', __FILE__ );
defined( 'FANXIE_WP_CORE_PATH' ) || define( 'FANXIE_WP_CORE_PATH', plugin_dir_path( __FILE__ ) );
defined( 'FANXIE_WP_CORE_URL' ) || define( 'FANXIE_WP_CORE_URL', plugin_dir_url( __FILE__ ) );
defined( 'FANXIE_WP_CORE_MIN_PHP' ) || define( 'FANXIE_WP_CORE_MIN_PHP', '8.1' );
defined( 'FANXIE_WP_CORE_MIN_WP' ) || define( 'FANXIE_WP_CORE_MIN_WP', '6.4' );

/*
 * -----------------------------------------------------------------------------
 * Environment gate — refuse to boot on unsupported PHP/WP versions.
 *
 * We deliberately avoid fatal errors: surface a clear admin notice and return.
 * -----------------------------------------------------------------------------
 */
if ( version_compare( PHP_VERSION, FANXIE_WP_CORE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Fanxie WP Core requires PHP %1$s or higher. You are running PHP %2$s. The plugin is inactive until PHP is upgraded.', 'fanxie-wp-core' ),
						FANXIE_WP_CORE_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, FANXIE_WP_CORE_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () use ( $wp_version ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required WordPress version, 2: current WordPress version */
						__( 'Fanxie WP Core requires WordPress %1$s or higher. You are running WordPress %2$s. The plugin is inactive until WordPress is upgraded.', 'fanxie-wp-core' ),
						FANXIE_WP_CORE_MIN_WP,
						$wp_version
					)
				)
			);
		}
	);
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Composer autoloader.
 *
 * `vendor/` is not committed during development (see `.gitignore`). If the
 * autoloader is missing we surface a notice and bail out instead of fataling.
 * Release tarballs ship with `vendor/` so this branch is only hit in dev.
 * -----------------------------------------------------------------------------
 */
$fanxie_wp_core_autoload = FANXIE_WP_CORE_PATH . 'vendor/autoload.php';

if ( ! file_exists( $fanxie_wp_core_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Fanxie WP Core: Composer dependencies are missing. Run `composer install` inside the plugin directory.', 'fanxie-wp-core' )
			);
		}
	);
	return;
}

require_once $fanxie_wp_core_autoload;
unset( $fanxie_wp_core_autoload );

/*
 * -----------------------------------------------------------------------------
 * Lifecycle hooks.
 * -----------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, [ \FanxieLab\WPCore\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \FanxieLab\WPCore\Plugin::class, 'deactivate' ] );

/*
 * -----------------------------------------------------------------------------
 * Boot the plugin once WordPress has loaded all plugins.
 *
 * Text domain loading is handled inside Plugin::boot() on `init` (WP requires
 * text-domain registration on or after `init` since 6.7).
 * -----------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\FanxieLab\WPCore\Plugin::boot();
	}
);
