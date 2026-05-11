<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Source_WooCommerce  v3.3
 *
 * Hooks into WooCommerce to fire CAPI events for all purchase lifecycle
 * stages.  Each feature can be toggled independently from the Event Sources
 * settings page (servertrack-sources).
 *
 * Changelog
 * ----------
 * v3.3  2026-05-11
 *   + Order Status Events  – fires Lead/Contact/SubmitForm when an order
 *     moves to on-hold, failed, or cancelled.  Guarded by:
 *     servertrack_source_order_status_enabled  (default: 1)
 *
 *   + AddToWishlist Events – fires AddToWishlist to Meta & TikTok when a
 *     customer adds a product to their wishlist via YITH WooCommerce Wishlist
 *     or TI WooCommerce Wishlist.  Guarded by:
 *     servertrack_source_wishlist_enabled  (default: 0, opt-in)
 *
 *   + Partial Refund Events – fires a Purchase event with a *negative* value
 *     equal to the exact partial refund amount, separate from the full-Refund
 *     event.  Guarded by:
 *     servertrack_source_partial_refund_enabled  (default: 1)
 *
 * v3.2  (prior)
 *   + Subscription Renewal events (Refund, Renewal)
 *   + Cart Abandonment integration
 *
 * v3.0 – v3.1  (prior)
 *   Purchase, ViewContent, AddToCart, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, Refund
 */
class ServerTrack_Source_WooCommerce {

    // ── Option defaults ────────────────────────────────────────────────────
    private static function opt( string $key, $default = 0 ) {
        return get_option( $key, $default );
    }

    // ══════════════════════════════════════════════════════════════════════
    // BOOTSTRAP
    // ══════════════════════════════════════════════════════════════════════

    public static function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // ── Core WooCommerce hooks (enabled via servertrack_source_woo_enabled) ──
        if ( self::opt( 'servertrack_source_woo_enabled', 1 ) ) {
            self::register_core_hooks();
        }

        // ── v3.3: Order Status Events ──────────────────────────────────────
        if ( self::opt( 'servertrack_source_order_status_enabled', 1 ) ) {
            add_action( 'woocommerce_order_status_changed', [ self::class, 'handle_order_status_change' ], 10, 4 );
        }

        // ── v3.3: AddToWishlist Events ─────────────────────────────────────
        if ( self::opt( 'servertrack_source_wishlist_enabled', 0 ) ) {
            // YITH WooCommerce Wishlist
            add_action( 'yith_wcwl_added_to_wishlist',  [ self::class, 'handle_add_to_wishlist' ], 10, 2 );
            // TI WooCommerce Wishlist
            add_action( 'ti_wl_add_to_wishlist',        [ self::class, 'handle_add_to_wishlist_ti' ], 10, 2 );
        }

