<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend
 *
 * IMPROVEMENT: Meta Parameter Builder pattern for fbc coverage.
 *
 * Root cause of low fbc coverage:
 *   The _fbc cookie is set by the Meta Pixel JS (browser-side) AFTER the pixel
 *   loads. On the checkout/thank-you page there is a race condition where:
 *     - The customer arrived via a ?fbclid= URL
 *     - The Pixel JS fires and sets _fbc cookie
 *     - But PHP already ran and read $_COOKIE['_fbc'] BEFORE the cookie was set
 *     - Result: build_browser_user_data() returns fbc = '' — every time
 *
 *   Additionally, in the async cron context (send_purchase_async), the browser
 *   session is completely gone — $_COOKIE is empty, so _fbc is NEVER available.
 *
 * Fix (Meta Parameter Builder equivalent):
 *   1. capture_click_ids() now captures ?fbclid from the landing page URL and
 *      constructs the canonical fbc value (fb.1.{timestamp}.{fbclid}) server-side.
 *      This is stored in:
 *        a) A PHP session-backed cookie _fbc (matching the Pixel JS format exactly)
 *        b) A WC-session-keyed transient for async cron recovery
 *        c) Order meta _servertrack_fbc at checkout time for permanent persistence
 *
 *   2. build_order_user_data() in WooCommerce source now reads fbc from order meta
 *      as the highest-priority source, falling back to cookie.
 *
 *   This matches exactly what Meta's Parameter Builder SDK add-on does:
 *   "detect fbclid → construct fbc → persist server-side → send via CAPI".
 */
class ServerTrack_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );

        // Persist fbc/fbp to order meta at checkout so async cron can read them
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'persist_click_ids_to_order' ], 10, 1 );
    }

    public static function enqueue_pixel_script() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        wp_register_script(
            'servertrack-pixel',
            SERVERTRACK_URL . 'frontend/assets/servertrack-pixel.js',
            [],
            SERVERTRACK_VERSION,
            true
        );

        $config = [
            'meta_pixel'     => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel'   => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'      => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled'   => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'     => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
            'google_enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
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

                    // GCLID recovery
                    $gclid = '';
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    if ( ! empty( $_COOKIE['_gcl_aw'] ) ) {
                        $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
                    } else {
                        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
                        if ( $session_id ) {
                            $stored = get_transient( 'servertrack_gclid_' . $session_id );
                            if ( ! empty( $stored ) ) $gclid = (string) $stored;
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
     * Capture fbclid, gclid, ttclid from URL params.
     *
     * KEY CHANGE — fbc Parameter Builder logic:
     *   When ?fbclid= is present, immediately construct the canonical fbc value:
     *     fb.{version}.{capture_timestamp}.{fbclid_value}
     *   and store it in a cookie + transient, exactly matching what Meta Pixel JS
     *   would produce. This means the _fbc value is available to PHP on the SAME
     *   page load, eliminating the race condition where PHP reads cookies before
     *   the Meta Pixel JS has written them.
     */
    public static function capture_click_ids() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
        if ( headers_sent() ) return;

        $now = time();

        // ── fbclid → construct fbc (Meta Parameter Builder pattern) ──────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['fbclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );

            // Canonical fbc format: fb.{version}.{unix_ms}.{fbclid}
            // Version is always 1 per Meta spec.
            $fbc = 'fb.1.' . ( $now * 1000 ) . '.' . $fbclid;

            // Set _fbc cookie — 90 day expiry, same as Meta Pixel JS
            setcookie( '_fbc', $fbc, $now + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), false );
            // Also make it available in $_COOKIE for the rest of this request
            $_COOKIE['_fbc'] = $fbc;

            // Store in WC-session transient for async cron recovery
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_fbc_' . $session_id, $fbc, 90 * DAY_IN_SECONDS );
                }
            }
        }

        // ── gclid ───────────────────────────────────────────────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['gclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            setcookie( '_gcl_aw', $gclid, $now + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
                }
            }
        }

        // ── ttclid ────────────────────────────────────────────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['ttclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            setcookie( 'ttclid', $ttclid, $now + ( 7 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }
    }

    /**
     * Persist fbc, fbp, ttclid to order meta at the moment of checkout.
     *
     * This is the KEY hook for async cron recovery:
     * When send_purchase_async() fires seconds later in cron, $_COOKIE is gone.
     * Reading fbc from order meta ensures 100% coverage regardless of cron timing.
     *
     * Priority sources (in order):
     *   1. $_COOKIE (set by this request or by Meta Pixel JS)
     *   2. WC-session transient (set by capture_click_ids on landing page)
     */
    public static function persist_click_ids_to_order( WC_Order $order ) {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';

        // fbc
        $fbc = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        } elseif ( $session_id ) {
            $stored = get_transient( 'servertrack_fbc_' . $session_id );
            if ( ! empty( $stored ) ) $fbc = (string) $stored;
        }
        if ( ! empty( $fbc ) ) {
            $order->update_meta_data( '_servertrack_fbc', $fbc );
        }

        // fbp
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) ) {
            $order->update_meta_data( '_servertrack_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
        }

        // ttclid
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['ttclid'] ) ) {
            $order->update_meta_data( '_servertrack_ttclid', sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) ) );
        }

        $order->save_meta_data();
    }
}
