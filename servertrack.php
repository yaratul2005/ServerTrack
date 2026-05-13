<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       servertrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * v6.0.4 — Three bootstrap bugs fixed.
 *
 * BUG-6 FIX — Dual WooCommerce class init (double-fire risk)
 * -----------------------------------------------------------
 * Both ServerTrack_WooCommerce (sources/class-servertrack-woocommerce.php)
 * and ServerTrack_Source_WooCommerce (sources/class-servertrack-source-woocommerce.php)
 * were being initialised unconditionally whenever WooCommerce was active.
 * Both classes hook into woocommerce_payment_complete / woocommerce_thankyou,
 * which caused every Purchase CAPI event to fire TWICE — once per class.
 * The in-request dedup guard catches the second fire within a single PHP
 * execution, but when Purchase is also scheduled via wp_cron as a fallback
 * (BUG-B fix, v5.0), the cron job runs in a separate process where the
 * in-memory dedup state is gone, so both classes fire independently.
 *
 * Fix: ServerTrack_WooCommerce is the primary, battle-tested implementation
 * (34 KB, v5.0 with all BUG-A/B/C/H fixes). ServerTrack_Source_WooCommerce
 * is the v3.3.1 extended implementation that introduced the already_sent()
 * poly-dispatch API used by Dedup v2.4. It is now gated behind a new option
 * 'servertrack_source_woo_extended' which defaults to 0 (disabled). Advanced
 * users who want the extended source can enable it explicitly. The two classes
 * must never run simultaneously.
 *
 * BUG-7 FIX — Dual cart-abandonment init (duplicate cron + events)
 * -----------------------------------------------------------------
 * ServerTrack_CartAbandonment (v2.x, 10 KB) and ServerTrack_WooAbandonment
 * (v3.x, 20 KB) were both initialised simultaneously when the abandonment
 * feature was enabled. This scheduled duplicate cron hooks
 * (servertrack_check_abandonment) and caused duplicate InitiateCheckout /
 * AbandonedCart CAPI events to fire.
 *
 * Fix: Only ServerTrack_WooAbandonment (the newer, richer v3.x implementation)
 * is initialised. ServerTrack_CartAbandonment is kept loaded (require_once) for
 * backward-compat so that any existing scheduled cron callbacks resolve without
 * a fatal, but its ::init() is no longer called.
 *
 * BUG-8 FIX — 'servertrack_source_woo_enabled' toggle silently ignored
 * ---------------------------------------------------------------------
 * The plugin registered the option 'servertrack_source_woo_enabled' (default 1)
 * and exposed a toggle in the admin UI, but servertrack_init() called
 * ServerTrack_WooCommerce::init() unconditionally — it never read the option.
 * Users who disabled WooCommerce tracking via Settings still had all WooCommerce
 * CAPI events firing.
 *
 * Fix: ServerTrack_WooCommerce::init() and ServerTrack_WooRenewals::init() are
 * now wrapped in an explicit get_option( 'servertrack_source_woo_enabled', 1 )
 * guard in this file.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * v6.0.3 — Bootstrap consolidation (history preserved)
 * ─────────────────────────────────────────────────────────────────────────────
 * The plugin had TWO competing bootstrap systems that were never merged:
 *
 *   1. The ORIGINAL flat system in this file (servertrack_init) loaded only
 *      ~15 classes and missed: ServerTrack_Frontend, ServerTrack_CustomEvents,
 *      ServerTrack_Retry (call), and all v3.x WooCommerce source classes.
 *
 *   2. The NEWER ServerTrack_Core::init() system in
 *      includes/class-servertrack-core.php was never require_once'd or called
 *      from this file, making it completely dead code.
 *
 * Result: frontend pixel never fired, custom events never ran, retry queue
 * was never processed, and half the WooCommerce source classes were silently
 * skipped.
 *
 * Fix: one authoritative servertrack_load_classes() + servertrack_init() here.
 * class-servertrack-core.php is kept as a backward-compat shim (no-op).
 */

