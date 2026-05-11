<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooCommerce  v3.0
 *
 * Changes in v3.0 (Feature #9 — Identity & Click ID wiring):
 *   - build_order_user_data() now uses ServerTrack_Identity::get_external_id_for_order()
 *     instead of the previous hashed order-ID fallback. This gives every order the
 *     strongest possible external_id (stable WP user UID > guest email match > order ID).
 *   - build_order_user_data() now merges ServerTrack_ClickCapture::get_for_order() as
 *     the PRIMARY source of click IDs (fbc, fbp, ttclid, gclid), before falling back
 *     to order meta and cookie values. This recovers click IDs that were lost to
 *     Safari ITP / Firefox ETP / ad blockers.
 *   - send_purchase_async() and send_refund_async() now call
 *     ServerTrack_MatchQuality::annotate() on each event before sending.
 *     The EMQ score is stripped from the payload before API dispatch but
 *     persisted to the logger so the dashboard can display per-event scores.
 *   - All logger calls updated to pass event_type as 7th argument (v2.0 signature).
 *
 * Changes in v2.3:
 *   - on_order_refunded() fires real Refund CAPI event.
 *
 * Changes in v2.2:
 *   CRITICAL FIX — retry-on-success guard.
 *
 * Changes in v2.1:
 *   - Consent capture on thank-you page.
 *   - Per-order consent in async cron.
 */
class ServerTrack_WooCommerce {

    const SYNC_TIMEOUT = 3;

    public static function init() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }

        add_action( 'woocommerce_thankyou',               [ self::class, 'on_thankyou' ],             10, 1 );
        add_action( 'woocommerce_order_status_completed', [ self::class, 'on_order_completed' ],      10, 1 );
        add_action( 'woocommerce_order_status_refunded',  [ self::class, 'on_order_refunded' ],       10, 1 );

        add_action( 'woocommerce_after_single_product',   [ self::class, 'on_view_content_dispatch' ] );
        add_action( 'woocommerce_add_to_cart',            [ self::class, 'on_add_to_cart' ],          10, 6 );
        add_action( 'woocommerce_before_checkout_form',   [ self::class, 'on_initiate_checkout' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'on_add_payment_info' ],     10, 1 );
        add_action( 'woocommerce_created_customer',       [ self::class, 'on_new_customer' ],         10, 3 );

        // Async cron callbacks
        add_action( 'servertrack_send_woo_purchase',      [ self::class, 'send_purchase_async' ],     10, 2 );
        add_action( 'servertrack_send_woo_view_content',  [ self::class, 'send_view_content_async' ], 10, 1 );
        add_action( 'servertrack_send_woo_refund',        [ self::class, 'send_refund_async' ],       10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // PURCHASE
    // ────────────────────────────────────────────────────────────────────────

    public static function on_thankyou( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $order->get_meta( '_subscription_renewal' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Subscription renewal — handled by renewals source.', '', '', $order_id, 'Purchase' );
            return;
        }

        ServerTrack_Consent::capture_for_order( $order_id );

        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }

        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'thankyou' ] );
        spawn_cron();
    }

    public static function on_order_completed( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_google_enabled', 0 ) ) return;
        if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'google', 'order_status_completed: already sent', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }
        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'completed' ] );
        spawn_cron();
    }

    public static function on_order_refunded( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $order->update_meta_data( '_servertrack_refunded', '1' );
        $order->save_meta_data();
        ServerTrack_Logger::log( 'queued', 'all', 'Order #' . $order_id . ' refunded — queuing Refund CAPI event.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Refund' );
        wp_schedule_single_event( time(), 'servertrack_send_woo_refund', [ $order_id ] );
        spawn_cron();
    }

    public static function send_refund_async( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'send_refund_async: order #' . $order_id . ' not found.', '', '', $order_id, 'Refund' );
            return;
        }
        $refund_event_id_key = 'refund_' . $order_id;
        $event_id = ServerTrack_Dedup::get_event_id( $refund_event_id_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $refund_event_id_key );
            ServerTrack_Dedup::store_event_id( $refund_event_id_key, $event_id );
        }
        if ( ServerTrack_Dedup::was_sent( $refund_event_id_key, 'meta' )
            && ServerTrack_Dedup::was_sent( $refund_event_id_key, 'tiktok' )
            && ServerTrack_Dedup::was_sent( $refund_event_id_key, 'google' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'all', 'Refund #' . $order_id . ' already sent.', '', $event_id, $order_id, 'Refund' );
            return;
        }
        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_purchase_custom_data( $order );
        $custom_data['content_name'] = 'Refund';

        // v3.0: compute and attach EMQ
        $temp_event = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data );
        $emq        = ServerTrack_MatchQuality::score( $user_data );

        if ( get_option( 'servertrack_meta_enabled', 0 ) && ! ServerTrack_Dedup::was_sent( $refund_event_id_key, 'meta' ) ) {
            $meta_data          = $custom_data;
            $meta_data['value'] = -1 * abs( $meta_data['value'] );
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $meta_data );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $refund_event_id_key, 'meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ! ServerTrack_Dedup::was_sent( $refund_event_id_key, 'tiktok' ) ) {
            $tt_data          = $custom_data;
            $tt_data['value'] = -1 * abs( $tt_data['value'] );
            $e      = ( new ServerTrack_Event( 'PlaceAnOrder', $event_id ) )->set_user_data( $user_data )->set_custom_data( $tt_data );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $refund_event_id_key, 'tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }
        if ( get_option( 'servertrack_google_enabled', 0 ) && ! ServerTrack_Dedup::was_sent( $refund_event_id_key, 'google' ) ) {
            $g_data          = $custom_data;
            $g_data['value'] = -1 * abs( $g_data['value'] );
            $e      = ( new ServerTrack_Event( 'refund', $event_id ) )->set_user_data( $user_data )->set_custom_data( $g_data );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $refund_event_id_key, 'google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }
    }

    public static function send_purchase_async( int $order_id, string $trigger ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'send_purchase_async: order #' . $order_id . ' not found.', '', '', $order_id, 'Purchase' );
            return;
        }
        if ( '1' === (string) $order->get_meta( '_servertrack_refunded' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Aborted — order was refunded.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }
        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }
        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_purchase_custom_data( $order );

        // v3.0: compute EMQ once, pass to all logger calls
        $emq = ServerTrack_MatchQuality::score( $user_data );

        if ( 'thankyou' === $trigger && get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'meta' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_Meta::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'meta' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
                ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Purchase #' . $order_id, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }
        if ( 'thankyou' === $trigger && get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'tiktok' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_TikTok::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'tiktok' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
                ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Purchase #' . $order_id, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent (trigger=' . $trigger . ')', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_Google::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $order_id, 'google' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
                ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Purchase #' . $order_id, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // VIEW CONTENT
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
        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product->get_id();
        $event_id = ServerTrack_Dedup::generate_event_id();
        $event    = new ServerTrack_Event( 'ViewContent', $event_id );
        $event->set_user_data( $context )->set_custom_data( [
            'currency'     => get_woocommerce_currency(),
            'value'        => $price,
            'contents'     => [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ],
            'content_ids'  => [ $sku ],
            'content_type' => 'product',
        ] );
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'ViewContent product #' . $product_id, '', $event_id, 0, 'ViewContent' );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'ViewContent product #' . $product_id, '', $event_id, 0, 'ViewContent' );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // ADD TO CART
    // ────────────────────────────────────────────────────────────────────────

    public static function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation = [], array $cart_item_data = [] ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product || 'publish' !== $product->get_status() ) return;
        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product_id;
        $event_id = ServerTrack_Dedup::generate_event_id();
        $event    = new ServerTrack_Event( 'AddToCart', $event_id );
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
        if ( get_transient( $dedup_key ) ) return;
        set_transient( $dedup_key, 1, 30 * MINUTE_IN_SECONDS );
        $event_id = ServerTrack_Dedup::generate_event_id( 'checkout_' . $session_id );
        $cart     = WC()->cart;
        $contents = [];
        foreach ( $cart->get_cart() as $cart_item ) {
            $prod = $cart_item['data'];
            if ( ! $prod instanceof WC_Product ) continue;
            $sku        = $prod->get_sku() ?: (string) $cart_item['product_id'];
            $qty        = (int) $cart_item['quantity'];
            $contents[] = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => (float) wc_get_price_to_display( $prod ) ];
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
        $event     = new ServerTrack_Event( 'AddPaymentInfo', $event_id );
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
        // v3.0: use stable identity UID
        $user_data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $customer_id );
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

    private static function send_to_platforms( ServerTrack_Event $event, bool $meta_on, bool $tiktok_on ) {
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
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
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
            // v3.0: stable identity UID for logged-in users
            $data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $user->ID );
        }
        return $data;
    }

    /**
     * Build user_data from a WC order.
     *
     * v3.0 changes:
     *   - external_id now uses ServerTrack_Identity (stable UID > guest match > order ID)
     *   - Click IDs now use ServerTrack_ClickCapture::get_for_order() as primary source
     *     (server-side persistent store), with order meta + cookie as fallbacks.
     */
    private static function build_order_user_data( WC_Order $order ): array {
        $data = [];
        $ip = $order->get_customer_ip_address();
        if ( substr( (string) $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = $ip;
        $ua = $order->get_customer_user_agent();
        if ( $ua ) $data['user_agent'] = $ua;

        // v3.0: server-side click ID store (primary source)
        $customer_id = (int) $order->get_customer_id();
        $session_id  = (string) ( $order->get_meta( '_servertrack_session_id' ) ?: '' );
        $stored_clicks = ServerTrack_ClickCapture::get_for_order( $customer_id, $session_id );

        // fbc — server store > order meta > cookie > build from fbclid
        $fbc = $stored_clicks['fbc'] ?? '';
        if ( empty( $fbc ) ) $fbc = (string) $order->get_meta( '_servertrack_fbc' );
        if ( empty( $fbc ) && ! empty( $_COOKIE['_fbc'] ) ) $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ); // phpcs:ignore
        if ( empty( $fbc ) ) {
            $fbclid = $stored_clicks['fbclid'] ?? (string) $order->get_meta( '_servertrack_fbclid' );
            if ( $fbclid ) {
                $ts  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
                $fbc = 'fb.1.' . ( $ts * 1000 ) . '.' . $fbclid;
            }
        }
        if ( $fbc ) $data['fbc'] = $fbc;

        // fbp
        $fbp = $stored_clicks['fbp'] ?? '';
        if ( empty( $fbp ) ) $fbp = (string) $order->get_meta( '_servertrack_fbp' );
        if ( empty( $fbp ) && ! empty( $_COOKIE['_fbp'] ) ) $fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ); // phpcs:ignore
        if ( $fbp ) $data['fbp'] = $fbp;

        // ttclid
        $ttclid = $stored_clicks['ttclid'] ?? '';
        if ( empty( $ttclid ) ) $ttclid = (string) $order->get_meta( '_servertrack_ttclid' );
        if ( empty( $ttclid ) && ! empty( $_COOKIE['ttclid'] ) ) $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) ); // phpcs:ignore
        if ( $ttclid ) $data['ttclid'] = $ttclid;

        // gclid
        $gclid = $stored_clicks['gclid'] ?? '';
        if ( empty( $gclid ) ) $gclid = (string) $order->get_meta( '_servertrack_gclid' );
        if ( empty( $gclid ) && ! empty( $_COOKIE['_gcl_aw'] ) ) $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) ); // phpcs:ignore
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
        // v3.0: stable identity UID
        $data['external_id'] = ServerTrack_Identity::get_external_id_for_order( $order );
        return $data;
    }

    private static function build_purchase_custom_data( WC_Order $order ): array {
        $contents    = [];
        $content_ids = [];
        foreach ( $order->get_items() as $item ) {
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
