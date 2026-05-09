<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_WooCommerce {

    public static function init() {
        if ( ! get_option( 'servertrack_source_woo_enabled', 1 ) ) {
            return;
        }

        // ── Purchase signals (async — constraint #7) ────────────────────────
        add_action( 'woocommerce_thankyou',               [ self::class, 'on_thankyou' ],        10, 1 );
        add_action( 'woocommerce_order_status_completed',  [ self::class, 'on_order_completed' ], 10, 1 );

        // ── Refund guard (Section 15) ─────────────────────────────────
        add_action( 'woocommerce_order_status_refunded',   [ self::class, 'on_order_refunded' ],  10, 1 );

        // ── Engagement signals (synchronous — no PII, no checkout timeout risk)
        add_action( 'woocommerce_after_single_product',    [ self::class, 'on_view_content' ] );
        add_action( 'woocommerce_add_to_cart',             [ self::class, 'on_add_to_cart' ],     10, 4 );
        add_action( 'woocommerce_before_checkout_form',    [ self::class, 'on_initiate_checkout' ] );

        // ── Lead: new account registration ───────────────────────────
        add_action( 'woocommerce_created_customer',        [ self::class, 'on_new_customer' ],    10, 3 );

        // ── Async cron handler (constraint #7) ────────────────────────────
        add_action( 'servertrack_send_woo_purchase',       [ self::class, 'send_purchase_async' ], 10, 2 );
    }

    // ── Thank-You Page ────────────────────────────────────────────────────
    public static function on_thankyou( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Section 15 edge case: subscription renewals fire no browser session
        if ( $order->get_meta( '_subscription_renewal' ) ) {
            ServerTrack_Logger::log(
                'skipped', 'all',
                'Subscription renewal — browser session event only. Server event not sent.',
                '', '', $order_id, 'Purchase'
            );
            return;
        }

        // Generate + store event_id BEFORE scheduling — dedup depends on this
        $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
        ServerTrack_Dedup::store_event_id( $order_id, $event_id );

        // Constraint #7: NEVER fire API calls synchronously on checkout page
        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'thankyou' ] );
    }

    // ── Order Completed (Google prefers this status) ─────────────────────
    public static function on_order_completed( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_google_enabled', 0 ) ) return;

        // Constraint #6: check dedup lock before scheduling
        if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'google', 'order_completed dedup block', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }

        wp_schedule_single_event( time(), 'servertrack_send_woo_purchase', [ $order_id, 'completed' ] );
    }

    // ── Refund Guard — Section 15 ───────────────────────────────────────
    public static function on_order_refunded( int $order_id ) {
        ServerTrack_Logger::log(
            'skipped', 'all',
            'Order #' . $order_id . ' refunded. Server event already sent — no reversal sent to platforms.',
            '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase'
        );
        update_post_meta( $order_id, '_servertrack_refunded', true );
    }

    // ── ViewContent ─────────────────────────────────────────────────────────
    public static function on_view_content() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $servertrack_product = wc_get_product( get_queried_object_id() );
        if ( ! $servertrack_product ) return;

        $event_id  = ServerTrack_Dedup::generate_event_id( 'view_' . $servertrack_product->get_id() . '_' . wp_generate_uuid4() );
        $user_data = self::build_browser_user_data();
        $price     = (float) wc_get_price_to_display( $servertrack_product );
        $sku       = $servertrack_product->get_sku() ?: (string) $servertrack_product->get_id();

        $event = new ServerTrack_Event( 'ViewContent', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [
            'currency'    => get_woocommerce_currency(),
            'value'       => $price,
            'contents'    => [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ],
            'content_ids' => [ $sku ],
        ] );

        $timeout_filter = function( $args ) { $args['timeout'] = 3; return $args; };
        add_filter( 'http_request_args', $timeout_filter, 999 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
        }

        remove_filter( 'http_request_args', $timeout_filter, 999 );
    }

    // ── AddToCart ─────────────────────────────────────────────────────────────
    public static function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( 'atc_' . $product_id . '_' . wp_generate_uuid4() );
        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product_id;

        $event = new ServerTrack_Event( 'AddToCart', $event_id );
        $event->set_user_data( self::build_browser_user_data() );
        $event->set_custom_data( [
            'currency' => get_woocommerce_currency(),
            'value'    => $price * $quantity,
            'contents' => [ [ 'id' => $sku, 'quantity' => $quantity, 'item_price' => $price ] ],
        ] );

        $timeout_filter = function( $args ) { $args['timeout'] = 3; return $args; };
        add_filter( 'http_request_args', $timeout_filter, 999 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
        }

        remove_filter( 'http_request_args', $timeout_filter, 999 );
    }

    // ── InitiateCheckout ─────────────────────────────────────────────────────
    public static function on_initiate_checkout() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        if ( ! WC()->cart ) return;

        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : wp_generate_uuid4();
        $event_id   = ServerTrack_Dedup::generate_event_id( 'checkout_' . $session_id . '_' . wp_generate_uuid4() );

        $cart     = WC()->cart;
        $contents = [];
        foreach ( $cart->get_cart() as $cart_item ) {
            $prod = $cart_item['data'];
            if ( ! $prod instanceof WC_Product ) continue;
            $sku        = $prod->get_sku() ?: (string) $cart_item['product_id'];
            $contents[] = [
                'id'         => $sku,
                'quantity'   => $cart_item['quantity'],
                'item_price' => (float) wc_get_price_to_display( $prod ),
            ];
        }

        $event = new ServerTrack_Event( 'InitiateCheckout', $event_id );
        $event->set_user_data( self::build_browser_user_data() );
        $event->set_custom_data( [
            'currency' => get_woocommerce_currency(),
            'value'    => (float) $cart->get_total( 'edit' ),
            'contents' => $contents,
        ] );

        $timeout_filter = function( $args ) { $args['timeout'] = 3; return $args; };
        add_filter( 'http_request_args', $timeout_filter, 999 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
        }

        remove_filter( 'http_request_args', $timeout_filter, 999 );
    }

    // ── Lead: New Customer Registration ─────────────────────────────────
    public static function on_new_customer( int $customer_id, array $new_customer_data, bool $password_generated ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $event_id  = ServerTrack_Dedup::generate_event_id( 'woo_lead_' . $customer_id . '_' . wp_generate_uuid4() );
        $user_data = self::build_browser_user_data();

        $email = $new_customer_data['user_email'] ?? '';
        if ( ! empty( $email ) ) $user_data['email'] = ServerTrack_Hasher::hash_email( $email );
        $first_name = $new_customer_data['first_name'] ?? '';
        if ( ! empty( $first_name ) ) $user_data['first_name'] = ServerTrack_Hasher::hash( $first_name );
        $last_name = $new_customer_data['last_name'] ?? '';
        if ( ! empty( $last_name ) ) $user_data['last_name'] = ServerTrack_Hasher::hash( $last_name );

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [ 'currency' => get_woocommerce_currency(), 'value' => 0.0, 'contents' => [] ] );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
    }

    // ── Async Cron Handler ──────────────────────────────────────────────────
    public static function send_purchase_async( int $order_id, string $trigger ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Section 15: abort if refunded
        if ( get_post_meta( $order_id, '_servertrack_refunded', true ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Aborted — order was refunded.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }

        $event_id    = ServerTrack_Dedup::get_event_id( $order_id );
        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_custom_data( $order );

        // Meta — thankyou trigger
        if ( 'thankyou' === $trigger && get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $order_id, 'meta' ) ) {
                if ( ServerTrack_Consent::is_granted( 'meta' ) ) {
                    $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                    $result = ServerTrack_Meta::send( $e );
                    if ( ( $result['status'] ?? '' ) === 'success' ) {
                        ServerTrack_Dedup::mark_as_sent( $order_id, 'meta' );
                    } else {
                        ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
                    }
                } else {
                    ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent', '', $event_id, $order_id, 'Purchase' );
            }
        }

        // TikTok — thankyou trigger
        if ( 'thankyou' === $trigger && get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $order_id, 'tiktok' ) ) {
                if ( ServerTrack_Consent::is_granted( 'tiktok' ) ) {
                    $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                    $result = ServerTrack_TikTok::send( $e );
                    if ( ( $result['status'] ?? '' ) === 'success' ) {
                        ServerTrack_Dedup::mark_as_sent( $order_id, 'tiktok' );
                    } else {
                        ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
                    }
                } else {
                    ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent', '', $event_id, $order_id, 'Purchase' );
            }
        }

        // Google — completed trigger
        if ( 'completed' === $trigger && get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
                if ( ServerTrack_Consent::is_granted( 'google' ) ) {
                    $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                    $result = ServerTrack_Google::send( $e );
                    if ( ( $result['status'] ?? '' ) === 'success' ) {
                        ServerTrack_Dedup::mark_as_sent( $order_id, 'google' );
                    } else {
                        ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
                    }
                } else {
                    ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $order_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent', '', $event_id, $order_id, 'Purchase' );
            }
        }
    }

    // ── Helper: browser-context user data ──────────────────────────────────
    private static function build_browser_user_data(): array {
        $data = [];
        $ip   = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
        if ( ! empty( $ip ) ) $data['ip'] = $ip;

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( ! empty( $ua ) ) $data['user_agent'] = $ua;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )    $data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )    $data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) )  $data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $data['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        // phpcs:enable

        return $data;
    }

    // ── Helper: full order user data (PII hashed) ───────────────────────────
    private static function build_order_user_data( WC_Order $order ): array {
        $data = self::build_browser_user_data();

        $email = $order->get_billing_email();
        if ( ! empty( $email ) ) $data['email'] = ServerTrack_Hasher::hash_email( $email );

        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) {
            $country_codes = [
                'US' => '1',  'CA' => '1',  'GB' => '44',  'AU' => '61',  'DE' => '49',
                'FR' => '33', 'IT' => '39', 'ES' => '34',  'NL' => '31',  'SE' => '46',
                'NO' => '47', 'DK' => '45', 'FI' => '358', 'CH' => '41',  'AT' => '43',
                'IE' => '353','NZ' => '64', 'ZA' => '27',  'IN' => '91',  'BR' => '55',
            ];
            $country_code  = $country_codes[ $order->get_billing_country() ] ?? '';
            $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $country_code );
        }

        $pii_map = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'zip'        => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ];
        foreach ( $pii_map as $key => $val ) {
            if ( ! empty( $val ) ) $data[ $key ] = ServerTrack_Hasher::hash( $val );
        }

        $raw_map = [
            'city_raw'    => $order->get_billing_city(),
            'state_raw'   => $order->get_billing_state(),
            'zip_raw'     => $order->get_billing_postcode(),
            'country_raw' => $order->get_billing_country(),
        ];
        foreach ( $raw_map as $key => $val ) {
            if ( ! empty( $val ) ) $data[ $key ] = $val;
        }

        $order_ip = $order->get_customer_ip_address();
        if ( ! empty( $order_ip ) ) $data['ip'] = $order_ip;
        $order_ua = $order->get_customer_user_agent();
        if ( ! empty( $order_ua ) ) $data['user_agent'] = $order_ua;

        return $data;
    }

    // ── Helper: order custom data ────────────────────────────────────────────
    private static function build_custom_data( WC_Order $order ): array {
        $contents = [];
        foreach ( $order->get_items() as $item ) {
            $product    = $item->get_product();
            $sku        = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_product_id();
            $qty        = $item->get_quantity();
            $contents[] = [
                'id'         => $sku,
                'quantity'   => $qty,
                'item_price' => $qty > 0 ? (float) $item->get_total() / $qty : 0.0,
            ];
        }
        return [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => $order->get_id(),
        ];
    }
}
