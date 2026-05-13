<?php
/**
 * ServerTrack Uninstall  — v6.1.0
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes ALL stored options, order meta (classic + HPOS), retry/abandonment
 * transients, and all scheduled cron hooks from the database.
 *
 * Updated in v6.1.0 (BUG-NEW-2, BUG-NEW-5):
 *   BUG-NEW-2 / BUG-NEW-5 — Added servertrack_google_refresh_token_enc to the
 *             options delete list. FIX-DR-4 (v6.1.0) stores the Google refresh
 *             token AES-256-CBC encrypted under this key. The previous uninstall
 *             routine only deleted the legacy plaintext key, leaving the
 *             encrypted credential in wp_options after uninstall. Also confirmed
 *             servertrack_db_version is already present (BUG-9), ensuring a
 *             future reinstall correctly reseeds all defaults.
 *
 * Updated in v6.0.5 (BUG-9, BUG-10, BUG-13, BUG-14):
 *   BUG-9  — Added all options introduced in v5.0–v6.0.4 that were missing.
 *   BUG-10 — Fixed cron hook typo: _abandonments (plural) → _abandonment (singular)
 *   BUG-13 — Fixed renewal cron hook: _send_renewal_purchase → _send_sub_renewal
 *   BUG-14 — Added 3 missing cron hooks that were only cleared on deactivation.
 *
 * Updated in v4.0:
 *   - Added v4.0 options: abandonment, gtag, scroll/video/wishlist tracking
 *   - Fixed cron hook list
 *   - Added abandonment transient cleanup
 *   - Added full order meta key list
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── 1. Remove all plugin options ─────────────────────────────────────────────
$servertrack_options = [
    // General
    'servertrack_enabled',
    'servertrack_test_mode',
    'servertrack_consent_mode',
    'servertrack_debug_mode',
    'servertrack_db_version',
    // Meta CAPI
    'servertrack_meta_enabled',
    'servertrack_meta_pixel_id',
    'servertrack_meta_access_token',
    'servertrack_meta_test_event_code',
    // Google Ads
    'servertrack_google_enabled',
    'servertrack_google_customer_id',
    'servertrack_google_conversion_id',
    'servertrack_google_conversion_label',
    'servertrack_google_developer_token',
    'servertrack_google_client_id',
    'servertrack_google_client_secret',
    'servertrack_google_refresh_token',
    'servertrack_google_refresh_token_enc',  // BUG-NEW-5 FIX: encrypted token key (FIX-DR-4)
    'servertrack_google_access_token',
    'servertrack_google_token_expires',
    'servertrack_google_gtag_id',
    'servertrack_google_gtag_label',
    // TikTok
    'servertrack_tiktok_enabled',
    'servertrack_tiktok_pixel_id',
    'servertrack_tiktok_access_token',
    // Sources
    'servertrack_source_woo_enabled',
    'servertrack_source_woo_extended',
    'servertrack_source_order_status_enabled',
    'servertrack_source_wishlist_enabled',
    'servertrack_source_partial_refund_enabled',
    'servertrack_source_cf7_enabled',
    'servertrack_source_edd_enabled',
    'servertrack_source_abandonment_enabled',
    'servertrack_source_cart_abandonment_enabled',
    'servertrack_source_subscriptions_enabled',
    'servertrack_abandonment_window_minutes',
    'servertrack_cf7_mappings',
    // Webhook
    'servertrack_webhook_enabled',
    'servertrack_webhook_url',
    'servertrack_webhook_secret',
    'servertrack_webhook_events',
    // Retry queue
    'servertrack_retry_queue',
    // Browser tracking
    'servertrack_scroll_depth',
    'servertrack_video_tracking',
    'servertrack_wishlist_tracking',
    // Misc
    'servertrack_debug_log',
    'servertrack_dedup_ttl_days',
    'servertrack_log_max_entries',
];
foreach ( $servertrack_options as $opt ) {
    delete_option( $opt );
}

// ── 2. Remove classic post meta ───────────────────────────────────────────────
$meta_keys = [
    '_servertrack_event_id',
    '_servertrack_server_sent',
    '_servertrack_refunded',
    '_servertrack_consent',
    '_servertrack_fbc',
    '_servertrack_fbp',
    '_servertrack_fbclid',
    '_servertrack_ttclid',
    '_servertrack_gclid',
    '_servertrack_api_sent',
    '_servertrack_renewal_sent',
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

// ── 5. Clear cart abandonment transients / options ────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_servertrack\_abandon\_%'
        OR option_name LIKE '\_transient\_timeout\_servertrack\_abandon\_%'
        OR option_name LIKE 'servertrack\_abandon\_%'"
);

// ── 6. Cancel all ServerTrack cron hooks ──────────────────────────────────────
$cron_hooks = [
    'servertrack_send_woo_purchase',
    'servertrack_send_woo_view_content',
    'servertrack_send_woo_refund',
    'servertrack_send_edd_purchase',
    'servertrack_send_sub_renewal',
    'servertrack_send_subscription_cancellation',
    'servertrack_process_retry',
    'servertrack_process_retry_queue',
    'servertrack_check_abandonment',
    'servertrack_deliver_webhook',
    'servertrack_send_offline_conversion',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
    wp_unschedule_hook( $hook );
}
