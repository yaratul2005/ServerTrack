<?php
/**
 * ServerTrack Uninstall
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes all stored options and order meta from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit; // Exit if accessed directly.
}

// Remove all plugin options
$options = [
    'servertrack_enabled',
    'servertrack_test_mode',
    'servertrack_consent_mode',
    'servertrack_meta_enabled',
    'servertrack_meta_pixel_id',
    'servertrack_meta_access_token',
    'servertrack_meta_test_event_code',
    'servertrack_google_enabled',
    'servertrack_google_customer_id',
    'servertrack_google_conversion_id',
    'servertrack_google_developer_token',
    'servertrack_google_client_id',
    'servertrack_google_client_secret',
    'servertrack_google_refresh_token',
    'servertrack_google_access_token',
    'servertrack_google_token_expires',
    'servertrack_tiktok_enabled',
    'servertrack_tiktok_pixel_id',
    'servertrack_tiktok_access_token',
    'servertrack_source_woo_enabled',
    'servertrack_source_cf7_enabled',
    'servertrack_source_edd_enabled',
    'servertrack_cf7_mappings',
    'servertrack_debug_log',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove order post meta — batched to avoid memory issues on large stores
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
        '_servertrack_event_id',
        '_servertrack_server_sent'
    )"
);
