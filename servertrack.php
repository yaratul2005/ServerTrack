<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.1.0
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
 * v6.1.0 — Five bugs fixed (BUG-NEW-1 through BUG-NEW-5).
 *
 * BUG-NEW-1 FIX — servertrack_register_defaults() called on every version bump
 * -----------------------------------------------------------------------------
 * Previously, servertrack_run_upgrade() called servertrack_register_defaults()
 * on every version bump. add_option() is safe (won't overwrite existing values),
 * but any option introduced in a new version would silently remain unset on
 * existing installs if the activation hook had already stamped the db_version.
 * Fix: introduced a per-version migration function (servertrack_migrate_to_610)
 * that only adds keys new in v6.1.0. Fresh installs still seed all defaults
 * via the activation hook.
 *
 * BUG-NEW-2 FIX — Deactivation hook missing servertrack_google_refresh_token_enc
 * --------------------------------------------------------------------------------
 * FIX-DR-4 (v6.1.0, class-servertrack-admin.php) stores the Google refresh
 * token AES-256-CBC encrypted under the key servertrack_google_refresh_token_enc.
 * The deactivation hook only deleted the old plaintext key, leaving the
 * encrypted credential in wp_options permanently after deactivation.
 * Fix: added servertrack_google_refresh_token_enc to the $credentials list.
 *
 * BUG-NEW-3 FIX — SERVERTRACK_VERSION constant was still 6.0.4
 * -------------------------------------------------------------
 * The admin class (class-servertrack-admin.php) was already at v6.1.0 after
 * the FIX-DR-1..4 commit, but the main plugin file still declared 6.0.4.
 * This caused version_compare in servertrack_run_upgrade() to return '>=' on
 * all existing installs and exit without running the new migration.
 * Fix: bumped SERVERTRACK_VERSION to '6.1.0'.
 *
 * BUG-NEW-4 FIX — Master kill-switch servertrack_enabled was never checked
 * --------------------------------------------------------------------------
 * get_option('servertrack_enabled', 1) is exposed as a master on/off toggle
 * in the admin UI, but servertrack_init() initialised every infrastructure
 * class unconditionally — Retry (scheduled cron), CustomEvents (page-load
 * hooks), and all platform senders fired even when the plugin was disabled.
 * Fix: all init() calls are now wrapped inside the master enabled guard.
 *
 * BUG-NEW-5 FIX — uninstall.php missing _enc key + reinstall sentinel
 * --------------------------------------------------------------------
 * Same blind-spot as BUG-NEW-2: uninstall.php did not delete
 * servertrack_google_refresh_token_enc. Also confirmed servertrack_db_version
 * is already in the delete list (BUG-9 fix), so reinstall correctly reseeds.
 * Fix: servertrack_google_refresh_token_enc added to the options delete list
 * in uninstall.php.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * v6.0.4 — Three bootstrap bugs fixed (BUG-6, BUG-7, BUG-8). See below.
 * ─────────────────────────────────────────────────────────────────────────────
 */

define( 'SERVERTRACK_VERSION', '6.1.0' );
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
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-source-woocommerce.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-abandonment.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-order-status.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-wishlist.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-partial-refund.php';
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

    // BUG-NEW-4 FIX: Respect the master kill-switch. When the admin disables
    // the plugin via Settings → ServerTrack → Enable Plugin, NO hooks, cron
    // jobs, or AJAX handlers should be registered. Previously every class was
    // initialised unconditionally, bypassing this toggle entirely.
    if ( ! get_option( 'servertrack_enabled', 1 ) ) {
        return;
    }

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

        if ( get_option( 'servertrack_source_woo_enabled', 1 ) ) {

            if ( get_option( 'servertrack_source_woo_extended', 0 ) ) {
                ServerTrack_Source_WooCommerce::init();
            } else {
                ServerTrack_WooCommerce::init();
            }

            if ( class_exists( 'WC_Subscriptions' ) ) {
                ServerTrack_WooRenewals::init();
                ServerTrack_Subscriptions::init();
            }

            if ( get_option( 'servertrack_source_abandonment_enabled', 0 ) ) {
                ServerTrack_WooAbandonment::init();
            }

            if ( get_option( 'servertrack_source_order_status_enabled', 1 ) ) {
                ServerTrack_WooOrderStatus::init();
            }

            if ( get_option( 'servertrack_source_wishlist_enabled', 0 ) ) {
                ServerTrack_WooWishlist::init();
            }

            if ( get_option( 'servertrack_source_partial_refund_enabled', 1 ) ) {
                ServerTrack_WooPartialRefund::init();
            }

        }
    }

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
// BUG-NEW-1 FIX: Use per-version migration functions instead of calling
// servertrack_register_defaults() blindly on every upgrade. add_option() is
// safe but this pattern makes migrations explicit and auditable.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_run_upgrade(): void {
    $installed = get_option( 'servertrack_db_version', '0' );
    if ( version_compare( $installed, SERVERTRACK_VERSION, '>=' ) ) {
        return;
    }

    // Run cumulative migrations in order.
    if ( version_compare( $installed, '6.1.0', '<' ) ) {
        servertrack_migrate_to_610();
    }

    update_option( 'servertrack_db_version', SERVERTRACK_VERSION );
}

