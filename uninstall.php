<?php
/**
 * Fanxie Warden — Uninstall handler.
 *
 * Runs when the plugin is deleted (not merely deactivated) via the WordPress
 * plugins screen or WP-CLI.
 *
 * Data removal is **opt-in**. Nothing is wiped unless one of the following
 * is true:
 *
 *   1. The `FX_CORE_DELETE_ALL_DATA` constant is defined and truthy
 *      (typically added to `wp-config.php` for site-owner control).
 *   2. The stored option `fanxie_warden_delete_on_uninstall` equals `'yes'`
 *      (set via the admin UI).
 *
 * A filter — `fanxie_warden/uninstall/delete_data` — receives the resolved
 * boolean and can override the decision programmatically.
 *
 * When wipe is authorised we:
 *   - Delete every option whose name starts with `fanxie_warden_`
 *     (both regular + site transients for consistency).
 *   - Drop every custom table matching `{$wpdb->prefix}fanxie_core_%`.
 *   - Remove the `manage_fanxie_warden` capability from every role.
 *
 * We intentionally do *not* touch user meta: no core module stores user-keyed
 * data under a known prefix at this phase. If/when that changes, this file
 * must be updated alongside the feature that introduces it.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Decide whether the user has opted into data removal.
 *
 * @return bool
 */
$fanxie_warden_should_delete = false;

if ( defined( 'FX_CORE_DELETE_ALL_DATA' ) && FX_CORE_DELETE_ALL_DATA ) {
	$fanxie_warden_should_delete = true;
}

if ( ! $fanxie_warden_should_delete ) {
	$fanxie_warden_opt_in = get_option( 'fanxie_warden_delete_on_uninstall', 'no' );
	if ( 'yes' === $fanxie_warden_opt_in ) {
		$fanxie_warden_should_delete = true;
	}
}

/**
 * Filter: fanxie_warden/uninstall/delete_data
 *
 * Programmatic override of the uninstall opt-in. Return true to force a wipe,
 * false to block one even when the user opted in.
 *
 * @param bool $should_delete Whether data should be deleted.
 */
$fanxie_warden_should_delete = (bool) apply_filters( 'fanxie_warden/uninstall/delete_data', $fanxie_warden_should_delete );

if ( ! $fanxie_warden_should_delete ) {
	return;
}

global $wpdb;

/*
 * -----------------------------------------------------------------------------
 * 1. Options.
 * -----------------------------------------------------------------------------
 */
// The `LIKE 'fanxie_warden_%'` sweep catches every plugin option, including
// legacy `_enabled` flags that no longer drive behaviour after the module
// enabled-gate was removed — no targeted migration needed.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- core exposes no API for enumerating options by name prefix, and an uninstall runs once against rows that are deleted moments later, so there is nothing worth caching.
$fanxie_warden_option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'fanxie_warden_' ) . '%'
	)
);

if ( is_array( $fanxie_warden_option_names ) ) {
	foreach ( $fanxie_warden_option_names as $fanxie_warden_option_name ) {
		delete_option( $fanxie_warden_option_name );
	}
}

// Multisite: mirror cleanup for network options.
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- network-option equivalent of the sweep above: no core API enumerates sitemeta by key prefix, and the rows are deleted immediately after.
	$fanxie_warden_site_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'fanxie_warden_' ) . '%'
		)
	);

	if ( is_array( $fanxie_warden_site_options ) ) {
		foreach ( $fanxie_warden_site_options as $fanxie_warden_site_option ) {
			delete_site_option( $fanxie_warden_site_option );
		}
	}
}

/*
 * -----------------------------------------------------------------------------
 * 2. Custom tables.
 * -----------------------------------------------------------------------------
 */
$fanxie_warden_table_prefix = $wpdb->prefix . 'fanxie_core_';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES has no WordPress API wrapper; it is the only way to discover which of this plugin's tables were ever created, and the answer is invalidated by the DROP TABLE loop directly below it.
$fanxie_warden_tables = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $fanxie_warden_table_prefix ) . '%'
	)
);

if ( is_array( $fanxie_warden_tables ) ) {
	foreach ( $fanxie_warden_tables as $fanxie_warden_table ) {
		// Table names cannot be parameterised; the source is an internal SHOW TABLES
		// result filtered by our own prefix, so this is safe.
		$fanxie_warden_escaped_table = esc_sql( (string) $fanxie_warden_table );
		if ( ! is_string( $fanxie_warden_escaped_table ) || '' === $fanxie_warden_escaped_table ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table identifier cannot be parameterised; the value is sourced from SHOW TABLES filtered by our own prefix and passed through esc_sql(); DROP TABLE is the intended schema change at uninstall time.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $fanxie_warden_escaped_table . '`' );
	}
}

/*
 * -----------------------------------------------------------------------------
 * 3. Custom capability.
 * -----------------------------------------------------------------------------
 */
if ( function_exists( 'wp_roles' ) ) {
	$fanxie_warden_roles = wp_roles();

	if ( $fanxie_warden_roles instanceof WP_Roles ) {
		foreach ( array_keys( $fanxie_warden_roles->roles ) as $fanxie_warden_role_slug ) {
			$fanxie_warden_role = get_role( $fanxie_warden_role_slug );
			if ( $fanxie_warden_role instanceof WP_Role && $fanxie_warden_role->has_cap( 'manage_fanxie_warden' ) ) {
				$fanxie_warden_role->remove_cap( 'manage_fanxie_warden' );
			}
		}
	}
}
