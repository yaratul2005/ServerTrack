<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Subscriptions  v1.1
 *
 * Feature #4 — WooCommerce Subscriptions CAPI Events.
 *
 * BUG-01 FIX (v1.1):
 *   Dedup::get_event_id() / store_event_id() internally call wc_get_order($id)
 *   which requires an integer order ID. Passing a string key like
 *   'renewal_123_456' caused wc_get_order() to return false, so event IDs were
 *   never stored or retrieved — a fresh UUID was generated on every call,
 *   breaking pixel/CAPI dedup for ALL subscription events (renewals,
 *   cancellations, pauses).
 *
 *   Fix: switch all dedup calls to the string-safe options-based API:
 *     - Dedup::get( $key )          — replaces get_event_id( $key )
 *     - Dedup::set( $key, $value )  — replaces store_event_id( $key, $value )
 *     - Dedup::was_sent( $key, $platform )   — unchanged signature, now backed by options
 *     - Dedup::mark_as_sent( $key, $platform ) — unchanged signature, now backed by options
 *
 *   These methods accept any string key, store in wp_options, and never touch
 *   order meta — making them safe for subscription-scoped dedup keys.
 *
 * Events handled:
 *   1. Renewal  → Purchase (Meta, TikTok, Google)
 *   2. Cancelled → SubscriptionCancelled (Meta), refund (Google), PlaceAnOrder neg (TikTok)
 *   3. Paused   → SubscriptionPaused (Meta only)
 */
class ServerTrack_Subscriptions {

