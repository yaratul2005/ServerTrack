<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );

        // Capture click IDs from URL params and store in cookies
        add_action( 'wp_loaded', [ self::class, 'capture_click_ids' ] );
    }

    public static function enqueue_pixel_script() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        wp_register_script(
            'servertrack-pixel',
            SERVERTRACK_URL . 'frontend/assets/servertrack-pixel.js',
            [],
            SERVERTRACK_VERSION,
            true  // footer
        );

        // Build the PHP-to-JS data bridge
        $config = [
            'meta_pixel'   => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel' => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'    => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled' => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'   => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
        ];

        // Thank-you page: inject event_id and event data for dedup
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            global $wp;
            $order_id = absint( $wp->query_vars['order-received'] ?? 0 );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $config['event_id']   = ServerTrack_Dedup::get_event_id( $order_id );
                    $config['event_name'] = 'Purchase';
                    $config['value']      = (float) $order->get_total();
                    $config['currency']   = $order->get_currency();
                }
            }
        }

        wp_localize_script( 'servertrack-pixel', 'servertrack_config', $config );
        wp_enqueue_script( 'servertrack-pixel' );
    }

    /**
     * Capture gclid and ttclid from URL params and store in cookies.
     * Runs on wp_loaded to catch all page types.
     */
    public static function capture_click_ids() {
        if ( headers_sent() ) return;

        // GCLID — 90 days
        if ( ! empty( $_GET['gclid'] ) ) {
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            setcookie( '_gcl_aw', $gclid, time() + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }

        // TTCLID — 7 days
        if ( ! empty( $_GET['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            setcookie( 'ttclid', $ttclid, time() + ( 7 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }
    }
}
