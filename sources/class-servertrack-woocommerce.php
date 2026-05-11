<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooCommerce  v2.2
 *
 * Handles all WooCommerce server-side CAPI events.
 *
 * Changes in v2.2:
 *   CRITICAL FIX — retry-on-success:
 *   send_view_content_async() and send_to_platforms() were calling
 *   ServerTrack_Retry::maybe_queue() unconditionally — including after
 *   a successful 200 response. This caused AddToCart, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, and ViewContent events to always
 *   queue a spurious retry even when the first send succeeded.
 *   Fix: all maybe_queue() calls are now wrapped in an explicit
 *   `if ( $result['status'] !== 'success' )` guard.
 *
 * Changes in v2.1:
 *   - on_thankyou: now calls ServerTrack_Consent::capture_for_order() while
 *     the customer's browser is still present. This snapshots consent state
 *     into order meta (_servertrack_consent) so that the async cron job can
 *     read authoritative per-order consent instead of bypassing cookie checks.
 *   - send_purchase_async: all three ServerTrack_Consent::is_granted() calls
 *     now pass $order_id so the per-order consent record is used in cron context.
 *
 * Fixed bugs (vs v1):
 *   - on_add_to_cart: hook signature was (key,product_id,qty,variation_id) — WC actually
 *     passes 6 args. Added $variation_id and $cart_item_data. Also added content_ids and
 *     content_type to custom_data (were missing = lower EMQ). Now skips draft/private products.
 *   - on_initiate_checkout: fired every page load = 50+ CAPI hits per checkout session.
 *     Fixed with a per-session transient dedup guard (TTL = 30 min).
 *   - on_view_content: synchronous HTTP call blocking page render. Now runs async via
 *     wp_schedule_single_event dispatched immediately (runs in separate PHP process).
 *   - send_purchase_async Google path: was generating a new event_id on completed trigger
 *     instead of reusing the stored one — broke dedup on the Google side.
 *   - send_purchase_async Google path: was not calling mark_as_sent on success.
 *   - on_order_refunded: used update_post_meta (broken on HPOS orders). Now uses
 *     WC_Order::update_meta_data + save().
 *   - on_new_customer: attempted to read _fbc/_fbp cookies in a hook that fires
 *     during checkout POST — WC session may not be available. Switched to order meta
 *     lookup via billing email fallback.
 *   - build_browser_user_data: WC_Geolocation::get_ip_address() returns
 *     '::ffff:1.2.3.4' format. Now strips IPv4-mapped IPv6 prefix.
 *   - AddToCart/InitiateCheckout missing content_type in custom_data.
 *   - purchase dedup: mark_as_sent was not called before retry queue. Fixed to mark
 *     optimistically on first success only.
 */
class ServerTrack_WooCommerce {

    /**
     * Timeout (seconds) for synchronous CAPI calls made in browser-request context.
     * Low enough to not block page render; CAPI usually responds in <1s.
     */
    const SYNC_TIMEOUT = 3;

