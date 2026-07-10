<?php
/**
 * Plugin Name:       Ratuls- Ads Conversion Tracker
 * Plugin URI:        https://github.com/yaratul2005/Ratuls_ACT
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       ratuls-act
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * v6.0.4 — Bug fixes.
 *
 * Fixes in this release
 * ----------------------
 *   BUG-FIX-1: Ratuls_ACT_Hasher::event_id() was missing — fatal error on
 *              every WooCommerce CAPI event from the v3.x source layer.
 *   BUG-FIX-2: Ratuls_ACT_Source_WooCommerce::init() was never called —
 *              entire v3.x extended WooCommerce source was dead code.
 *   BUG-FIX-3: Cart abandonment option key mismatch fixed.
 *              (ratuls_act_source_abandonment_enabled vs
 *               ratuls_act_source_cart_abandonment_enabled)
 *   BUG-FIX-4: InitiateCheckout event_id used time() — broke deduplication.
 *   BUG-FIX-5: ensure_uid() race condition — replaced random UUID with
 *              deterministic hash_hmac so concurrent requests always produce
 *              the same external_id for new users.
 *
 * v6.0.3 — Bootstrap consolidation.
 *
 * History of the problem v6.0.3 fixed
 * ------------------------------------------
 * The plugin had TWO competing bootstrap systems that were never merged:
 *
 *   1. The ORIGINAL flat system in this file (ratuls_act_init) loaded only
 *      ~15 classes and missed: Ratuls_ACT_Frontend, Ratuls_ACT_CustomEvents,
 *      Ratuls_ACT_Retry (call), and all v3.x WooCommerce source classes.
 *
 *   2. The NEWER Ratuls_ACT_Core::init() system in
 *      includes/class-ratuls-act-core.php was never require_once'd or called
 *      from this file, making it completely dead code.
 *
 * Result: frontend pixel never fired, custom events never ran, retry queue
 * was never processed, and half the WooCommerce source classes were silently
 * skipped.
 *
 * Fix: one authoritative ratuls_act_load_classes() + ratuls_act_init() here.
 * class-ratuls-act-core.php is kept as a backward-compat shim (no-op).
 */

