<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );

        // Capture click IDs from URL params and store in cookies + transients
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
            'meta_pixel'      => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel'    => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'       => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled'    => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'      => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
            'google_enabled'  => (bool) get_option( 'servertrack_google_enabled', 0 ),
            // Google gtag measurement ID (e.g. AW-123456789)
            'gtag_id'         => get_option( 'servertrack_google_gtag_id', '' ),
            // Google Ads conversion label (e.g. AbCdEfGhIjK)
            'gtag_label'      => get_option( 'servertrack_google_gtag_label', '' ),
        ];

        // Thank-you page: inject event_id and event data for browser-side dedup
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

                    // ── GCLID session fallback (Day 4) ─────────────────────────────
                    // Primary source: cookie set on landing page by capture_click_ids().
                    // Fallback: transient stored at capture time, keyed to WC session ID.
                    // This ensures the server-side Google send() always gets a GCLID
                    // even when the browser cookie is not yet readable in the async cron.
                    $gclid = '';
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    if ( ! empty( $_COOKIE['_gcl_aw'] ) ) {
                        $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
                    } else {
                        // Try WC session-keyed transient
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
                        // Persist GCLID against this order so the cron async handler can read it
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
     * Capture gclid and ttclid from URL params and store in:
     *   1. Cookies (used by browser pixel and read back on checkout)
     *   2. WC-session-keyed transients (GCLID fallback for async cron)
     *
     * Runs on wp_loaded to catch all page types.
     */
    public static function capture_click_ids() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
        if ( headers_sent() ) return;

        // GCLID — 90-day cookie + 90-day transient keyed to WC session
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['gclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            setcookie( '_gcl_aw', $gclid, time() + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );

            // Store in transient for async cron fallback
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
                }
            }
        }

        // TTCLID — 7 days
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['ttclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            setcookie( 'ttclid', $ttclid, time() + ( 7 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }
    }
}
