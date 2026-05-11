<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Core  v3.2
 *
 * Changes in v3.2:
 *   - Bootstraps ServerTrack_WooAbandonment when WooCommerce is active and
 *     servertrack_source_abandonment_enabled is on.
 *   - Registers servertrack_source_abandonment_enabled and
 *     servertrack_abandonment_window_minutes default options.
 *
 * Changes in v3.1:
 *   - CRITICAL FIX: replaced mismatched add_action( 'servertrack_retry_failed_events', ... )
 *     with ServerTrack_Retry::init(). The old hook name never matched the actual
 *     hook 'servertrack_process_retry' — retries were never executed.
 *
 * Changes in v3.0:
 *   - Added ServerTrack_CustomEvents initialisation.
 *   - Added scroll_depth, video_tracking, wishlist_tracking options.
 *   - Added google_gtag_id and google_gtag_label options.
 */
class ServerTrack_Core {

    public static function init() {
        // ── Platform drivers ─────────────────────────────────────────────────
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        // ── WooCommerce sources ──────────────────────────────────────────────
        if ( class_exists( 'WooCommerce' ) && get_option( 'servertrack_source_woo_enabled', 1 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';
            ServerTrack_WooCommerce::init();
            ServerTrack_WooRenewals::init();

            // Cart abandonment — opt-in, separate toggle
            if ( get_option( 'servertrack_source_abandonment_enabled', 0 ) ) {
                require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-abandonment.php';
                ServerTrack_WooAbandonment::init();
            }
        }

        if ( class_exists( 'WPCF7' ) && get_option( 'servertrack_source_cf7_enabled', 0 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
            ServerTrack_CF7::init();
        }

        if ( class_exists( 'Easy_Digital_Downloads' ) && get_option( 'servertrack_source_edd_enabled', 0 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';
            ServerTrack_EDD::init();
        }

        // ── Custom Events ────────────────────────────────────────────────────
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-custom-events.php';
        ServerTrack_CustomEvents::init();

        // ── Retry processor ──────────────────────────────────────────────────
        ServerTrack_Retry::init();

        // ── Admin ────────────────────────────────────────────────────────────
        if ( is_admin() ) {
            require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
            ServerTrack_Admin::init();
        }

        // ── Frontend pixel ───────────────────────────────────────────────────
        if ( ! is_admin() ) {
            require_once SERVERTRACK_DIR . 'frontend/class-servertrack-frontend.php';
            ServerTrack_Frontend::init();
        }

        // ── Register options ─────────────────────────────────────────────────
        self::register_v3_options();
        self::register_v32_options();
    }

    private static function register_v3_options() {
        $new_options = [
            'servertrack_scroll_depth'      => 1,
            'servertrack_video_tracking'    => 1,
            'servertrack_wishlist_tracking' => 1,
            'servertrack_google_gtag_id'    => '',
            'servertrack_google_gtag_label' => '',
        ];
        foreach ( $new_options as $key => $default ) {
            if ( false === get_option( $key ) ) add_option( $key, $default );
        }
        add_filter( 'allowed_options', function( array $allowed ): array {
            $v3 = [
                'servertrack_scroll_depth', 'servertrack_video_tracking',
                'servertrack_wishlist_tracking', 'servertrack_google_gtag_id',
                'servertrack_google_gtag_label',
            ];
            foreach ( $v3 as $opt ) $allowed['servertrack_settings'][] = $opt;
            return $allowed;
        } );
    }

    /**
     * Register v3.2 options for cart abandonment.
     */
    private static function register_v32_options() {
        $new_options = [
            'servertrack_source_abandonment_enabled'  => 0,
            'servertrack_abandonment_window_minutes'  => 60,
        ];
        foreach ( $new_options as $key => $default ) {
            if ( false === get_option( $key ) ) add_option( $key, $default );
        }
        add_filter( 'allowed_options', function( array $allowed ): array {
            $v32 = [
                'servertrack_source_abandonment_enabled',
                'servertrack_abandonment_window_minutes',
            ];
            foreach ( $v32 as $opt ) $allowed['servertrack_sources_settings'][] = $opt;
            return $allowed;
        } );
    }
}
