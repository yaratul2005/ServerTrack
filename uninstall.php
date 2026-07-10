<?php
/**
 * Ratuls_ACT Uninstall  — v4.0
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes ALL stored options, order meta (classic + HPOS), retry/abandonment
 * transients, and all scheduled cron hooks from the database.
 *
 * Updated in v4.0:
 *   - Added v4.0 options: abandonment, gtag, scroll/video/wishlist tracking
 *   - Fixed cron hook list (old mismatched name removed, all new hooks added)
 *   - Added abandonment transient cleanup
 *   - Added full order meta key list (consent, click IDs, dedup flags)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── 1. Remove all plugin options ─────────────────────────────────────────────
$ratuls_act_options = [
    // General
    'ratuls_act_enabled',
    'ratuls_act_test_mode',
    'ratuls_act_consent_mode',
    'ratuls_act_debug_mode',
    'ratuls_act_retry_queue',
    'ratuls_act_db_version',
    'ratuls_act_log_max_entries',
    'ratuls_act_dedup_ttl_days',
    'ratuls_act_debug_log',
    // Meta CAPI
    'ratuls_act_meta_enabled',
    'ratuls_act_meta_pixel_id',
    'ratuls_act_meta_access_token',
    'ratuls_act_meta_test_event_code',
    // Google Ads
    'ratuls_act_google_enabled',
    'ratuls_act_google_customer_id',
    'ratuls_act_google_conversion_id',
    'ratuls_act_google_developer_token',
    'ratuls_act_google_client_id',
    'ratuls_act_google_client_secret',
    'ratuls_act_google_refresh_token',
    'ratuls_act_google_access_token',
    'ratuls_act_google_token_expires',
    'ratuls_act_google_gtag_id',
    'ratuls_act_google_gtag_label',
    // TikTok
    'ratuls_act_tiktok_enabled',
    'ratuls_act_tiktok_pixel_id',
    'ratuls_act_tiktok_access_token',
    // Sources
    'ratuls_act_source_woo_enabled',
    'ratuls_act_source_cf7_enabled',
    'ratuls_act_source_edd_enabled',
    'ratuls_act_source_abandonment_enabled',
    'ratuls_act_abandonment_window_minutes',
    'ratuls_act_cf7_mappings',
    // Browser tracking
    'ratuls_act_scroll_depth',
    'ratuls_act_video_tracking',
    'ratuls_act_wishlist_tracking',
    // Order Status, partial refunds, subscriptions
    'ratuls_act_source_order_status_enabled',
    'ratuls_act_source_wishlist_enabled',
    'ratuls_act_source_partial_refund_enabled',
    'ratuls_act_source_subscriptions_enabled',
    // Missing platforms
    'ratuls_act_snapchat_enabled',
    'ratuls_act_snapchat_pixel_id',
    'ratuls_act_snapchat_access_token',
    'ratuls_act_pinterest_enabled',
    'ratuls_act_pinterest_pixel_id',
    'ratuls_act_pinterest_access_token',
    'ratuls_act_linkedin_enabled',
    'ratuls_act_linkedin_pixel_id',
    'ratuls_act_linkedin_access_token',
];
foreach ( $ratuls_act_options as $opt ) {
    delete_option( $opt );
}

// ── 2. Remove classic post meta ───────────────────────────────────────────────
$meta_keys = [
    '_ratuls_act_event_id',
    '_ratuls_act_server_sent',
    '_ratuls_act_refunded',
    '_ratuls_act_consent',
    '_ratuls_act_fbc',
    '_ratuls_act_fbp',
    '_ratuls_act_fbclid',
    '_ratuls_act_ttclid',
    '_ratuls_act_gclid',
    '_ratuls_act_api_sent',
    '_ratuls_act_renewal_sent',
    '_ratuls_act_consent_v2',
];
foreach ( $meta_keys as $meta_key ) {
    delete_post_meta_by_key( $meta_key );
}

// ── 3. Remove HPOS order meta ─────────────────────────────────────────────────
if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $hpos_table = $wpdb->prefix . 'wc_orders_meta';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
        if ( ! empty( $meta_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos_table} WHERE meta_key IN ($placeholders)", ...$meta_keys ) );
        }
    }
}

// ── 4. Clear retry transients ─────────────────────────────────────────────────
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_ratuls-act\_retry\_%'
        OR option_name LIKE '\_transient\_timeout\_ratuls-act\_retry\_%'"
);

// ── 5. Clear cart abandonment transients / options ────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_ratuls-act\_abandon\_%'
        OR option_name LIKE '\_transient\_timeout\_ratuls-act\_abandon\_%'
        OR option_name LIKE 'ratuls-act\_abandon\_%'"
);

// ── 6. Cancel all Ratuls_ACT cron hooks ──────────────────────────────────────
$cron_hooks = [
    'ratuls_act_process_retry_queue',
    'ratuls_act_check_abandonment',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
    wp_unschedule_hook( $hook );
}

// ── 7. Drop Custom Dedup Table ──────────────────────────────────────────────────
global $wpdb;
$table_name = $wpdb->prefix . 'ratuls_act_dedup';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

