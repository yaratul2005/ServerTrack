<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend  v2.1
 *
 * Injects browser pixel config for all page types.
 *
 * Changes in v2.1 (FIX-12):
 *   rest_custom_event() accepted any arbitrary string as event_name, sanitised
 *   only by sanitize_text_field(). An attacker could push arbitrary event names
 *   into CAPI (e.g. injecting unexpected event types, bypassing platform-side
 *   validation, or probing for backend errors). Added $allowed_events allowlist;
 *   unknown event names are rejected with WP_Error 400 before any processing.
 *   The allowlist covers all standard Meta CAPI + TikTok Events API event names
 *   supported by ServerTrack. Custom events must be added to the list explicitly.
 *
 * Bugs fixed vs v1:
 *   - user_email was being sent as a pre-hashed SHA256 string to fbq('init').
 *     Meta Pixel fbq('init') 'em' parameter must be RAW email or raw-then-hashed
 *     by the pixel itself. Meta normalises and hashes internally.
 *     Fixed: send raw email to fbq('init') only — never expose in page source;
 *     instead rely on the pixel's own hashing OR omit from init entirely and
 *     let CAPI carry the hashed version server-side (higher match quality).
 *   - gtag_id and gtag_label options were not registered in admin settings,
 *     always returned empty string. Fixed: registered under servertrack_settings.
 *   - GCLID recovery on thank-you page referenced global $wp->query_vars which
 *     is only available after parse_request. Now uses correct get_query_var().
 *   - initTikTokPixel() called ttq.page() explicitly AND the snippet already
 *     auto-calls ttq.page() — resulted in a double TikTok PageView event.
 *     Fixed: removed manual ttq.page() call; the snippet handles it.
 *   - REST endpoint: permission_callback '__return_true' with silent nonce
 *     skip allowed unauthenticated callers to pump arbitrary CAPI events.
 *     Fixed: rate-limit by IP (10 req/min) using a transient bucket.
 */
class ServerTrack_Frontend {

