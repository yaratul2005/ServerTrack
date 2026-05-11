<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       servertrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SERVERTRACK_VERSION', '6.0.0' );
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

    // v2.0 — Logger (EMQ score storage, event_type field)
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';

    // v5.0 — Identity stitching
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-identity.php';

    // v5.0 — Server-side click ID capture REST endpoint
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-clickcapture.php';

    // v5.0 — Real-time EMQ scorer
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-matchquality.php';

    // v5.0 — Google Consent Mode v2
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent-v2.php';

    // v6.0 — NEW: Offline conversion sync (CRM fulfilment signal)
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-offline-conversion.php';

    // v6.0 — NEW: Browser pixel event_id injection (dedup loop)
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-pixel-dedup.php';

    // v6.0 — NEW: Customer LTV signal in Purchase payload
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-ltv.php';

    // v6.0 — NEW: Product catalog signal enrichment
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-catalog.php';

    // v6.0 — NEW: Webhook outbound
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-webhook.php';

    // v6.0 — NEW: WP-CLI commands (only loaded when WP-CLI is active)
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-cli.php';
    }

    // ── Platform senders ─────────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';

    // ── Event sources ───────────────────────────────────────────────────────────
    // v3.0 — WooCommerce (identity + click ID wired in)
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';

    // v5.0 — Subscription lifecycle events
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-subscriptions.php';

    // v5.0 — Server-side cart abandonment detection
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';

    // ── Admin ─────────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-dashboard.php';

        if ( file_exists( SERVERTRACK_DIR . 'admin/class-servertrack-settings.php' ) ) {
            require_once SERVERTRACK_DIR . 'admin/class-servertrack-settings.php';
        }
    }
}

/**
 * Initialise all plugin components.
 * Called on plugins_loaded to ensure WooCommerce is available.
 */
function servertrack_init(): void {
    servertrack_load_classes();

    // Core infrastructure
    ServerTrack_Identity::init();
    ServerTrack_ClickCapture::init();

    // v6.0 — NEW features
    ServerTrack_OfflineConversion::init();
    ServerTrack_PixelDedup::init();
    ServerTrack_LTV::init();
    ServerTrack_Catalog::init();
    ServerTrack_Webhook::init();

    // Event sources
    ServerTrack_WooCommerce::init();
    ServerTrack_Subscriptions::init();
    ServerTrack_CartAbandonment::init();

    // Admin
    if ( is_admin() ) {
        ServerTrack_Dashboard::init();
        if ( class_exists( 'ServerTrack_Settings' ) ) {
            ServerTrack_Settings::init();
        }
    }
}
add_action( 'plugins_loaded', 'servertrack_init', 5 );

/**
 * Plugin activation: set default options.
 */
register_activation_hook( __FILE__, function (): void {
    add_option( 'servertrack_enabled',         1 );
    add_option( 'servertrack_debug_mode',       0 );
    add_option( 'servertrack_debug_log',        [] );
    add_option( 'servertrack_retry_queue',      [] );
    add_option( 'servertrack_webhook_enabled',  0 );
    add_option( 'servertrack_webhook_url',      '' );
    add_option( 'servertrack_webhook_secret',   '' );
    add_option( 'servertrack_webhook_events',   '' );
} );

/**
 * Plugin deactivation: clear all scheduled cron events and transient state.
 *
 * FIX-09: Added delete_option( 'servertrack_retry_queue' ).
 *   Previously only cron hooks were cleared. The retry queue persisted in
 *   wp_options across deactivation/re-activation cycles, causing stale events
 *   to fire immediately on re-activation — potentially double-sending conversions
 *   that had already been delivered before deactivation.
 */
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

    // FIX-09: Purge the retry queue so stale events don't re-fire on re-activation.
    delete_option( 'servertrack_retry_queue' );
} );
