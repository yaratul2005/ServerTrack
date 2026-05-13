<?php
/**
 * ServerTrack Uninstall  — v6.0.5
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes ALL stored options, order meta (classic + HPOS), retry/abandonment
 * transients, and all scheduled cron hooks from the database.
 *
 * Updated in v6.0.5 (BUG-9, BUG-10, BUG-13, BUG-14):
 *   BUG-9  — Added all options introduced in v5.0–v6.0.4 that were missing:
 *             servertrack_source_woo_extended,
 *             servertrack_source_order_status_enabled,
 *             servertrack_source_wishlist_enabled,
 *             servertrack_source_partial_refund_enabled,
 *             servertrack_debug_mode,
 *             servertrack_webhook_enabled/url/secret/events,
 *             servertrack_retry_queue, servertrack_db_version
 *   BUG-10 — Fixed cron hook typo: _abandonments (plural) → _abandonment (singular)
 *   BUG-13 — Fixed renewal cron hook: _send_renewal_purchase → _send_sub_renewal
 *   BUG-14 — Added 3 missing cron hooks that were only cleared on deactivation:
 *             servertrack_deliver_webhook, servertrack_process_retry_queue,
 *             servertrack_send_offline_conversion
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
$servertrack_options = [
    // General
    'servertrack_enabled',
    'servertrack_test_mode',
    'servertrack_consent_mode',
    'servertrack_debug_mode',           // BUG-9: added (v5.0+)
    'servertrack_db_version',           // BUG-9: added (v5.0+)
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
    'servertrack_source_woo_extended',          // BUG-9: added (v6.0.4+)
    'servertrack_source_order_status_enabled',  // BUG-9: added (v5.0+)
    'servertrack_source_wishlist_enabled',      // BUG-9: added (v5.0+)
    'servertrack_source_partial_refund_enabled',// BUG-9: added (v5.0+)
    'servertrack_source_cf7_enabled',
    'servertrack_source_edd_enabled',
    'servertrack_source_abandonment_enabled',
    'servertrack_source_cart_abandonment_enabled', // BUG-9: old key — clean both
    'servertrack_source_subscriptions_enabled',
    'servertrack_abandonment_window_minutes',
    'servertrack_cf7_mappings',
    // Webhook (BUG-9: added — v5.0+)
    'servertrack_webhook_enabled',
    'servertrack_webhook_url',
    'servertrack_webhook_secret',
    'servertrack_webhook_events',
    // Retry queue (BUG-9: added — v5.0+)
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
    // Purchase / async sends
    'servertrack_send_woo_purchase',
    'servertrack_send_woo_view_content',
    'servertrack_send_woo_refund',
    'servertrack_send_edd_purchase',
    'servertrack_send_sub_renewal',             // BUG-13: was _send_renewal_purchase (wrong)
    'servertrack_send_subscription_cancellation',
    // Retry processor
    'servertrack_process_retry',
    'servertrack_process_retry_queue',          // BUG-14: added — present in deactivation hook
    // Cart abandonment — BUG-10: was _abandonments (plural) — now singular (correct)
    'servertrack_check_abandonment',
    // Webhook delivery — BUG-14: added
    'servertrack_deliver_webhook',
    // Offline conversions — BUG-14: added
    'servertrack_send_offline_conversion',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
    wp_unschedule_hook( $hook );
}
