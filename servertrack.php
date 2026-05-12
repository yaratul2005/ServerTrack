<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       servertrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// v6.0.2 — Full event pipeline fix (BUG-A logging, BUG-B cron bypass, BUG-C consent).
define( 'SERVERTRACK_VERSION', '6.0.2' );
define( 'SERVERTRACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Load all plugin classes.
 * Order matters: dependencies before dependents.
 */
function servertrack_load_classes(): void {

    // ── Core infrastructure ───────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-identity.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-clickcapture.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-matchquality.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent-v2.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-offline-conversion.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-pixel-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-ltv.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-catalog.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-webhook.php';

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-cli.php';
    }

    // ── Platform senders ─────────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';

    // ── Event sources ───────────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-subscriptions.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';

    // ── Admin ─────────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-dashboard.php';
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
    }
}

function servertrack_init(): void {
    servertrack_load_classes();
    servertrack_maybe_upgrade();

    ServerTrack_Identity::init();
    ServerTrack_ClickCapture::init();
    ServerTrack_OfflineConversion::init();
    ServerTrack_PixelDedup::init();
    ServerTrack_LTV::init();
    ServerTrack_Catalog::init();
    ServerTrack_Webhook::init();
    ServerTrack_WooCommerce::init();
    ServerTrack_Subscriptions::init();
    ServerTrack_CartAbandonment::init();

    if ( is_admin() ) {
        ServerTrack_Dashboard::init();
        ServerTrack_Admin::init();
    }
}
add_action( 'plugins_loaded', 'servertrack_init', 20 );

function servertrack_maybe_upgrade(): void {
    add_option( 'servertrack_enabled',           1 );
    add_option( 'servertrack_debug_mode',         0 );
    add_option( 'servertrack_debug_log',          [] );
    add_option( 'servertrack_retry_queue',        [] );
    add_option( 'servertrack_meta_enabled',       0 );
    add_option( 'servertrack_tiktok_enabled',     0 );
    add_option( 'servertrack_google_enabled',     0 );
    add_option( 'servertrack_webhook_enabled',    0 );
    add_option( 'servertrack_webhook_url',        '' );
    add_option( 'servertrack_webhook_secret',     '' );
    add_option( 'servertrack_webhook_events',     '' );
    // BUG-C FIX: ensure consent_mode defaults to 'none' so events are never
    // silently blocked on fresh installs where the option was never saved.
    add_option( 'servertrack_consent_mode',       'none' );
}

register_activation_hook( __FILE__, function (): void {
    add_option( 'servertrack_enabled',           1 );
    add_option( 'servertrack_debug_mode',         0 );
    add_option( 'servertrack_debug_log',          [] );
    add_option( 'servertrack_retry_queue',        [] );
    add_option( 'servertrack_meta_enabled',       0 );
    add_option( 'servertrack_tiktok_enabled',     0 );
    add_option( 'servertrack_google_enabled',     0 );
    add_option( 'servertrack_webhook_enabled',    0 );
    add_option( 'servertrack_webhook_url',        '' );
    add_option( 'servertrack_webhook_secret',     '' );
    add_option( 'servertrack_webhook_events',     '' );
    add_option( 'servertrack_consent_mode',       'none' );
} );

register_deactivation_hook( __FILE__, function (): void {
    $hooks = [
        'servertrack_send_woo_purchase',
        'servertrack_send_woo_refund',
        'servertrack_send_woo_view_content',
        'servertrack_send_sub_renewal',
        'servertrack_send_sub_cancelled',
        'servertrack_send_sub_paused',
        'servertrack_check_abandonment',
        'servertrack_send_offline_conversion',
        'servertrack_deliver_webhook',
        'servertrack_process_retry_queue',
    ];
    foreach ( $hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
    delete_option( 'servertrack_retry_queue' );
    $credential_options = [
        'servertrack_meta_access_token',
        'servertrack_tiktok_access_token',
        'servertrack_google_refresh_token',
        'servertrack_google_access_token',
        'servertrack_webhook_secret',
    ];
    foreach ( $credential_options as $opt ) {
        delete_option( $opt );
    }
} );
