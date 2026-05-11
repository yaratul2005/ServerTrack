<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooAbandonment  v1.0
 *
 * Cart abandonment tracking for WooCommerce.
 *
 * How it works:
 *   1. on_cart_updated() fires whenever a customer adds or changes items in
 *      their WooCommerce cart. It records a "pending abandonment" record in
 *      the servertrack_pending_abandonments option keyed by session hash.
 *      The record contains the cart snapshot, user_data, and the timestamp
 *      of the last update.
 *
 *   2. A WP-Cron job (servertrack_check_abandonments) runs every 15 minutes.
 *      For each pending record whose last_updated timestamp is older than the
 *      abandonment window (default: 60 minutes), it fires an AbandonedCart
 *      CAPI event and removes the record.
 *
 *   3. on_order_placed() fires on woocommerce_checkout_order_created. It
 *      removes the session's pending abandonment record immediately so that
 *      completed purchases are never counted as abandonments.
 *
 *   4. on_order_placed() also fires on woocommerce_cart_emptied to handle
 *      manual cart clears.
 *
 * CAPI event mapping:
 *   - Meta:   InitiateCheckout (highest signal for cart abandonment)
 *   - TikTok: InitiateCheckout
 *   - Google: begin_checkout
 *
 * Dedup:
 *   Each pending record has a unique event_id generated at recording time.
 *   If the cron fires twice for the same record, the second fire hits the
 *   dedup guard and returns early.
 *
 * Abandonment window:
 *   Defaults to 60 minutes. Filterable via
 *   `servertrack_abandonment_window` filter (value in minutes).
 *
 * Options:
 *   servertrack_source_abandonment_enabled  — 0/1, default 0 (opt-in)
 *   servertrack_abandonment_window_minutes  — int, default 60
 */
class ServerTrack_WooAbandonment {

    /** Option key for the pending abandonment records store. */
    const STORE_KEY = 'servertrack_pending_abandonments';

    /** Cron hook name. */
    const CRON_HOOK = 'servertrack_check_abandonments';

    public static function init() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_source_abandonment_enabled', 0 ) ) return;

