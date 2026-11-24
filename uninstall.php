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
foreach ( array( 'log', 'requests', 'groups', 'services', 'hospitals' ) as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'uh_' . $t ); // phpcs:ignore
}
delete_option( 'uh_settings' );
delete_option( 'uh_pages' );
delete_option( 'uh_db_version' );
remove_role( 'uh_hero' );
remove_role( 'uh_supporter' );
remove_role( 'uh_coordinator' );
