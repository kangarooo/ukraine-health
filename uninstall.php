<?php
/**
 * Removes data only when the site owner opts in (define UH_DELETE_DATA true in wp-config.php).
 *
 * @package UkraineHealth
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( ! defined( 'UH_DELETE_DATA' ) || ! UH_DELETE_DATA ) {
	return;
}
global $wpdb;
foreach ( array( 'log', 'reports', 'invites', 'requests', 'groups', 'services', 'hospitals' ) as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'uh_' . $t ); // phpcs:ignore
}
delete_option( 'uh_settings' );
delete_option( 'uh_pages' );
delete_option( 'uh_db_version' );
delete_metadata( 'user', 0, 'uh_invited_by', '', true );
delete_metadata( 'user', 0, 'uh_invite_id', '', true );
delete_metadata( 'user', 0, 'uh_suspended', '', true );
delete_metadata( 'user', 0, 'uh_supporter_ok', '', true );
delete_metadata( 'user', 0, 'uh_approved', '', true );
delete_metadata( 'user', 0, 'uh_hold', '', true );
delete_metadata( 'user', 0, 'uh_coc_at', '', true );
delete_metadata( 'user', 0, 'uh_tg_link', '', true );
wp_clear_scheduled_hook( 'uh_hourly' );
remove_role( 'uh_hero' );
remove_role( 'uh_supporter' );
remove_role( 'uh_coordinator' );