        // Record cart state whenever cart is updated
        add_action( 'woocommerce_cart_updated',          [ self::class, 'on_cart_updated' ] );
        // Remove abandonment record when order is placed
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'on_order_placed' ], 10, 1 );
        // Remove abandonment record when cart is emptied manually
        add_action( 'woocommerce_cart_emptied',           [ self::class, 'on_cart_emptied' ] );
        // Cron handler
        add_action( self::CRON_HOOK,                      [ self::class, 'process_abandonments' ] );

        // Schedule the 15-minute cron if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'servertrack_15min', self::CRON_HOOK );
        }

        // Register custom 15-minute cron interval
        add_filter( 'cron_schedules', [ self::class, 'add_cron_interval' ] );
    }

    public static function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['servertrack_15min'] ) ) {
            $schedules['servertrack_15min'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 minutes (ServerTrack)', 'servertrack' ),
            ];
        }
        return $schedules;
    }

    // ────────────────────────────────────────────────────────────────────────
    // RECORD CART STATE
    // ────────────────────────────────────────────────────────────────────────

    public static function on_cart_updated() {
        if ( ! WC()->session || ! WC()->cart ) return;

        $session_id = (string) WC()->session->get_customer_id();
        if ( empty( $session_id ) ) return;

        $cart = WC()->cart;
        if ( $cart->is_empty() ) {
            // Empty cart — remove any existing pending record
            self::remove_pending( $session_id );
            return;
        }

        $contents    = [];
        $total_value = 0.0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product instanceof WC_Product ) continue;
            $sku         = $product->get_sku() ?: (string) $cart_item['product_id'];
            $qty         = (int) $cart_item['quantity'];
            $item_price  = (float) wc_get_price_to_display( $product );
            $total_value += $item_price * $qty;
            $contents[]  = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $item_price ];
        }

        if ( empty( $contents ) ) return;

        $user_data = self::build_session_user_data();

        // Re-use existing event_id if we already have a record for this session
        // to avoid generating a new id on every cart update
        $existing = self::get_pending( $session_id );
        $event_id = ! empty( $existing['event_id'] )
            ? $existing['event_id']
            : ServerTrack_Dedup::generate_event_id( 'abandon_' . $session_id . '_' . time() );

        self::upsert_pending( $session_id, [
            'event_id'     => $event_id,
            'session_id'   => $session_id,
            'user_data'    => $user_data,
            'contents'     => $contents,
            'value'        => round( $total_value, 2 ),
            'currency'     => get_woocommerce_currency(),
            'last_updated' => time(),
            'sent'         => false,
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // REMOVE RECORD WHEN ORDER PLACED
    // ────────────────────────────────────────────────────────────────────────

    public static function on_order_placed( WC_Order $order ) {
        if ( ! WC()->session ) return;
        $session_id = (string) WC()->session->get_customer_id();
        if ( ! empty( $session_id ) ) {
            self::remove_pending( $session_id );
        }
    }

    public static function on_cart_emptied() {
        if ( ! WC()->session ) return;
        $session_id = (string) WC()->session->get_customer_id();
        if ( ! empty( $session_id ) ) {
            self::remove_pending( $session_id );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // CRON: PROCESS ABANDONMENTS
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Runs every 15 minutes. Finds pending records older than the abandonment
     * window, fires CAPI events, and removes processed records.
     */
    public static function process_abandonments() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_source_abandonment_enabled', 0 ) ) return;

        /**
         * Abandonment window in minutes.
         * Default: 60. Override via `servertrack_abandonment_window` filter.
         *
         * @param int $window_minutes
         */
        $window_minutes = (int) apply_filters(
            'servertrack_abandonment_window',
            (int) get_option( 'servertrack_abandonment_window_minutes', 60 )
        );
        $window_seconds = $window_minutes * MINUTE_IN_SECONDS;
        $now            = time();

        $pending = get_option( self::STORE_KEY, [] );
        if ( ! is_array( $pending ) || empty( $pending ) ) return;

        $meta_on   = (bool) get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = (bool) get_option( 'servertrack_tiktok_enabled', 0 );
        $google_on = (bool) get_option( 'servertrack_google_enabled', 0 );

        if ( ! $meta_on && ! $tiktok_on && ! $google_on ) return;

        $changed = false;
        foreach ( $pending as $session_id => $record ) {
            // Skip if already sent
            if ( ! empty( $record['sent'] ) ) {
                unset( $pending[ $session_id ] );
                $changed = true;
                continue;
            }

            // Skip if within the abandonment window
            $age = $now - (int) ( $record['last_updated'] ?? $now );
            if ( $age < $window_seconds ) continue;

            // Fire the CAPI event
            self::fire_abandonment_event( $record, $meta_on, $tiktok_on, $google_on );

            // Remove the record regardless of send success
            // (retry will handle failures; we don't want to spam on next cron run)
            unset( $pending[ $session_id ] );
            $changed = true;
        }

        if ( $changed ) {
            update_option( self::STORE_KEY, $pending, false );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // SEND CAPI EVENT
    // ────────────────────────────────────────────────────────────────────────

    private static function fire_abandonment_event(
        array $record,
        bool  $meta_on,
        bool  $tiktok_on,
        bool  $google_on
    ) {
        $event_id  = $record['event_id']  ?? ServerTrack_Dedup::generate_event_id();
        $user_data = $record['user_data'] ?? [];
        $contents  = $record['contents']  ?? [];
        $value     = (float) ( $record['value']    ?? 0.0 );
        $currency  = (string) ( $record['currency'] ?? get_woocommerce_currency() );

        $custom_data = [
            'currency'     => $currency,
            'value'        => $value,
            'contents'     => $contents,
            'content_ids'  => array_column( $contents, 'id' ),
            'content_type' => 'product',
            'num_items'    => count( $contents ),
        ];

        ServerTrack_Logger::log(
            'queued', 'all',
            'Cart abandonment detected — firing AbandonedCart CAPI event.',
            '', $event_id, 0, 'AbandonedCart'
        );

        // ── META: InitiateCheckout ───────────────────────────────────────────
        if ( $meta_on ) {
            $e = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }

        // ── TIKTOK: InitiateCheckout ─────────────────────────────────────────
        if ( $tiktok_on ) {
            $e = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }

        // ── GOOGLE: begin_checkout ───────────────────────────────────────────
        if ( $google_on ) {
            $e = ( new ServerTrack_Event( 'begin_checkout', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function build_session_user_data(): array {
        $data = [];
        $ip   = '';
        if ( class_exists( 'WC_Geolocation' ) ) {
            $ip = WC_Geolocation::get_ip_address();
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = sanitize_text_field( $ip );

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
            if ( $user->user_email ) {
                $data['email']       = ServerTrack_Hasher::hash_email( $user->user_email );
                $data['external_id'] = ServerTrack_Hasher::hash( (string) $user->ID );
            }
        }

        return $data;
    }

    // ── Persistent store helpers (option-based, HPOS-safe) ──────────────────

    private static function get_pending( string $session_id ): array {
        $store = get_option( self::STORE_KEY, [] );
        return is_array( $store ) ? ( $store[ $session_id ] ?? [] ) : [];
    }

    private static function upsert_pending( string $session_id, array $record ) {
        $store = get_option( self::STORE_KEY, [] );
        if ( ! is_array( $store ) ) $store = [];
        $store[ $session_id ] = $record;
        update_option( self::STORE_KEY, $store, false );
    }

    private static function remove_pending( string $session_id ) {
        $store = get_option( self::STORE_KEY, [] );
        if ( ! is_array( $store ) || ! isset( $store[ $session_id ] ) ) return;
        unset( $store[ $session_id ] );
        update_option( self::STORE_KEY, $store, false );
    }
}
