<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Core {

    public static function init() {
        // Admin UI
        if ( is_admin() ) {
            require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
            ServerTrack_Admin::init();
        }

        // Frontend pixel coordinator (always loaded on front-end)
        if ( ! is_admin() ) {
            require_once SERVERTRACK_DIR . 'frontend/class-servertrack-frontend.php';
            ServerTrack_Frontend::init();
        }

        // Retry queue — register cron hook unconditionally so it fires in all contexts
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';
        ServerTrack_Retry::init();

        // Unconditionally load all platform classes to avoid cron fragilities
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        // Event sources — always register hooks so cron callbacks fire correctly
        if ( class_exists( 'WooCommerce' ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
            ServerTrack_WooCommerce::init();

            // WooCommerce Subscriptions renewal handler (Day 5)
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';
            ServerTrack_WooRenewals::init();
        }

        if ( class_exists( 'WPCF7' ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
            ServerTrack_CF7::init();
        }

        if ( function_exists( 'EDD' ) || class_exists( 'Easy_Digital_Downloads' ) ) {
            require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';
            ServerTrack_EDD::init();
        }
    }
}