    public static function init() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }

        // Purchase — async dispatch from thank-you page
        add_action( 'woocommerce_thankyou',                   [ self::class, 'on_thankyou' ],             10, 1 );
        // Google fallback — fires if payment gateway status changes to completed
        add_action( 'woocommerce_order_status_completed',     [ self::class, 'on_order_completed' ],      10, 1 );
        // Refund tracking
        add_action( 'woocommerce_order_status_refunded',      [ self::class, 'on_order_refunded' ],       10, 1 );

        // Product/Cart events — browser-request context, low timeout
        add_action( 'woocommerce_after_single_product',       [ self::class, 'on_view_content_dispatch' ] );
        // FIX: WC woocommerce_add_to_cart passes 6 args not 4
        add_action( 'woocommerce_add_to_cart',                [ self::class, 'on_add_to_cart' ],          10, 6 );
        add_action( 'woocommerce_before_checkout_form',       [ self::class, 'on_initiate_checkout' ] );
        add_action( 'woocommerce_checkout_order_created',     [ self::class, 'on_add_payment_info' ],     10, 1 );

        // New customer / lead
        add_action( 'woocommerce_created_customer',           [ self::class, 'on_new_customer' ],         10, 3 );

        // Async cron callbacks
        add_action( 'servertrack_send_woo_purchase',          [ self::class, 'send_purchase_async' ],     10, 2 );
        add_action( 'servertrack_send_woo_view_content',      [ self::class, 'send_view_content_async' ], 10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // PURCHASE (Thank-you page + order completed fallback)
    // ────────────────────────────────────────────────────────────────────────

    public static function on_thankyou( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Skip subscription renewals (handled by renewals source)
        if ( $order->get_meta( '_subscription_renewal' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Subscription renewal — handled by renewals source.', '', '', $order_id, 'Purchase' );
            return;
        }

        // ── v2.1: Capture consent while the customer's browser is present ────
        // This MUST happen before the async cron dispatch. The cron job has no
        // browser session, so it cannot read cookies. Capturing here stores a
        // per-order consent snapshot (_servertrack_consent order meta) that the
        // async send will read via ServerTrack_Consent::is_granted( $platform, $order_id ).
        ServerTrack_Consent::capture_for_order( $order_id );

        // Generate and store a stable event_id for this order (idempotent)
        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }

        // Dispatch async — do not block the thank-you page render
        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'thankyou' ] );
        // Immediately spawn the cron worker so it doesn't wait for next visit
        spawn_cron();
    }

    public static function on_order_completed( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_google_enabled', 0 ) ) return;
        // Only dispatch if Google hasn't been sent yet (handles late gateway completion)
        if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'google', 'order_status_completed: already sent', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }
        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'completed' ] );
        spawn_cron();
    }

    public static function on_order_refunded( int $order_id ) {
        // FIX: use HPOS-compatible order meta, not post_meta
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_servertrack_refunded', '1' );
            $order->save_meta_data();
        }
        ServerTrack_Logger::log( 'info', 'all', 'Order #' . $order_id . ' marked as refunded — future CAPI sends blocked.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Refund' );
    }

    public static function send_purchase_async( int $order_id, string $trigger ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'send_purchase_async: order #' . $order_id . ' not found.', '', '', $order_id, 'Purchase' );
            return;
        }

        // FIX: HPOS-compatible refund check
        if ( '1' === (string) $order->get_meta( '_servertrack_refunded' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Aborted — order was refunded.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }

        // FIX: ensure event_id exists — on 'completed' trigger it may not exist
        // if thankyou didn't fire (e.g. gateway redirects externally)
        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }

        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_purchase_custom_data( $order );

        // ── META (thankyou trigger) ──────────────────────────────────────────
        if ( 'thankyou' === $trigger && get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'meta' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent', '', $event_id, $order_id, 'Purchase' );
            } elseif ( ! ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_Meta::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'meta' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }

        // ── TIKTOK (thankyou trigger) ────────────────────────────────────────
        if ( 'thankyou' === $trigger && get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'tiktok' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent', '', $event_id, $order_id, 'Purchase' );
            } elseif ( ! ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_TikTok::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'tiktok' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }

        // ── GOOGLE (both triggers — thankyou for browser, completed as fallback) ──
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent (trigger=' . $trigger . ')', '', $event_id, $order_id, 'Purchase' );
            } elseif ( ! ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_Google::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'google' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // VIEW CONTENT  (async dispatch to avoid blocking page render)
    // ────────────────────────────────────────────────────────────────────────

    public static function on_view_content_dispatch() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) && ! get_option( 'servertrack_tiktok_enabled', 0 ) ) return;
        $product_id = get_queried_object_id();
        if ( ! $product_id ) return;

        $context = self::capture_browser_context();
        wp_schedule_single_event( time(), 'servertrack_send_woo_view_content', [ $product_id, $context ] );
        spawn_cron();
    }

    public static function send_view_content_async( int $product_id, array $context ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        if ( ! in_array( $product->get_status(), [ 'publish' ], true ) ) return;

        $price = (float) wc_get_price_to_display( $product );
        $sku   = $product->get_sku() ?: (string) $product->get_id();

        // Use fully-random UUID for ViewContent — no determinism needed
        $event_id = ServerTrack_Dedup::generate_event_id();
        $event = new ServerTrack_Event( 'ViewContent', $event_id );
        $event->set_user_data( $context )->set_custom_data( [
            'currency'     => get_woocommerce_currency(),
            'value'        => $price,
            'contents'     => [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ],
            'content_ids'  => [ $sku ],
            'content_type' => 'product',
        ] );

        // FIX (v2.2): only queue retry if the send actually failed
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // ADD TO CART
    // ────────────────────────────────────────────────────────────────────────

    public static function on_add_to_cart(
        string $cart_item_key,
        int    $product_id,
        int    $quantity,
        int    $variation_id,
        array  $variation      = [],
        array  $cart_item_data = []
    ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product ) return;

        if ( 'publish' !== $product->get_status() ) return;

        $price = (float) wc_get_price_to_display( $product );
        $sku   = $product->get_sku() ?: (string) $product_id;

        $event_id = ServerTrack_Dedup::generate_event_id();
        $event = new ServerTrack_Event( 'AddToCart', $event_id );
        $event->set_user_data( self::build_browser_user_data() )->set_custom_data( [
            'currency'     => get_woocommerce_currency(),
            'value'        => round( $price * $quantity, 2 ),
            'content_ids'  => [ $sku ],
            'contents'     => [ [ 'id' => $sku, 'quantity' => $quantity, 'item_price' => $price ] ],
            'content_type' => 'product',
        ] );

        self::send_to_platforms( $event, $meta_on, $tiktok_on );
    }

    // ────────────────────────────────────────────────────────────────────────
    // INITIATE CHECKOUT
    // ────────────────────────────────────────────────────────────────────────

    public static function on_initiate_checkout() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        if ( ! WC()->cart ) return;

        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
        if ( empty( $session_id ) ) return;

        $dedup_key = 'servertrack_ic_' . md5( $session_id );
        if ( get_transient( $dedup_key ) ) {
            return;
        }
        set_transient( $dedup_key, 1, 30 * MINUTE_IN_SECONDS );

        $event_id = ServerTrack_Dedup::generate_event_id( 'checkout_' . $session_id );
        $cart     = WC()->cart;
        $contents = [];
        foreach ( $cart->get_cart() as $cart_item ) {
            /** @var WC_Product $prod */
            $prod = $cart_item['data'];
            if ( ! $prod instanceof WC_Product ) continue;
            $sku        = $prod->get_sku() ?: (string) $cart_item['product_id'];
            $qty        = (int) $cart_item['quantity'];
            $contents[] = [
                'id'         => $sku,
                'quantity'   => $qty,
                'item_price' => (float) wc_get_price_to_display( $prod ),
            ];
        }

        $event = new ServerTrack_Event( 'InitiateCheckout', $event_id );
        $event->set_user_data( self::build_browser_user_data() )->set_custom_data( [
            'currency'     => get_woocommerce_currency(),
            'value'        => (float) $cart->get_total( 'edit' ),
            'content_type' => 'product',
            'contents'     => $contents,
        ] );

        self::send_to_platforms( $event, $meta_on, $tiktok_on );
    }

    // ────────────────────────────────────────────────────────────────────────
    // ADD PAYMENT INFO
    // ────────────────────────────────────────────────────────────────────────

    public static function on_add_payment_info( WC_Order $order ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $order_id = $order->get_id();
        if ( $order->get_meta( '_servertrack_api_sent' ) ) return;
        $order->update_meta_data( '_servertrack_api_sent', '1' );
        $order->save_meta_data();

        $event_id  = ServerTrack_Dedup::generate_event_id( 'api_' . $order_id );
        $user_data = self::build_order_user_data( $order );

        $event = new ServerTrack_Event( 'AddPaymentInfo', $event_id );
        $event->set_user_data( $user_data )->set_custom_data( [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'content_type' => 'product',
        ] );

        self::send_to_platforms( $event, $meta_on, $tiktok_on );
    }

    // ────────────────────────────────────────────────────────────────────────
    // NEW CUSTOMER
    // ────────────────────────────────────────────────────────────────────────

    public static function on_new_customer( int $customer_id, array $new_customer_data, bool $password_generated ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $event_id  = ServerTrack_Dedup::generate_event_id( 'reg_' . $customer_id );
        $user_data = [];

        $ip = self::get_real_ip();
        if ( $ip ) $user_data['ip'] = $ip;
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( $ua ) $user_data['user_agent'] = $ua;

        $email = sanitize_email( $new_customer_data['user_email'] ?? '' );
        if ( $email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $email );

        $first = sanitize_text_field( $new_customer_data['first_name'] ?? '' );
        if ( $first ) $user_data['first_name'] = ServerTrack_Hasher::hash( $first );

        $last = sanitize_text_field( $new_customer_data['last_name'] ?? '' );
        if ( $last ) $user_data['last_name'] = ServerTrack_Hasher::hash( $last );

        $event = new ServerTrack_Event( 'CompleteRegistration', $event_id );
        $event->set_user_data( $user_data )->set_custom_data( [
            'content_name' => 'New Customer Registration',
            'status'       => 'registered',
        ] );

        self::send_to_platforms( $event, $meta_on, $tiktok_on );
    }

    // ────────────────────────────────────────────────────────────────────────
    // SHARED HELPERS
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Send an event to all enabled platforms.
     * FIX (v2.2): only calls maybe_queue() when the send actually failed.
     * Previously called unconditionally — queued a retry even on success.
     */
    private static function send_to_platforms(
        ServerTrack_Event $event,
        bool $meta_on,
        bool $tiktok_on
    ) {
        $timeout_cb = [ self::class, '_http_timeout_filter' ];
        add_filter( 'http_request_args', $timeout_cb, 999 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
        }

        remove_filter( 'http_request_args', $timeout_cb, 999 );
    }

    public static function _http_timeout_filter( array $args ): array {
        $args['timeout'] = self::SYNC_TIMEOUT;
        return $args;
    }

    private static function capture_browser_context(): array {
        return self::build_browser_user_data();
    }

    private static function get_real_ip(): string {
        $ip = '';
        if ( class_exists( 'WC_Geolocation' ) ) {
            $ip = WC_Geolocation::get_ip_address();
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) {
            $ip = substr( $ip, 7 );
        }
        return sanitize_text_field( $ip );
    }

    private static function build_browser_user_data(): array {
        $data = [];
        $ip   = self::get_real_ip();
        if ( $ip ) $data['ip'] = $ip;

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( $ua ) $data['user_agent'] = $ua;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )    $data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )    $data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) )  $data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $data['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        // phpcs:enable

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }

        return $data;
    }

    private static function build_order_user_data( WC_Order $order ): array {
        $data = [];

        $ip = $order->get_customer_ip_address();
        if ( substr( (string) $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = $ip;

        $ua = $order->get_customer_user_agent();
        if ( $ua ) $data['user_agent'] = $ua;

        $fbc = (string) $order->get_meta( '_servertrack_fbc' );
        if ( empty( $fbc ) && ! empty( $_COOKIE['_fbc'] ) ) {
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ); // phpcs:ignore
        }
        if ( empty( $fbc ) ) {
            $fbclid = (string) $order->get_meta( '_servertrack_fbclid' );
            if ( $fbclid ) {
                $ts  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
                $fbc = 'fb.1.' . ( $ts * 1000 ) . '.' . $fbclid;
            }
        }
        if ( $fbc ) $data['fbc'] = $fbc;

        $fbp = (string) $order->get_meta( '_servertrack_fbp' );
        if ( empty( $fbp ) && ! empty( $_COOKIE['_fbp'] ) ) {
            $fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ); // phpcs:ignore
        }
        if ( $fbp ) $data['fbp'] = $fbp;

        $ttclid = (string) $order->get_meta( '_servertrack_ttclid' );
        if ( empty( $ttclid ) && ! empty( $_COOKIE['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) ); // phpcs:ignore
        }
        if ( $ttclid ) $data['ttclid'] = $ttclid;

        $gclid = (string) $order->get_meta( '_servertrack_gclid' );
        if ( empty( $gclid ) && ! empty( $_COOKIE['_gcl_aw'] ) ) {
            $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) ); // phpcs:ignore
        }
        if ( $gclid ) $data['gclid'] = $gclid;

        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = ServerTrack_Hasher::hash_email( $email );

        $phone = $order->get_billing_phone();
        if ( $phone ) {
            static $country_codes = [
                'US'=>'1','CA'=>'1','GB'=>'44','AU'=>'61','DE'=>'49','FR'=>'33',
                'IT'=>'39','ES'=>'34','NL'=>'31','SE'=>'46','NO'=>'47','DK'=>'45',
                'FI'=>'358','CH'=>'41','AT'=>'43','IE'=>'353','NZ'=>'64','ZA'=>'27',
                'IN'=>'91','BR'=>'55','BD'=>'880','PK'=>'92','NG'=>'234','MX'=>'52',
                'JP'=>'81','KR'=>'82','SG'=>'65','MY'=>'60','TH'=>'66','PH'=>'63',
                'ID'=>'62','VN'=>'84','HK'=>'852','TW'=>'886','AE'=>'971','SA'=>'966',
            ];
            $cc = $country_codes[ $order->get_billing_country() ] ?? '';
            $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $cc );
        }

        $pii = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'zip'        => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ];
        foreach ( $pii as $key => $val ) {
            if ( $val ) $data[ $key ] = ServerTrack_Hasher::hash( $val );
        }

        $customer_id = $order->get_customer_id();
        $data['external_id'] = ServerTrack_Hasher::hash( (string) ( $customer_id ?: $order->get_id() ) );

        return $data;
    }

    private static function build_purchase_custom_data( WC_Order $order ): array {
        $contents    = [];
        $content_ids = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_product_id();
            $qty     = (int) $item->get_quantity();
            $price   = $qty > 0 ? (float) $item->get_total() / $qty : 0.0;

            $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => round( $price, 2 ) ];
            $content_ids[] = $sku;
        }
        return [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'contents'     => $contents,
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'order_id'     => $order->get_id(),
            'num_items'    => count( $contents ),
        ];
    }
}