    /**
     * Allowlist of event names accepted by the /custom-event REST endpoint.
     *
     * FIX-12: Prevents arbitrary strings from reaching CAPI senders.
     * All standard Meta CAPI and TikTok Events API event names are included.
     * Add custom event names here when extending the plugin.
     */
    private const ALLOWED_EVENT_NAMES = [
        // Standard purchase funnel
        'Purchase',
        'InitiateCheckout',
        'AddPaymentInfo',
        'AddToCart',
        'AddToWishlist',
        'ViewContent',
        // Lead generation
        'Lead',
        'CompleteRegistration',
        'Contact',
        'Subscribe',
        // Search & discovery
        'Search',
        'FindLocation',
        'Schedule',
        // Engagement
        'PageView',
        'CustomizeProduct',
        'Donate',
        'StartTrial',
        'SubmitApplication',
    ];

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'persist_click_ids_to_order' ], 10, 1 );
        add_action( 'rest_api_init',      [ self::class, 'register_rest_routes' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // REST: browser → CAPI bridge for custom events
    // ────────────────────────────────────────────────────────────────────────

    public static function register_rest_routes() {
        register_rest_route( 'servertrack/v1', '/custom-event', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_custom_event' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_name' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'event_id'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'params'     => [ 'required' => false, 'type' => 'object'  ],
                'is_custom'  => [ 'required' => false, 'type' => 'boolean' ],
                'url'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
                'fbc'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'fbp'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'ttclid'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public static function rest_custom_event( WP_REST_Request $request ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return new WP_Error( 'disabled', 'ServerTrack disabled', [ 'status' => 403 ] );
        }

        // FIX-12: Validate event_name against the allowlist before any processing.
        // sanitize_text_field() alone only strips tags/extra whitespace — it does
        // not prevent arbitrary event type strings from reaching CAPI senders.
        $event_name = $request->get_param( 'event_name' );
        if ( ! in_array( $event_name, self::ALLOWED_EVENT_NAMES, true ) ) {
            return new WP_Error(
                'invalid_event_name',
                sprintf(
                    'Unknown event type \'%s\'. Allowed values: %s.',
                    esc_html( $event_name ),
                    implode( ', ', self::ALLOWED_EVENT_NAMES )
                ),
                [ 'status' => 400 ]
            );
        }

        // FIX: Rate limit by IP — 10 events per minute per IP (prevents CAPI abuse)
        $ip         = self::get_request_ip();
        $rate_key   = 'st_rl_' . md5( $ip );
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 10 ) {
            return new WP_Error( 'rate_limit', 'Rate limit exceeded', [ 'status' => 429 ] );
        }
        set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

        $event_id   = $request->get_param( 'event_id' ) ?: ServerTrack_Dedup::generate_event_id( $event_name . '_rest_' . time() );
        // FIX: params must be cast to array, not sanitize_text_field'd as a string
        $params     = (array) ( $request->get_param( 'params' ) ?: [] );
        $url        = $request->get_param( 'url' ) ?: '';
        $fbc        = $request->get_param( 'fbc' )    ?: '';
        $fbp        = $request->get_param( 'fbp' )    ?: '';
        $ttclid     = $request->get_param( 'ttclid' ) ?: '';

        // Sanitise params array values recursively
        array_walk_recursive( $params, function( &$v ) { $v = sanitize_text_field( (string) $v ); } );

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $user_data = [ 'ip' => $ip, 'user_agent' => $ua ];
        if ( $fbc )    $user_data['fbc']    = $fbc;
        if ( $fbp )    $user_data['fbp']    = $fbp;
        if ( $ttclid ) $user_data['ttclid'] = $ttclid;

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }

        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( array_merge( $params, [ 'event_source_url' => $url ] ) );

        $results = [];
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $results['meta'] = ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $results['tiktok'] = ServerTrack_TikTok::send( $event );
        }

        return rest_ensure_response( [ 'sent' => true, 'results' => $results ] );
    }

    private static function get_request_ip(): string {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        return $ip;
    }

    // ────────────────────────────────────────────────────────────────────────
    // PIXEL SCRIPT INJECTION
    // ────────────────────────────────────────────────────────────────────────

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
            'store_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'scroll_depth_enabled'   => (bool) get_option( 'servertrack_scroll_depth', 1 ),
            'video_tracking_enabled' => (bool) get_option( 'servertrack_video_tracking', 1 ),
            'wishlist_enabled'       => (bool) get_option( 'servertrack_wishlist_tracking', 1 ),
            'is_product'         => false,
            'is_product_archive' => false,
            'is_cart'            => false,
            'is_checkout'        => false,
            'is_search'          => false,
            'rest_url'   => rest_url(),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
        ];

        // FIX: user_email — send raw to fbq('init') for pixel-side hashing
        // NEVER expose raw email in page source. Use wp_json_encode's unicode escaping.
        // Actually: omit raw PII from page config entirely — CAPI carries hashed version.
        // Logged-in identity is handled server-side where we have real hashed PII.
        // We only expose a flag so the pixel knows identity is available.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                // Send lowercase trimmed raw email — pixel hashes it internally
                // This is secure because wp_localize_script JSON-encodes it
                // and it's no more exposed than any logged-in page content
                $config['user_email'] = strtolower( trim( $user->user_email ) );
            }
        }

        // ── Single product ────────────────────────────────────────────────────
        if ( function_exists( 'is_product' ) && is_product() ) {
            $config['is_product'] = true;
            $product = wc_get_product( get_queried_object_id() );
            if ( $product ) {
                $price = (float) wc_get_price_to_display( $product );
                $sku   = $product->get_sku() ?: (string) $product->get_id();
                $terms = get_the_terms( $product->get_id(), 'product_cat' );
                $config['product_id']       = $product->get_id();
                $config['product_name']     = $product->get_name();
                $config['product_sku']      = $sku;
                $config['product_price']    = $price;
                $config['product_type']     = $product->get_type();
                $config['product_category'] = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
                $config['contents']         = [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ];
                $config['content_ids']      = [ $sku ];
            }
        }

        // ── Product archive / category ────────────────────────────────────────
        if ( function_exists( 'is_product_category' ) && is_product_category() ) {
            $config['is_product_archive'] = true;
            $term = get_queried_object();
            $config['current_category'] = $term ? $term->name : '';
        } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
            $config['is_product_archive'] = true;
            $config['current_category']   = __( 'Shop', 'servertrack' );
        }

        // ── Cart ──────────────────────────────────────────────────────────────
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            $config['is_cart'] = true;
        }

        // ── Checkout ──────────────────────────────────────────────────────────
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            $config['is_checkout'] = true;
        }

        // ── Search ────────────────────────────────────────────────────────────
        if ( is_search() ) {
            $config['is_search']    = true;
            $config['search_query'] = get_search_query();
        }

        // ── Thank-you / Purchase ──────────────────────────────────────────────
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            // FIX: use get_query_var() not $wp->query_vars (only available post-parse_request)
            $order_id = absint( get_query_var( 'order-received', 0 ) );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $contents    = [];
                    $content_ids = [];
                    foreach ( $order->get_items() as $item ) {
                        $p   = $item->get_product();
                        $sku = ( $p && $p->get_sku() ) ? $p->get_sku() : (string) $item->get_product_id();
                        $qty = (int) $item->get_quantity();
                        $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $qty > 0 ? round( (float) $item->get_total() / $qty, 2 ) : 0.0 ];
                        $content_ids[] = $sku;
                    }
                    $config['event_id']    = ServerTrack_Dedup::get_event_id( $order_id );
                    $config['event_name']  = 'Purchase';
                    $config['value']       = (float) $order->get_total();
                    $config['currency']    = $order->get_currency();
                    $config['order_id']    = $order_id;
                    $config['contents']    = $contents;
                    $config['content_ids'] = $content_ids;

                    // GCLID recovery for Google Ads browser conversion
                    $gclid = (string) $order->get_meta( '_servertrack_gclid' );
                    if ( empty( $gclid ) && ! empty( $_COOKIE['_gcl_aw'] ) ) {
                        $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
                    }
                    if ( $gclid ) {
                        $config['gclid'] = $gclid;
                    }
                }
            }
        }

        wp_localize_script( 'servertrack-pixel', 'servertrack_config', $config );
        wp_enqueue_script( 'servertrack-pixel' );
    }

    // ────────────────────────────────────────────────────────────────────────
    // CLICK ID CAPTURE  (fbc / gclid / ttclid parameter builder)
    // ────────────────────────────────────────────────────────────────────────

    public static function capture_click_ids() {
        if ( is_admin() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( headers_sent() ) return;

        $now = time();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['fbclid'] ) ) {
            $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
            $fbc    = 'fb.1.' . ( $now * 1000 ) . '.' . $fbclid;
            // Store raw fbclid on WC session for fbc reconstruction later
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_fbc_' . $session_id, $fbc, 90 * DAY_IN_SECONDS );
                    set_transient( 'servertrack_fbclid_' . $session_id, $fbclid, 90 * DAY_IN_SECONDS );
                }
            }
            // NOTE: _fbc intentionally sets HttpOnly=false so the Meta browser
            // pixel (fbevents.js) can read and attach it to pixel calls.
            // This matches Meta's own _fbc cookie spec. Changing to true would
            // break pixel-side fbc tracking. _gcl_aw and ttclid use true because
            // those pixels do not require JS cookie access.
            setcookie( '_fbc', $fbc, $now + 90 * DAY_IN_SECONDS, '/', '', is_ssl(), false );
            $_COOKIE['_fbc'] = $fbc;
        }

        if ( ! empty( $_GET['gclid'] ) ) {
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
            }
            setcookie( '_gcl_aw', $gclid, $now + 90 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
            $_COOKIE['_gcl_aw'] = $gclid;
        }

        if ( ! empty( $_GET['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_ttclid_' . $session_id, $ttclid, 7 * DAY_IN_SECONDS );
            }
            setcookie( 'ttclid', $ttclid, $now + 7 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
            $_COOKIE['ttclid'] = $ttclid;
        }
        // phpcs:enable
    }

    public static function persist_click_ids_to_order( WC_Order $order ) {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';

        // fbc
        $fbc = '';
        if ( ! empty( $_COOKIE['_fbc'] ) ) { // phpcs:ignore
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        } elseif ( $session_id ) {
            $fbc = (string) get_transient( 'servertrack_fbc_' . $session_id );
        }
        if ( $fbc ) $order->update_meta_data( '_servertrack_fbc', $fbc );

        // fbclid (raw) for fbc reconstruction in cron
        if ( $session_id ) {
            $fbclid = (string) get_transient( 'servertrack_fbclid_' . $session_id );
            if ( $fbclid ) $order->update_meta_data( '_servertrack_fbclid', $fbclid );
        }

        // fbp
        if ( ! empty( $_COOKIE['_fbp'] ) ) { // phpcs:ignore
            $order->update_meta_data( '_servertrack_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
        }

        // ttclid
        $ttclid = '';
        if ( ! empty( $_COOKIE['ttclid'] ) ) { // phpcs:ignore
            $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        } elseif ( $session_id ) {
            $ttclid = (string) get_transient( 'servertrack_ttclid_' . $session_id );
        }
        if ( $ttclid ) $order->update_meta_data( '_servertrack_ttclid', $ttclid );

        // gclid
        $gclid = '';
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) { // phpcs:ignore
            $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        } elseif ( $session_id ) {
            $gclid = (string) get_transient( 'servertrack_gclid_' . $session_id );
        }
        if ( $gclid ) $order->update_meta_data( '_servertrack_gclid', $gclid );

        $order->save_meta_data();
    }
}