    public static function init(): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! class_exists( 'WC_Subscriptions' ) ) return;

        add_action(
            'woocommerce_subscription_renewal_payment_complete',
            [ self::class, 'on_renewal' ], 10, 2
        );
        add_action(
            'woocommerce_subscription_status_cancelled',
            [ self::class, 'on_cancelled' ], 10, 1
        );
        add_action(
            'woocommerce_subscription_status_on-hold',
            [ self::class, 'on_paused' ], 10, 1
        );

        // Async cron handlers
        add_action( 'servertrack_send_sub_renewal',   [ self::class, 'send_renewal_async' ],   10, 2 );
        add_action( 'servertrack_send_sub_cancelled', [ self::class, 'send_cancelled_async' ], 10, 1 );
        add_action( 'servertrack_send_sub_paused',    [ self::class, 'send_paused_async' ],    10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HOOKS
    // ────────────────────────────────────────────────────────────────────────

    public static function on_renewal( WC_Subscription $subscription, WC_Order $renewal_order ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $sub_id = $subscription->get_id();
        $ord_id = $renewal_order->get_id();
        wp_schedule_single_event( time(), 'servertrack_send_sub_renewal', [ $sub_id, $ord_id ] );
        spawn_cron();
    }

    public static function on_cancelled( WC_Subscription $subscription ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        wp_schedule_single_event( time(), 'servertrack_send_sub_cancelled', [ $subscription->get_id() ] );
        spawn_cron();
    }

    public static function on_paused( WC_Subscription $subscription ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) ) return;
        wp_schedule_single_event( time(), 'servertrack_send_sub_paused', [ $subscription->get_id() ] );
        spawn_cron();
    }

    // ────────────────────────────────────────────────────────────────────────
    // ASYNC CRON HANDLERS
    // ────────────────────────────────────────────────────────────────────────

    public static function send_renewal_async( int $sub_id, int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'Renewal: order #' . $order_id . ' not found.', '', '', $order_id, 'Renewal' );
            return;
        }

        /*
         * BUG-01 FIX: Use string-safe options-based dedup.
         * Dedup::get() / Dedup::set() accept any string key and store in
         * wp_options. They never call wc_get_order(), so a string like
         * 'renewal_123_456' works correctly.
         */
        $dedup_key = 'renewal_' . $sub_id . '_' . $order_id;
        $event_id  = ServerTrack_Dedup::get( $dedup_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
            ServerTrack_Dedup::set( $dedup_key, $event_id );
        }

        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_renewal_custom_data( $order, $sub_id );

        // META
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'meta' )
            && ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }

        // TIKTOK
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'tiktok' )
            && ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }

        // GOOGLE
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'google' )
            && ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }
    }

    public static function send_cancelled_async( int $sub_id ): void {
        $subscription = wcs_get_subscription( $sub_id );
        if ( ! $subscription ) return;

        /*
         * BUG-01 FIX: string-safe options-based dedup (same as send_renewal_async).
         */
        $dedup_key = 'sub_cancelled_' . $sub_id;
        $event_id  = ServerTrack_Dedup::get( $dedup_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
            ServerTrack_Dedup::set( $dedup_key, $event_id );
        }

        $order_id  = $subscription->get_last_order( 'ids' ) ?: 0;
        $user_data = self::build_order_user_data( $subscription );
        $custom    = [
            'currency'        => $subscription->get_currency(),
            'value'           => (float) $subscription->get_total(),
            'content_name'    => 'Subscription Cancelled',
            'content_type'    => 'product',
            'subscription_id' => $sub_id,
        ];

        // META: custom event 'SubscriptionCancelled'
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'meta' ) ) {
            $e      = ( new ServerTrack_Event( 'SubscriptionCancelled', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }

        // TIKTOK: BUG-05 FIX — TikTok cancellation was entirely missing.
        // Uses PlaceAnOrder with negative value (same approach as Google refund)
        // to signal a lost revenue event on the TikTok side.
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'tiktok' ) ) {
            $neg    = array_merge( $custom, [ 'value' => -1 * abs( $custom['value'] ) ] );
            $e      = ( new ServerTrack_Event( 'PlaceAnOrder', $event_id ) )->set_user_data( $user_data )->set_custom_data( $neg );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }

        // GOOGLE: negative-value refund event
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'google' ) ) {
            $neg    = array_merge( $custom, [ 'value' => -1 * abs( $custom['value'] ) ] );
            $e      = ( new ServerTrack_Event( 'refund', $event_id ) )->set_user_data( $user_data )->set_custom_data( $neg );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }
    }

    public static function send_paused_async( int $sub_id ): void {
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) ) return;
        $subscription = wcs_get_subscription( $sub_id );
        if ( ! $subscription ) return;

        /*
         * BUG-01 FIX: string-safe options-based dedup.
         */
        $dedup_key = 'sub_paused_' . $sub_id;
        if ( ServerTrack_Dedup::was_sent( $dedup_key, 'meta' ) ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
        ServerTrack_Dedup::set( $dedup_key, $event_id );

        $user_data = self::build_order_user_data( $subscription );
        $custom    = [
            'currency'        => $subscription->get_currency(),
            'value'           => (float) $subscription->get_total(),
            'content_name'    => 'Subscription Paused',
            'subscription_id' => $sub_id,
        ];
        $e      = ( new ServerTrack_Event( 'SubscriptionPaused', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom );
        $result = ServerTrack_Meta::send( $e );
        if ( ( $result['status'] ?? '' ) === 'success' ) {
            ServerTrack_Dedup::mark_as_sent( $dedup_key, 'meta' );
        } else {
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
        }
        ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Sub paused #' . $sub_id, '', $event_id, 0, 'SubscriptionPaused' );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Build hashed user_data from a WC order or subscription object.
     *
     * Minor fix: hash_phone() now receives the billing country so the
     * phone hasher can apply the correct E.164 normalisation per-country.
     */
    private static function build_order_user_data( WC_Abstract_Order $order ): array {
        $data    = [];
        $country = method_exists( $order, 'get_billing_country' ) ? (string) $order->get_billing_country() : '';

        $ip = method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '';
        if ( $ip ) $data['ip'] = $ip;

        $ua = method_exists( $order, 'get_customer_user_agent' ) ? $order->get_customer_user_agent() : '';
        if ( $ua ) $data['user_agent'] = $ua;

        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = ServerTrack_Hasher::hash_email( $email );

        $phone = $order->get_billing_phone();
        // M-1 minor fix: pass actual country instead of empty string.
        if ( $phone ) $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $country );

        foreach ( [ 'first_name', 'last_name', 'city', 'state', 'zip', 'country' ] as $field ) {
            $method = 'get_billing_' . $field;
            $val    = method_exists( $order, $method ) ? $order->$method() : '';
            if ( $val ) $data[ $field ] = ServerTrack_Hasher::hash( $val );
        }

        $data['external_id'] = ServerTrack_Identity::get_external_id_for_order( $order );
        return $data;
    }

    private static function build_renewal_custom_data( WC_Order $order, int $sub_id ): array {
        $contents    = [];
        $content_ids = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_product_id();
            $qty     = (int) $item->get_quantity();
            $price   = $qty > 0 ? round( (float) $item->get_total() / $qty, 2 ) : 0.0;
            $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $price ];
            $content_ids[] = $sku;
        }
        return [
            'currency'        => $order->get_currency(),
            'value'           => (float) $order->get_total(),
            'contents'        => $contents,
            'content_ids'     => $content_ids,
            'content_type'    => 'product',
            'order_id'        => $order->get_id(),
            'num_items'       => count( $contents ),
            'content_name'    => 'Subscription Renewal',
            'subscription_id' => $sub_id,
        ];
    }
}
