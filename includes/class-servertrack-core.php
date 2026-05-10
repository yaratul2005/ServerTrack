<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Core  v3.0
 *
 * Central bootstrap — loads all platform drivers, sources, and the frontend.
 * Only loads what is needed per context (admin vs front-end vs cron).
 *
 * Changes in v3.0:
 *   - Added ServerTrack_CustomEvents initialisation
 *   - Added servertrack_scroll_depth, servertrack_video_tracking,
 *     servertrack_wishlist_tracking option registration
 *   - Added servertrack_google_gtag_id and servertrack_google_gtag_label registration
 */
class ServerTrack_Core {

    public static function init() {
        // ── Platform drivers (needed in cron too) ───────────────────────────
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        // ── Sources ─────────────────────────────────────────────────────────
        if ( class_exists( 'WooCommerce' ) && get_option( 'servertrack_source_woo_enabled', 1 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';
            ServerTrack_WooCommerce::init();
            ServerTrack_WooRenewals::init();
        }

        if ( class_exists( 'WPCF7' ) && get_option( 'servertrack_source_cf7_enabled', 0 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
            ServerTrack_CF7::init();
        }

        if ( class_exists( 'Easy_Digital_Downloads' ) && get_option( 'servertrack_source_edd_enabled', 0 ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';
            ServerTrack_EDD::init();
        }

        // ── Custom Events (always loaded — provides the PHP action hook,
        //    Search CAPI, ViewCategory CAPI, and standalone WP registration) ─
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-custom-events.php';
        ServerTrack_CustomEvents::init();

        // ── Retry processor ─────────────────────────────────────────────────
        add_action( 'servertrack_retry_failed_events', [ 'ServerTrack_Retry', 'process_queue' ] );

        // ── Admin ────────────────────────────────────────────────────────────
        if ( is_admin() ) {
            require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
            ServerTrack_Admin::init();
        }

        // ── Frontend pixel ──────────────────────────────────────────────────
        if ( ! is_admin() ) {
            require_once SERVERTRACK_DIR . 'frontend/class-servertrack-frontend.php';
            ServerTrack_Frontend::init();
        }

        // ── Register new options (v3.0 additions) ────────────────────────────
        self::register_v3_options();
    }

    /**
     * Register new settings added in v3.0 that the admin settings registration
     * loop (in ServerTrack_Admin::register_settings) does not yet know about.
     * Safe to call on every boot — add_option is a no-op if option exists.
     */
    private static function register_v3_options() {
        $new_options = [
            'servertrack_scroll_depth'         => 1,
            'servertrack_video_tracking'       => 1,
            'servertrack_wishlist_tracking'    => 1,
            'servertrack_google_gtag_id'       => '',
            'servertrack_google_gtag_label'    => '',
        ];
        foreach ( $new_options as $key => $default ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $default );
            }
        }

        // Whitelist new options in allowed_options so settings_fields() accepts them
        add_filter( 'allowed_options', function( array $allowed ): array {
            $v3 = [
                'servertrack_scroll_depth',
                'servertrack_video_tracking',
                'servertrack_wishlist_tracking',
                'servertrack_google_gtag_id',
                'servertrack_google_gtag_label',
            ];
            foreach ( $v3 as $opt ) {
                $allowed['servertrack_settings'][] = $opt;
            }
            return $allowed;
        } );
    }
}
