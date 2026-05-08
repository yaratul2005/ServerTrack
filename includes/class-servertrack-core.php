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

        // Event sources — always register hooks so cron callbacks fire correctly
        require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
        ServerTrack_WooCommerce::init();

        require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
        ServerTrack_CF7::init();

        require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';
        ServerTrack_EDD::init();
    }
}
