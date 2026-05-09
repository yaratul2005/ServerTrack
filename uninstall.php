<?php
/**
 * ServerTrack Uninstall
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes all stored options, post meta (classic meta + HPOS order meta),
 * retry transients, and scheduled cron hooks from the database.
 *
 * Day 7 additions:
 *   - Clears retry queue transients (servertrack_retry_*)
 *   - Clears HPOS order meta via wc_get_orders() when WooCommerce is active
 *   - Clears renewal event_id + dedup meta keys added in Day 5
 *   - Cancels all four cron hooks including the Day 5 renewal hook
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── 1. Remove all plugin options ──────────────────────────────────────────────
$servertrack_options = [
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
foreach ( $servertrack_options as $servertrack_option ) {
    delete_option( $servertrack_option );
}

// ── 2. Remove classic post meta (WooCommerce non-HPOS + EDD) ─────────────────
$meta_keys = [
    '_servertrack_event_id',
    '_servertrack_server_sent',
    '_servertrack_refunded',
];
foreach ( $meta_keys as $meta_key ) {
    delete_post_meta_by_key( $meta_key );
}

// ── 3. Remove HPOS order meta (WooCommerce 8.2+ with HPOS enabled) ────────────
if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
    global $wpdb;

    // Direct table query — wc_get_orders() would load every order into memory
    // which is unsafe on large stores. wpdb is the correct approach here.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $hpos_table = $wpdb->prefix . 'wc_orders_meta';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
        foreach ( $meta_keys as $meta_key ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( $hpos_table, [ 'meta_key' => $meta_key ], [ '%s' ] );
        }
    }
}

// ── 4. Clear retry transients ─────────────────────────────────────────────────
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_servertrack\_retry\_%'
        OR option_name LIKE '\_transient\_timeout\_servertrack\_retry\_%'"
);

// ── 5. Cancel all ServerTrack cron hooks ─────────────────────────────────────
$cron_hooks = [
    'servertrack_send_woo_purchase',
    'servertrack_send_edd_purchase',
    'servertrack_send_renewal_purchase',
    'servertrack_retry_failed_events',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
    wp_unschedule_hook( $hook );
}
