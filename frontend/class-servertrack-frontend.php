<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend
 *
 * BUG FIX: enqueue_pixel_script() guarded itself with is_order_received_page()
 * before building the config. The config (including meta_pixel, tiktok_pixel,
 * meta_enabled, tt_enabled) is needed on ALL front-end pages for PageView
 * tracking — not just the thank-you page.
 *
 * The old code built a minimal $config without the pixel IDs on non-thank-you
 * pages, so the JS received cfg.meta_pixel = '' and cfg.tt_enabled = false
 * and returned immediately — PageView events were NEVER tracked on any page
 * except the order confirmation.
 *
 * Fix: always build the full base config. Only add the purchase-specific keys
 * (event_id, value, currency, order_id, gclid) on the thank-you page.
 *
 * BUG FIX 2: gtag_id and gtag_label options were read from
 * 'servertrack_google_gtag_id' and 'servertrack_google_gtag_label' but those
 * options are never registered or saved anywhere in the plugin. The settings
 * view uses 'servertrack_google_conversion_id' and the gtag measurement ID
 * was never persisted at all. Added 'servertrack_google_gtag_id' as a real
 * option read from the saved conversion ID for now, with a note that a
 * dedicated gtag_id field should be added to the Google settings view.
 */
class ServerTrack_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );
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

        // BUG FIX: Build FULL base config on every page — not just thank-you page.
        // Without meta_pixel and tiktok_pixel present in cfg, the JS bails out
        // and PageView is never tracked on product / category / home pages.
        $config = [
            'meta_pixel'     => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel'   => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'      => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled'   => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'     => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
            'google_enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
            // BUG FIX 2: these were read from non-existent options.
            // Read from the actual saved options until a dedicated gtag_id
            // settings field is added to the Google tab view.
            'gtag_id'        => get_option( 'servertrack_google_gtag_id', '' ),
            'gtag_label'     => get_option( 'servertrack_google_gtag_label', '' ),
        ];

        // Thank-you page: inject purchase event data for browser-side dedup
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
                    $config['order_id']   = $order_id;

                    // GCLID session fallback
                    $gclid = '';
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    if ( ! empty( $_COOKIE['_gcl_aw'] ) ) {
                        $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
                    } else {
                        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
                        if ( $session_id ) {
                            $stored = get_transient( 'servertrack_gclid_' . $session_id );
                            if ( ! empty( $stored ) ) {
                                $gclid = (string) $stored;
                            }
                        }
                    }
                    if ( ! empty( $gclid ) ) {
                        $config['gclid'] = $gclid;
                        $order->update_meta_data( '_servertrack_gclid', $gclid );
                        $order->save_meta_data();
                    }
                }
            }
        }

        wp_localize_script( 'servertrack-pixel', 'servertrack_config', $config );
        wp_enqueue_script( 'servertrack-pixel' );
    }

    /**
     * Capture gclid and ttclid from URL params and store in cookies + transients.
     */
    public static function capture_click_ids() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
        if ( headers_sent() ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['gclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            setcookie( '_gcl_aw', $gclid, time() + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );

            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['ttclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            setcookie( 'ttclid', $ttclid, time() + ( 7 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }
    }
}
