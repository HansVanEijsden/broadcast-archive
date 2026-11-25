<?php
/**
 * Uninstall routine: remove configuration, fetch metadata and caches.
 *
 * @package BroadcastArchive
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete this site's Broadcast Archive data.
 *
 * @return void
 */
function broadcast_archive_uninstall_site(): void {
	global $wpdb;

	delete_option( 'broadcast_archive_options' );
	delete_option( 'broadcast_archive_feed_meta' );

	$options_table = $wpdb->prefix . 'options';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$options_table} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ba_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ba_' ) . '%'
		)
	);

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'broadcast-archive' );
	}
}

if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		broadcast_archive_uninstall_site();
		restore_current_blog();
	}
} else {
	broadcast_archive_uninstall_site();
}