define( 'SERVERTRACK_VERSION', '6.0.4' );
define( 'SERVERTRACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────────────────────
// Class loader — strict dependency order: dependency before dependent.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_load_classes(): void {

    // ── Core infrastructure ───────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent-v2.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-identity.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-clickcapture.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-matchquality.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-offline-conversion.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-pixel-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-ltv.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-catalog.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-webhook.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-custom-events.php';
    // Backward-compat shim — keeps ServerTrack_Core as a safe no-op class.
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-cli.php';
    }

    // ── Platform senders ─────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';

    // ── WooCommerce event sources ─────────────────────────────────────────────
    //
    // PRIMARY implementation (v5.0, all BUG-A/B/C/H fixes applied).
    // This is the authoritative WooCommerce CAPI class.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';

    // EXTENDED implementation (v3.3.1).
    // BUG-6 FIX: always loaded (require_once) so the class definition exists
    // for any stored cron callbacks, but ::init() is ONLY called when the
    // 'servertrack_source_woo_extended' option is explicitly enabled (default: 0).
    // The two classes must never run simultaneously — see init() below.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-source-woocommerce.php';

    // Subscription renewal/cancellation/pause events (v3.x).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';

    // Cart abandonment — v3.x primary implementation.
    // BUG-7 FIX: ServerTrack_CartAbandonment (v2.x) is loaded for backward-compat
    // (resolves any existing scheduled cron callbacks) but its ::init() is NOT called.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-abandonment.php';

    // Order lifecycle status events: on-hold, failed, cancelled (on by default).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-order-status.php';
    // AddToWishlist events — opt-in (requires YITH or TI Wishlist plugin).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-wishlist.php';
    // Partial refund events (on by default).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-partial-refund.php';
    // Subscriptions (WooCommerce Subscriptions plugin wrapper).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-subscriptions.php';

    // ── Optional third-party sources ─────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-dashboard.php';
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        require_once SERVERTRACK_DIR . 'frontend/class-servertrack-frontend.php';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Plugin init — runs at plugins_loaded priority 20.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_init(): void {
    servertrack_load_classes();
    servertrack_run_upgrade();

    // ── Core infrastructure ───────────────────────────────────────────────────
    ServerTrack_Identity::init();
    ServerTrack_ClickCapture::init();
    ServerTrack_OfflineConversion::init();
    ServerTrack_PixelDedup::init();
    ServerTrack_LTV::init();
    ServerTrack_Catalog::init();
    ServerTrack_Webhook::init();
    ServerTrack_Retry::init();
    ServerTrack_CustomEvents::init();

    // ── WooCommerce sources ───────────────────────────────────────────────────
    if ( class_exists( 'WooCommerce' ) ) {

        // BUG-8 FIX: Respect the 'servertrack_source_woo_enabled' admin toggle.
        // Previously this block ran unconditionally, ignoring the user's setting.
        if ( get_option( 'servertrack_source_woo_enabled', 1 ) ) {

            // BUG-6 FIX: Mutually exclusive WooCommerce source selection.
            // Only ONE of the two WooCommerce implementations must be active at
            // any given time. ServerTrack_WooCommerce (primary, v5.0) is the
            // default. ServerTrack_Source_WooCommerce (extended, v3.3.1) is only
            // activated when explicitly opted-in via the admin option.
            if ( get_option( 'servertrack_source_woo_extended', 0 ) ) {
                // Extended mode: use the richer v3.3.1 source.
                // WARNING: Do NOT enable both — double-fire will occur.
                ServerTrack_Source_WooCommerce::init();
            } else {
                // Default (recommended): primary v5.0 source with all fixes.
                ServerTrack_WooCommerce::init();
            }

            // BUG-8 FIX (continued): Renewals is also a WooCommerce sub-feature
            // and should be gated by the same woo_enabled parent toggle.
            if ( class_exists( 'WC_Subscriptions' ) ) {
                ServerTrack_WooRenewals::init();
                ServerTrack_Subscriptions::init();
            }

            // BUG-7 FIX: Only initialise ServerTrack_WooAbandonment (v3.x).
            // ServerTrack_CartAbandonment (v2.x) is intentionally NOT init'd here.
            // It is only loaded via require_once above so that any pre-existing
            // wp_cron callbacks resolve safely without a fatal error.
            if ( get_option( 'servertrack_source_abandonment_enabled', 0 ) ) {
                ServerTrack_WooAbandonment::init();
            }

            // Order lifecycle status events (on-hold, failed, cancelled) — on by default.
            if ( get_option( 'servertrack_source_order_status_enabled', 1 ) ) {
                ServerTrack_WooOrderStatus::init();
            }

            // AddToWishlist events — opt-in.
            if ( get_option( 'servertrack_source_wishlist_enabled', 0 ) ) {
                ServerTrack_WooWishlist::init();
            }

            // Partial refund events — on by default.
            if ( get_option( 'servertrack_source_partial_refund_enabled', 1 ) ) {
                ServerTrack_WooPartialRefund::init();
            }

        } // end servertrack_source_woo_enabled guard

    } // end WooCommerce active guard

    // ── Optional third-party sources ─────────────────────────────────────────
    if ( class_exists( 'WPCF7' ) && get_option( 'servertrack_source_cf7_enabled', 0 ) ) {
        ServerTrack_CF7::init();
    }
    if ( class_exists( 'Easy_Digital_Downloads' ) && get_option( 'servertrack_source_edd_enabled', 0 ) ) {
        ServerTrack_EDD::init();
    }

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        ServerTrack_Dashboard::init();
        ServerTrack_Admin::init();
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        ServerTrack_Frontend::init();
    }
}
add_action( 'plugins_loaded', 'servertrack_init', 20 );

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade guard — version-keyed so it only runs once per version bump.
// BUG-4 FIX (v6.0.3): previously used bare add_option() calls on every page load.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_run_upgrade(): void {
    $installed = get_option( 'servertrack_db_version', '0' );
    if ( version_compare( $installed, SERVERTRACK_VERSION, '>=' ) ) {
        return; // Nothing to do.
    }
    servertrack_register_defaults();
    update_option( 'servertrack_db_version', SERVERTRACK_VERSION );
}

