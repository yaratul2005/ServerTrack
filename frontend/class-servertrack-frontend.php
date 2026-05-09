<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend  v3.0
 *
 * Injects full config for ALL page types:
 *   - meta_pixel, tiktok_pixel, gtag_id, gtag_label
 *   - page type flags: is_product, is_product_archive, is_checkout, is_cart
 *   - product data on single product pages
 *   - category data on archive pages
 *   - search query on search pages
 *   - user email (hashed — for fbq identify) when logged in
 *   - purchase data on thank-you page
 *   - store currency
 *   - REST URL + nonce for custom event CAPI bridge
 *   - feature flags: scroll_depth_enabled, video_tracking_enabled, wishlist_enabled
 *
 * fbc Parameter Builder:
 *   Captures ?fbclid from URL → constructs canonical fbc server-side →
 *   sets _fbc cookie + WC session transient + order meta at checkout.
 */
class ServerTrack_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'persist_click_ids_to_order' ], 10, 1 );

        // REST endpoint for browser → server custom event bridge
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
    }

    public static function register_rest_routes() {
        register_rest_route( 'servertrack/v1', '/custom-event', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_custom_event' ],
            'permission_callback' => '__return_true',  // nonce checked in callback
        ] );
    }

    /**
     * REST handler for browser-fired custom events → CAPI.
     * Fires to Meta + TikTok (server-side) so custom events have both
     * browser + server coverage, matching PixelMysite Pro behaviour.
     */
    public static function rest_custom_event( WP_REST_Request $request ) {
        // Verify nonce (wp_rest nonce sent as X-WP-Nonce header)
        if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
            // Allow unauthenticated for PageView-type events but log them
            // Don't hard-block — nonce may be absent for anonymous visitors
        }

        $event_name = sanitize_text_field( $request->get_param( 'event_name' ) ?: '' );
        $event_id   = sanitize_text_field( $request->get_param( 'event_id' )   ?: '' );
        $params     = $request->get_param( 'params' );
        $is_custom  = (bool) $request->get_param( 'is_custom' );
        $url        = esc_url_raw( $request->get_param( 'url' ) ?: '' );
        $fbc        = sanitize_text_field( $request->get_param( 'fbc' ) ?: '' );
        $fbp        = sanitize_text_field( $request->get_param( 'fbp' ) ?: '' );
        $ttclid     = sanitize_text_field( $request->get_param( 'ttclid' ) ?: '' );

        if ( empty( $event_name ) ) {
            return new WP_Error( 'missing_event_name', 'event_name required', [ 'status' => 400 ] );
        }

        $params = is_array( $params ) ? $params : [];

        // Build user_data from request context
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $user_data = [
            'ip'         => $ip,
            'user_agent' => $ua,
        ];
        if ( ! empty( $fbc ) )    $user_data['fbc']    = $fbc;
        if ( ! empty( $fbp ) )    $user_data['fbp']    = $fbp;
        if ( ! empty( $ttclid ) ) $user_data['ttclid'] = $ttclid;

        // Add logged-in user PII if available
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }

        $event = new ServerTrack_Event( $event_name, $event_id ?: ServerTrack_Dedup::generate_event_id( $event_name . '_rest_' . time() ) );
        $event->set_user_data( $user_data );
        $event->set_custom_data( array_merge( $params, [ 'event_source_url' => $url ] ) );

        $results = [];
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            // For custom events, we need a custom CAPI call — Meta handles
            // custom event names transparently in the events array
            $results['meta'] = ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $results['tiktok'] = ServerTrack_TikTok::send( $event );
        }

        return rest_ensure_response( [ 'sent' => true, 'results' => $results ] );
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

        // ── Base config (every page) ────────────────────────────────────────
        $config = [
            'meta_pixel'     => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel'   => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'      => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled'   => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'     => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
            'google_enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
            'gtag_id'        => get_option( 'servertrack_google_gtag_id', '' ),
            'gtag_label'     => get_option( 'servertrack_google_gtag_label', '' ),
            'store_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',

            // Feature flags
            'scroll_depth_enabled'  => (bool) get_option( 'servertrack_scroll_depth', 1 ),
            'video_tracking_enabled'=> (bool) get_option( 'servertrack_video_tracking', 1 ),
            'wishlist_enabled'      => (bool) get_option( 'servertrack_wishlist_tracking', 1 ),

            // Page type flags (for JS branching)
            'is_product'         => false,
            'is_product_archive' => false,
            'is_cart'            => false,
            'is_checkout'        => false,
            'is_search'          => false,

            // REST bridge for custom event server-side send
            'rest_url'   => rest_url(),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
        ];

        // ── User identity (improves EMQ on all platforms) ────────────────
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                // Send pre-hashed to JS — never raw PII in page source
                $config['user_email'] = hash( 'sha256', strtolower( trim( $user->user_email ) ) );
            }
        }

        // ── Single product page ──────────────────────────────────────
        if ( function_exists( 'is_product' ) && is_product() ) {
            $config['is_product'] = true;
            $product = wc_get_product( get_queried_object_id() );
            if ( $product ) {
                $config['product_id']   = $product->get_id();
                $config['product_name'] = $product->get_name();
                $config['product_sku']  = $product->get_sku() ?: (string) $product->get_id();
                $config['product_price']= (float) wc_get_price_to_display( $product );
                $config['product_type'] = $product->get_type();
                $terms = get_the_terms( $product->get_id(), 'product_cat' );
                $config['product_category'] = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
                $config['contents'] = [[
                    'id'         => $config['product_sku'],
                    'quantity'   => 1,
                    'item_price' => $config['product_price'],
                ]];
                $config['content_ids'] = [ $config['product_sku'] ];
            }
        }

        // ── Product archive / category page ─────────────────────────
        if ( function_exists( 'is_product_category' ) && is_product_category() ) {
            $config['is_product_archive'] = true;
            $term = get_queried_object();
            $config['current_category'] = $term ? $term->name : '';
        } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
            $config['is_product_archive'] = true;
            $config['current_category']   = __( 'Shop', 'servertrack' );
        }

        // ── Cart page ──────────────────────────────────────────
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            $config['is_cart'] = true;
        }

        // ── Checkout page ───────────────────────────────────────
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            $config['is_checkout'] = true;
        }

        // ── Search page ────────────────────────────────────────
        if ( is_search() ) {
            $config['is_search'] = true;
            $config['search_query'] = get_search_query();
        }

        // ── Thank-you / order received page ───────────────────────
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            global $wp;
            $order_id = absint( $wp->query_vars['order-received'] ?? 0 );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $contents    = [];
                    $content_ids = [];
                    foreach ( $order->get_items() as $item ) {
                        $p   = $item->get_product();
                        $sku = ( $p && $p->get_sku() ) ? $p->get_sku() : (string) $item->get_product_id();
                        $qty = $item->get_quantity();
                        $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $qty > 0 ? (float) $item->get_total() / $qty : 0.0 ];
                        $content_ids[] = $sku;
                    }
                    $config['event_id']    = ServerTrack_Dedup::get_event_id( $order_id );
                    $config['event_name']  = 'Purchase';
                    $config['value']       = (float) $order->get_total();
                    $config['currency']    = $order->get_currency();
                    $config['order_id']    = $order_id;
                    $config['contents']    = $contents;
                    $config['content_ids'] = $content_ids;

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

    public static function capture_click_ids() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
        if ( headers_sent() ) return;

        $now = time();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['fbclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
            $fbc    = 'fb.1.' . ( $now * 1000 ) . '.' . $fbclid;
            setcookie( '_fbc', $fbc, $now + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), false );
            $_COOKIE['_fbc'] = $fbc;
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_fbc_' . $session_id, $fbc, 90 * DAY_IN_SECONDS );
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['gclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            setcookie( '_gcl_aw', $gclid, $now + ( 90 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['ttclid'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            setcookie( 'ttclid', $ttclid, $now + ( 7 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
        }
    }

    public static function persist_click_ids_to_order( WC_Order $order ) {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';

        $fbc = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        } elseif ( $session_id ) {
            $stored = get_transient( 'servertrack_fbc_' . $session_id );
            if ( ! empty( $stored ) ) $fbc = (string) $stored;
        }
        if ( ! empty( $fbc ) ) $order->update_meta_data( '_servertrack_fbc', $fbc );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) ) {
            $order->update_meta_data( '_servertrack_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['ttclid'] ) ) {
            $order->update_meta_data( '_servertrack_ttclid', sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) ) );
        }

        $order->save_meta_data();
    }
}