define( 'RATULS_ACT_VERSION', '6.0.4' );
define( 'RATULS_ACT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RATULS_ACT_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────────────────────
// Class loader — strict dependency order: dependency before dependent.
// ─────────────────────────────────────────────────────────────────────────────
function ratuls_act_load_classes(): void {

    // ── Core infrastructure ───────────────────────────────────────────────────
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-hasher.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-event.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-dedup.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-dedup-engine.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-enrichment.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-health.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-stream.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-attribution.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-license.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-consent.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-cookiehelper.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-proxy.php';

    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-retry.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-logger.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-identity.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-clickcapture.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-matchquality.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-offline-conversion.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-pixel-dedup.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-ltv.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-catalog.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-webhook.php';
    // BUG-2 FIX: custom-events was present but never loaded.
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-custom-events.php';
    // Backward-compat shim — keeps Ratuls_ACT_Core as a safe no-op class.
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-dispatcher.php';
    require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-core.php';

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once RATULS_ACT_DIR . 'includes/class-ratuls-act-cli.php';
    }

    // ── Platform senders ─────────────────────────────────────────────────────
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-meta.php';
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-tiktok.php';
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-google.php';
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-snapchat.php';
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-pinterest.php';
    require_once RATULS_ACT_DIR . 'platforms/class-ratuls-act-linkedin.php';

    // ── WooCommerce event sources ─────────────────────────────────────────────
    // Core WooCommerce purchase/refund/view events.
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woocommerce.php';
    // Extended WooCommerce source (v3.x — wishlist, partial refund, order status).
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-source-woocommerce.php';
    // Subscription renewal/cancellation events.
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woo-renewals.php';
    // Cart abandonment — opt-in, guarded by option check in init().
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-cart-abandonment.php';
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woo-abandonment.php';
    // Order lifecycle status events: on-hold, failed, cancelled.
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woo-order-status.php';
    // AddToWishlist events — opt-in.
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woo-wishlist.php';
    // Partial refund events.
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-woo-partial-refund.php';
    // Subscriptions (WooCommerce Subscriptions plugin wrapper).
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-subscriptions.php';

    // ── Optional third-party sources ─────────────────────────────────────────
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-cf7.php';
    require_once RATULS_ACT_DIR . 'sources/class-ratuls-act-edd.php';

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once RATULS_ACT_DIR . 'admin/class-ratuls-act-dashboard.php';
        require_once RATULS_ACT_DIR . 'admin/class-ratuls-act-admin.php';
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        require_once RATULS_ACT_DIR . 'frontend/class-ratuls-act-frontend.php';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Plugin init — runs at plugins_loaded priority 20.
// ─────────────────────────────────────────────────────────────────────────────
function ratuls_act_init(): void {
    ratuls_act_load_classes();
    ratuls_act_run_upgrade();

    // ── Core infrastructure ───────────────────────────────────────────────────
    Ratuls_ACT_Dispatcher::init();
    Ratuls_ACT_CookieHelper::init();
    Ratuls_ACT_Proxy::init();
    Ratuls_ACT_Identity::init();
    Ratuls_ACT_ClickCapture::init();
    Ratuls_ACT_OfflineConversion::init();
    Ratuls_ACT_PixelDedup::init();
    Ratuls_ACT_DedupEngine::init();
    Ratuls_ACT_Enrichment::init();
    Ratuls_ACT_Health::init();
    Ratuls_ACT_Stream::init();
    Ratuls_ACT_Attribution::init();
    Ratuls_ACT_License::init();
    Ratuls_ACT_LTV::init();
    Ratuls_ACT_Catalog::init();
    Ratuls_ACT_Webhook::init();
    // BUG-3 FIX: Retry::init() was never called → queue never processed.
    Ratuls_ACT_Retry::init();
    // BUG-2 FIX: CustomEvents::init() was never called.
    Ratuls_ACT_CustomEvents::init();

    // ── WooCommerce sources ───────────────────────────────────────────────────
    if ( class_exists( 'WooCommerce' ) ) {
        Ratuls_ACT_WooCommerce::init();
        // BUG-FIX-2: Source_WooCommerce::init() was never called — the entire
        // v3.x extended WooCommerce source (wishlist, partial refund, order
        // status events, enhanced purchase dedup) was silently dead code.
        Ratuls_ACT_Source_WooCommerce::init();

        // Renewals (WooCommerce Subscriptions plugin).
        if ( class_exists( 'WC_Subscriptions' ) ) {
            Ratuls_ACT_WooRenewals::init();
            Ratuls_ACT_Subscriptions::init();
        }

        // BUG-FIX-3: was checking 'ratuls_act_source_abandonment_enabled'
        // but admin saves to 'ratuls_act_source_cart_abandonment_enabled'.
        if ( get_option( 'ratuls_act_source_cart_abandonment_enabled', 0 ) ) {
            Ratuls_ACT_CartAbandonment::init();
            Ratuls_ACT_WooAbandonment::init();
        }

        // Order lifecycle status events (on-hold, failed, cancelled).
        if ( get_option( 'ratuls_act_source_order_status_enabled', 1 ) ) {
            Ratuls_ACT_WooOrderStatus::init();
        }

        // AddToWishlist events — opt-in.
        if ( get_option( 'ratuls_act_source_wishlist_enabled', 0 ) ) {
            Ratuls_ACT_WooWishlist::init();
        }

        // Partial refund events.
        if ( get_option( 'ratuls_act_source_partial_refund_enabled', 1 ) ) {
            Ratuls_ACT_WooPartialRefund::init();
        }
    }

    // ── Optional third-party sources ─────────────────────────────────────────
    if ( class_exists( 'WPCF7' ) && get_option( 'ratuls_act_source_cf7_enabled', 0 ) ) {
        Ratuls_ACT_CF7::init();
    }
    if ( class_exists( 'Easy_Digital_Downloads' ) && get_option( 'ratuls_act_source_edd_enabled', 0 ) ) {
        Ratuls_ACT_EDD::init();
    }

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        Ratuls_ACT_Dashboard::init();
        Ratuls_ACT_Admin::init();
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        Ratuls_ACT_Frontend::init();
    }
}
add_action( 'plugins_loaded', 'ratuls_act_init', 20 );

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade guard — version-keyed so it only runs once per version bump.
// ─────────────────────────────────────────────────────────────────────────────
function ratuls_act_run_upgrade(): void {
    $installed = get_option( 'ratuls_act_db_version', '0' );
    if ( version_compare( $installed, RATULS_ACT_VERSION, '>=' ) ) {
        return; // Nothing to do.
    }
    ratuls_act_create_tables();
    ratuls_act_register_defaults();
    update_option( 'ratuls_act_db_version', RATULS_ACT_VERSION );
}

