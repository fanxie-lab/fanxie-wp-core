<?php
/**
 * Fanxie WP Core — Uninstall handler.
 *
 * Runs when the plugin is deleted (not merely deactivated) via the WordPress
 * plugins screen or WP-CLI.
 *
 * Data removal is **opt-in**. Nothing is wiped unless one of the following
 * is true:
 *
 *   1. The `FANXIE_WP_CORE_DELETE_ALL_DATA` constant is defined and truthy
 *      (typically added to `wp-config.php` for site-owner control).
 *   2. The stored option `fanxie_wp_core_delete_on_uninstall` equals `'yes'`
 *      (set via the admin UI).
 *
 * A filter — `fanxie_wp_core/uninstall/delete_data` — receives the resolved
 * boolean and can override the decision programmatically.
 *
 * When wipe is authorised we:
 *   - Delete every option whose name starts with `fanxie_wp_core_`
 *     (both regular + site transients for consistency).
 *   - Drop every custom table matching `{$wpdb->prefix}fanxie_%`.
 *   - Remove the `manage_fanxie_wp_core` capability from every role.
 *
 * We intentionally do *not* touch user meta: no core module stores user-keyed
 * data under a known prefix at this phase. If/when that changes, this file
 * must be updated alongside the feature that introduces it.
 *
 * @package FanxieLab\WPCore
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Decide whether the user has opted into data removal.
 *
 * @return bool
 */
$fanxie_wp_core_should_delete = false;

if ( defined( 'FANXIE_WP_CORE_DELETE_ALL_DATA' ) && FANXIE_WP_CORE_DELETE_ALL_DATA ) {
	$fanxie_wp_core_should_delete = true;
}

if ( ! $fanxie_wp_core_should_delete ) {
	$fanxie_wp_core_opt_in = get_option( 'fanxie_wp_core_delete_on_uninstall', 'no' );
	if ( 'yes' === $fanxie_wp_core_opt_in ) {
		$fanxie_wp_core_should_delete = true;
	}
}

/**
 * Filter: fanxie_wp_core/uninstall/delete_data
 *
 * Programmatic override of the uninstall opt-in. Return true to force a wipe,
 * false to block one even when the user opted in.
 *
 * @param bool $should_delete Whether data should be deleted.
 */
$fanxie_wp_core_should_delete = (bool) apply_filters( 'fanxie_wp_core/uninstall/delete_data', $fanxie_wp_core_should_delete );

if ( ! $fanxie_wp_core_should_delete ) {
	return;
}

global $wpdb;

/*
 * -----------------------------------------------------------------------------
 * 1. Options.
 * -----------------------------------------------------------------------------
 */
$fanxie_wp_core_option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'fanxie_wp_core_' ) . '%'
	)
);

if ( is_array( $fanxie_wp_core_option_names ) ) {
	foreach ( $fanxie_wp_core_option_names as $fanxie_wp_core_option_name ) {
		delete_option( $fanxie_wp_core_option_name );
	}
}

// Multisite: mirror cleanup for network options.
if ( is_multisite() ) {
	$fanxie_wp_core_site_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'fanxie_wp_core_' ) . '%'
		)
	);

	if ( is_array( $fanxie_wp_core_site_options ) ) {
		foreach ( $fanxie_wp_core_site_options as $fanxie_wp_core_site_option ) {
			delete_site_option( $fanxie_wp_core_site_option );
		}
	}
}

/*
 * -----------------------------------------------------------------------------
 * 2. Custom tables.
 * -----------------------------------------------------------------------------
 */
$fanxie_wp_core_table_prefix = $wpdb->prefix . 'fanxie_';
$fanxie_wp_core_tables       = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $fanxie_wp_core_table_prefix ) . '%'
	)
);

if ( is_array( $fanxie_wp_core_tables ) ) {
	foreach ( $fanxie_wp_core_tables as $fanxie_wp_core_table ) {
		// Table names cannot be parameterised; the source is an internal SHOW TABLES
		// result filtered by our own prefix, so this is safe.
		$fanxie_wp_core_escaped_table = esc_sql( (string) $fanxie_wp_core_table );
		if ( ! is_string( $fanxie_wp_core_escaped_table ) || '' === $fanxie_wp_core_escaped_table ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table identifier cannot be parameterised; the value is sourced from SHOW TABLES filtered by our own prefix and passed through esc_sql(); DROP TABLE is the intended schema change at uninstall time.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $fanxie_wp_core_escaped_table . '`' );
	}
}

/*
 * -----------------------------------------------------------------------------
 * 3. Custom capability.
 * -----------------------------------------------------------------------------
 */
if ( function_exists( 'wp_roles' ) ) {
	$fanxie_wp_core_roles = wp_roles();

	if ( $fanxie_wp_core_roles instanceof WP_Roles ) {
		foreach ( array_keys( $fanxie_wp_core_roles->roles ) as $fanxie_wp_core_role_slug ) {
			$fanxie_wp_core_role = get_role( $fanxie_wp_core_role_slug );
			if ( $fanxie_wp_core_role instanceof WP_Role && $fanxie_wp_core_role->has_cap( 'manage_fanxie_wp_core' ) ) {
				$fanxie_wp_core_role->remove_cap( 'manage_fanxie_wp_core' );
			}
		}
	}
}