/**
 * Migration to v6.1.0.
 * Adds only the options that are NEW in this version.
 * Existing installs upgrading from any prior version will get these defaults
 * without touching any option the user has already customised.
 */
function servertrack_migrate_to_610(): void {
    // FIX-DR-3 (OAuth CSRF state): no stored option needed — state is transient-based.
    // FIX-DR-4 (encrypted token): add_option is a no-op if the key already exists;
    //   the plaintext fallback in decrypt_token() handles legacy rows transparently.
    add_option( 'servertrack_google_refresh_token_enc', '' );

    // BUG-6 FIX default (already in register_defaults, but guard existing installs).
    add_option( 'servertrack_source_woo_extended', 0 );
}

/**
 * Seeds ALL default options.
 * Called only from the activation hook (fresh installs).
 * Upgrade paths use per-version migration functions above.
 */
function servertrack_register_defaults(): void {
    $defaults = [
        // Core toggles
        'servertrack_enabled'                        => 1,
        'servertrack_debug_mode'                     => 0,
        'servertrack_debug_log'                      => [],
        'servertrack_retry_queue'                    => [],
        'servertrack_consent_mode'                   => 'none',
        // Platform toggles
        'servertrack_meta_enabled'                   => 0,
        'servertrack_tiktok_enabled'                 => 0,
        'servertrack_google_enabled'                 => 0,
        // Google OAuth (FIX-DR-4: encrypted token key)
        'servertrack_google_refresh_token_enc'       => '',
        // Webhook
        'servertrack_webhook_enabled'                => 0,
        'servertrack_webhook_url'                    => '',
        'servertrack_webhook_secret'                 => '',
        'servertrack_webhook_events'                 => '',
        // Frontend tracking
        'servertrack_scroll_depth'                   => 1,
        'servertrack_video_tracking'                 => 1,
        'servertrack_wishlist_tracking'              => 1,
        'servertrack_google_gtag_id'                 => '',
        'servertrack_google_gtag_label'              => '',
        // WooCommerce source toggles
        'servertrack_source_woo_enabled'             => 1,
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
// BUG-NEW-2 FIX: Added servertrack_google_refresh_token_enc to $credentials.
// FIX-DR-4 stores the encrypted refresh token under this key; the previous
// hook only deleted the old plaintext key, leaking the encrypted credential.
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
        'servertrack_google_refresh_token_enc',  // BUG-NEW-2 FIX: encrypted key
        'servertrack_google_access_token',
        'servertrack_webhook_secret',
    ];
    foreach ( $credentials as $opt ) {
        delete_option( $opt );
    }
} );