function ratuls_act_create_tables(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ratuls_act_dedup';

    $sql = "CREATE TABLE {$table_name} (
        dedup_key varchar(64) NOT NULL,
        created_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (dedup_key),
        KEY idx_expires_at (expires_at)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

function ratuls_act_register_defaults(): void {
    $defaults = [
        // Core toggles
        'ratuls_act_enabled'                        => 1,
        'ratuls_act_debug_mode'                     => 0,
        'ratuls_act_debug_log'                      => [],
        'ratuls_act_retry_queue'                    => [],
        // Consent
        'ratuls_act_consent_mode'                   => 'none',
        // Platform toggles
        'ratuls_act_meta_enabled'                   => 0,
        'ratuls_act_meta_pixel_id'                  => '',
        'ratuls_act_meta_access_token'              => '',
        'ratuls_act_meta_test_event_code'           => '',
        'ratuls_act_google_enabled'                 => 0,
        'ratuls_act_google_conversion_id'           => '',
        'ratuls_act_google_conversion_label'        => '',
        'ratuls_act_google_refresh_token'           => '',
        'ratuls_act_google_client_id'               => '',
        'ratuls_act_google_client_secret'           => '',
        'ratuls_act_tiktok_enabled'                 => 0,
        'ratuls_act_tiktok_pixel_id'                => '',
        'ratuls_act_tiktok_access_token'            => '',
        // Source toggles
        'ratuls_act_source_woo_enabled'                      => 1,
        'ratuls_act_source_cart_abandonment_enabled'         => 0,
        'ratuls_act_abandonment_window_minutes'              => 60,
        'ratuls_act_source_order_status_enabled'             => 1,
        'ratuls_act_source_wishlist_enabled'                 => 0,
        'ratuls_act_source_partial_refund_enabled'           => 1,
        'ratuls_act_source_cf7_enabled'                      => 0,
        'ratuls_act_source_edd_enabled'                      => 0,
        'ratuls_act_source_subscriptions_enabled'            => 0,
    ];

    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value, '', 'no' );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// WP-Cron schedules
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    if ( ! isset( $schedules['every_five_minutes'] ) ) {
        $schedules['every_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'ratuls-act' ),
        ];
    }
    return $schedules;
} );

// ─────────────────────────────────────────────────────────────────────────────
// Activation / deactivation
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'ratuls_act_cleanup_dedup', function() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}ratuls_act_dedup WHERE expires_at < NOW()" );
});

register_activation_hook( __FILE__, function (): void {
    ratuls_act_load_classes();
    ratuls_act_create_tables();
    ratuls_act_register_defaults();

    if ( ! wp_next_scheduled( 'ratuls_act_cleanup_dedup' ) ) {
        wp_schedule_event( time(), 'daily', 'ratuls_act_cleanup_dedup' );
    }

    if ( ! wp_next_scheduled( 'ratuls_act_process_retry_queue' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'ratuls_act_process_retry_queue' );
    }
    if ( get_option( 'ratuls_act_source_cart_abandonment_enabled', 0 ) ) {
        if ( ! wp_next_scheduled( 'ratuls_act_check_abandonment' ) ) {
            wp_schedule_event( time(), 'every_five_minutes', 'ratuls_act_check_abandonment' );
        }
    }
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'ratuls_act_process_retry_queue' );
    wp_clear_scheduled_hook( 'ratuls_act_check_abandonment' );
} );