function servertrack_register_defaults(): void {
    $defaults = [
        // Core toggles
        'servertrack_enabled'                        => 1,
        'servertrack_debug_mode'                     => 0,
        'servertrack_debug_log'                      => [],
        'servertrack_retry_queue'                    => [],
        // BUG-C (v6.0.2): consent_mode must default to 'none' so events are
        // never silently blocked on fresh installs.
        'servertrack_consent_mode'                   => 'none',
        // Platform toggles
        'servertrack_meta_enabled'                   => 0,
        'servertrack_tiktok_enabled'                 => 0,
        'servertrack_google_enabled'                 => 0,
        // Webhook
        'servertrack_webhook_enabled'                => 0,
        'servertrack_webhook_url'                    => '',
        'servertrack_webhook_secret'                 => '',
        'servertrack_webhook_events'                 => '',
        // Frontend tracking (v3.0)
        'servertrack_scroll_depth'                   => 1,
        'servertrack_video_tracking'                 => 1,
        'servertrack_wishlist_tracking'              => 1,
        'servertrack_google_gtag_id'                 => '',
        'servertrack_google_gtag_label'              => '',
        // WooCommerce source toggles (v3.2 / v3.3)
        'servertrack_source_woo_enabled'             => 1,
        // BUG-6 FIX (v6.0.4): extended WooCommerce source is opt-in only.
        // Defaults to 0 — primary v5.0 source is always used unless explicitly
        // switched. Setting this to 1 disables the primary source.
        'servertrack_source_woo_extended'            => 0,
        'servertrack_source_abandonment_enabled'     => 0,
        'servertrack_abandonment_window_minutes'     => 60,
        'servertrack_source_order_status_enabled'    => 1,
        'servertrack_source_wishlist_enabled'        => 0,
        'servertrack_source_partial_refund_enabled'  => 1,
        // Optional third-party source toggles
        'servertrack_source_cf7_enabled'             => 0,
        'servertrack_source_edd_enabled'             => 0,
    ];
    foreach ( $defaults as $key => $value ) {
        add_option( $key, $value );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Activation hook
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    servertrack_register_defaults();
    update_option( 'servertrack_db_version', SERVERTRACK_VERSION );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Deactivation hook — clear all scheduled cron jobs and sensitive credentials.
// ─────────────────────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function (): void {
    $cron_hooks = [
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
        'servertrack_process_retry',
    ];
    foreach ( $cron_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
    delete_option( 'servertrack_retry_queue' );
    $credentials = [
        'servertrack_meta_access_token',
        'servertrack_tiktok_access_token',
        'servertrack_google_refresh_token',
        'servertrack_google_access_token',
        'servertrack_webhook_secret',
    ];
    foreach ( $credentials as $opt ) {
        delete_option( $opt );
    }
} );
