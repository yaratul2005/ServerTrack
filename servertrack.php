<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, cart abandonment, subscriptions, and admin dashboard.
 * Version:           5.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       servertrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SERVERTRACK_VERSION', '5.0.0' );
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

    // v2.0 — Logger upgrade (EMQ score storage, event_type field)
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';

    // v5.0 — NEW: Identity stitching
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-identity.php';

    // v5.0 — NEW: Server-side click ID capture REST endpoint
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-clickcapture.php';

    // v5.0 — NEW: Real-time EMQ scorer
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-matchquality.php';

    // v5.0 — NEW: Google Consent Mode v2
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent-v2.php';

    // ── Platform senders ─────────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';

    // ── Event sources ───────────────────────────────────────────────────────────
    // v3.0 — WooCommerce (identity + click ID wired in)
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';

    // v5.0 — NEW: Subscription lifecycle events
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-subscriptions.php';

    // v5.0 — NEW: Server-side cart abandonment detection
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';

    // ── Admin ─────────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        // v5.0 — NEW: Admin dashboard (event log, EMQ chart, platform health)
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
    add_option( 'servertrack_enabled',     1 );
    add_option( 'servertrack_debug_mode',  0 );
    add_option( 'servertrack_debug_log',   [] );
    add_option( 'servertrack_retry_queue', [] );
} );

/**
 * Plugin deactivation: clear scheduled cron events.
 */
register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'servertrack_send_woo_purchase' );
    wp_clear_scheduled_hook( 'servertrack_send_woo_refund' );
    wp_clear_scheduled_hook( 'servertrack_send_woo_view_content' );
    wp_clear_scheduled_hook( 'servertrack_send_sub_renewal' );
    wp_clear_scheduled_hook( 'servertrack_send_sub_cancelled' );
    wp_clear_scheduled_hook( 'servertrack_send_sub_paused' );
    wp_clear_scheduled_hook( 'servertrack_check_abandonment' );
} );
