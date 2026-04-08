<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}swpb_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}swpb_categories" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}swpb_pricelists" );

delete_option( 'swpb_settings' );
delete_option( 'swpb_db_version' );