        // ── v3.3: Partial Refund Events ────────────────────────────────────
        if ( self::opt( 'servertrack_source_partial_refund_enabled', 1 ) ) {
            // woocommerce_order_refunded fires for ALL refund types;
            // we filter to partial-only by comparing refund amount vs order total.
            add_action( 'woocommerce_order_refunded', [ self::class, 'handle_partial_refund' ], 10, 2 );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // CORE HOOKS (unchanged from v3.2)
    // ══════════════════════════════════════════════════════════════════════

    private static function register_core_hooks(): void {
        add_action( 'woocommerce_payment_complete',                    [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_order_status_completed',              [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_order_status_processing',             [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_add_to_cart',                         [ self::class, 'handle_add_to_cart' ],           10, 6 );
        add_action( 'woocommerce_before_checkout_form',                [ self::class, 'handle_initiate_checkout' ],     10    );
        add_action( 'woocommerce_checkout_order_processed',            [ self::class, 'handle_add_payment_info' ],      10, 1 );
        add_action( 'woocommerce_created_customer',                    [ self::class, 'handle_complete_registration' ], 10, 1 );
        add_action( 'woocommerce_order_fully_refunded',                [ self::class, 'handle_full_refund' ],           10, 2 );
        add_filter( 'woocommerce_thankyou',                            [ self::class, 'handle_view_content' ],          10, 1 );
    }

    // ══════════════════════════════════════════════════════════════════════
    // v3.3 ── ORDER STATUS EVENTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fires when an order transitions to on-hold, failed, or cancelled.
     *
     * Maps WC status → CAPI event name:
     *   on-hold   → Lead      (buyer intent captured, awaiting payment)
     *   failed    → Contact   (buyer attempted checkout but payment failed)
     *   cancelled → SubmitForm (order was cancelled – useful for win-back)
     *
     * Dedup key: "order_status_{$order_id}_{$new_status}"
     * This prevents the same status→event from firing twice if WC fires
     * the hook more than once (e.g., during webhook replays).
     *
     * @param int       $order_id
     * @param string    $old_status  Previous WC order status
     * @param string    $new_status  New WC order status
     * @param WC_Order  $order
     */
    public static function handle_order_status_change(
        int $order_id,
        string $old_status,
        string $new_status,
        \WC_Order $order
    ): void {
        $status_event_map = [
            'on-hold'   => 'Lead',
            'failed'    => 'Contact',
            'cancelled' => 'SubmitForm',
        ];

        if ( ! isset( $status_event_map[ $new_status ] ) ) {
            return;
        }

        $event_name = $status_event_map[ $new_status ];
        $dedup_key  = "order_status_{$order_id}_{$new_status}";

        // Dedup: skip if this status event was already sent
        foreach ( [ 'meta', 'tiktok', 'google' ] as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                return;
            }
        }

        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = [
            'order_id'    => $order_id,
            'value'       => (float) $order->get_total(),
            'currency'    => get_woocommerce_currency(),
            'order_status'=> $new_status,
            '_dedup_key'  => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( $event_name, $order_id . '_' . $new_status );
        $event    = ( new ServerTrack_Event( $event_name, $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // v3.3 ── ADDTOWISHLIST EVENTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fires when a product is added to a YITH WooCommerce Wishlist.
     *
     * Hook: yith_wcwl_added_to_wishlist( $product_id, $wishlist_id )
     *
     * Platforms: Meta + TikTok only (Google GA4 has no native wishlist event).
     * Dedup key: "wishlist_yith_{$user_or_session}_{$product_id}"
     *
     * @param int $product_id
     * @param int $wishlist_id
     */
    public static function handle_add_to_wishlist( int $product_id, int $wishlist_id ): void {
        self::fire_add_to_wishlist_event( $product_id, 'yith' );
    }

    /**
     * Fires when a product is added to a TI WooCommerce Wishlist.
     *
     * Hook: ti_wl_add_to_wishlist( $product_id, $user_id )
     *
     * @param int $product_id
     * @param int $user_id
     */
    public static function handle_add_to_wishlist_ti( int $product_id, int $user_id ): void {
        self::fire_add_to_wishlist_event( $product_id, 'ti' );
    }

    /**
     * Shared logic for both wishlist integrations.
     *
     * @param int    $product_id
     * @param string $source  'yith' | 'ti'
     */
    private static function fire_add_to_wishlist_event( int $product_id, string $source ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $user_id    = get_current_user_id();
        $session_id = WC()->session ? WC()->session->get_customer_id() : '';
        $uid_part   = $user_id ?: $session_id;
        $dedup_key  = "wishlist_{$source}_{$uid_part}_{$product_id}";

        // Meta & TikTok only
        $platforms = [ 'meta', 'tiktok' ];
        foreach ( $platforms as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                continue;
            }
        }

        $user_data = ServerTrack_Identity::from_current_user();

        $custom_data = [
            'content_ids'  => [ (string) $product_id ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
            '_dedup_key'   => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( 'AddToWishlist', $uid_part . '_' . $product_id );
        $event    = ( new ServerTrack_Event( 'AddToWishlist', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        // Dispatch only to Meta and TikTok
        ServerTrack_Core::dispatch_to_platforms( $event, $platforms, $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // v3.3 ── PARTIAL REFUND EVENTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fires on woocommerce_order_refunded for every refund (full or partial).
     * Delegates full refunds to handle_full_refund() and only processes
     * partial refunds here.
     *
     * A negative-value Purchase event is sent equal to the exact refund
     * amount (NOT the order total).
     *
     * Dedup key: "partial_refund_{$refund_id}" — refund IDs are unique WC
     * post IDs, so this guarantees exactly-once delivery per refund object.
     *
     * @param int $order_id
     * @param int $refund_id  ID of the WC_Order_Refund object
     */
    public static function handle_partial_refund( int $order_id, int $refund_id ): void {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );

        if ( ! $order || ! $refund ) {
            return;
        }

        $refund_amount = abs( (float) $refund->get_amount() );
        $order_total   = (float) $order->get_total();

        // Only handle partial refunds here; full refunds are handled elsewhere.
        // A refund is considered "full" if its amount equals the order total
        // within a 1-cent tolerance (floating-point safety).
        if ( abs( $refund_amount - $order_total ) < 0.01 ) {
            return; // Full refund – skip, handled by handle_full_refund()
        }

        $dedup_key = "partial_refund_{$refund_id}";

        foreach ( [ 'meta', 'tiktok', 'google' ] as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                return;
            }
        }

        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = [
            'order_id'     => $order_id,
            'refund_id'    => $refund_id,
            'value'        => -$refund_amount,   // negative = refund signal
            'currency'     => get_woocommerce_currency(),
            'refund_type'  => 'partial',
            '_dedup_key'   => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( 'Purchase', 'partial_refund_' . $refund_id );
        $event    = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // EXISTING HANDLERS (v3.0 – v3.2, preserved unchanged)
    // ══════════════════════════════════════════════════════════════════════

    public static function handle_purchase( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ServerTrack_Dedup::already_sent( $order_id, 'meta' )
          && ServerTrack_Dedup::already_sent( $order_id, 'tiktok' )
          && ServerTrack_Dedup::already_sent( $order_id, 'google' ) ) {
            return;
        }
        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = ServerTrack_Catalog::from_order( $order );
        $event_id    = ServerTrack_Hasher::event_id( 'Purchase', $order_id );
        $event       = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event, $order_id );
    }

    public static function handle_view_content( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = ServerTrack_Catalog::from_order_summary( $order );
        $event_id    = ServerTrack_Hasher::event_id( 'ViewContent', $order_id );
        $event       = ( new ServerTrack_Event( 'ViewContent', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_add_to_cart( string $cart_item_key, int $product_id, int $quantity ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $user_data   = ServerTrack_Identity::from_current_user();
        $custom_data = [
            'content_ids'  => [ (string) $product_id ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => get_woocommerce_currency(),
            'num_items'    => $quantity,
        ];
        $event_id = ServerTrack_Hasher::event_id( 'AddToCart', $cart_item_key );
        $event    = ( new ServerTrack_Event( 'AddToCart', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_initiate_checkout(): void {
        if ( ! WC()->cart || WC()->cart->is_empty() ) return;
        $user_data   = ServerTrack_Identity::from_current_user();
        $custom_data = ServerTrack_Catalog::from_cart();
        $event_id    = ServerTrack_Hasher::event_id( 'InitiateCheckout', get_current_user_id() . '_' . time() );
        $event       = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_add_payment_info( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = ServerTrack_Catalog::from_order_summary( $order );
        $event_id    = ServerTrack_Hasher::event_id( 'AddPaymentInfo', $order_id );
        $event       = ( new ServerTrack_Event( 'AddPaymentInfo', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_complete_registration( int $customer_id ): void {
        $user_data   = ServerTrack_Identity::from_user_id( $customer_id );
        $event_id    = ServerTrack_Hasher::event_id( 'CompleteRegistration', $customer_id );
        $event       = ( new ServerTrack_Event( 'CompleteRegistration', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( [ 'currency' => get_woocommerce_currency() ] );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_full_refund( int $order_id, int $refund_id ): void {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;
        $dedup_key = "full_refund_{$order_id}";
        if ( ServerTrack_Dedup::already_sent( $dedup_key, 'meta' ) ) return;
        $user_data   = ServerTrack_Identity::from_order( $order );
        $custom_data = [
            'order_id'  => $order_id,
            'value'     => -(float) $order->get_total(),
            'currency'  => get_woocommerce_currency(),
            'refund_type' => 'full',
            '_dedup_key'  => $dedup_key,
        ];
        $event_id = ServerTrack_Hasher::event_id( 'Purchase', 'full_refund_' . $order_id );
        $event    = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }
}
