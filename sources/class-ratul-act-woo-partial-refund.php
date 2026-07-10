<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_WooPartialRefund  v1.0
 *
 * Sends server-side CAPI events for WooCommerce partial refunds.
 *
 * WHY A SEPARATE CLASS:
 *   The main WooCommerce source (class-ratul-ads-conversion-tracker-woocommerce.php) handles
 *   full refunds via woocommerce_order_status_refunded. That hook fires only
 *   when the entire order is refunded. Partial refunds fire
 *   woocommerce_order_partially_refunded with a separate $refund_id that
 *   contains the exact partial amount via wc_get_order_refund().
 *
 *   Sending the full order total for a partial refund would corrupt ad
 *   platform revenue data. This class extracts the precise partial refund
 *   value from the WC_Order_Refund object.
 *
 * PLATFORM EVENT MAPPING:
 *   Meta   → Purchase with negative value (same as full refund pattern)
 *   TikTok → PlaceAnOrder with negative value
 *   Google → refund event with negative value
 *
 * DEDUP STRATEGY:
 *   Options-based string keys (Dedup::exists / Dedup::set).
 *   Key format: partial_refund_{refund_id}_{platform}
 *   Keyed on refund_id (not order_id) so each partial refund is independently
 *   deduped. An order can have multiple partial refunds.
 *
 * ASYNC:
 *   Dispatched via WP-Cron. The hook fires in an admin context (order edit
 *   screen) so we must not block the request.
 *
 * @package Ratul_ACT
 * @since   5.0.0
 */
class Ratul_ACT_WooPartialRefund {

    public static function init(): void {
        if ( ! get_option( 'ratul_act_source_partial_refund_enabled', 1 ) ) {
            return;
        }
        if ( ! get_option( 'ratul_act_enabled', 1 ) ) {
            return;
        }

        add_action(
            'woocommerce_order_partially_refunded',
            [ self::class, 'on_partial_refund' ],
            10, 2
        );

        add_action(
            'ratul_act_send_partial_refund',
            [ self::class, 'send_partial_refund_async' ],
            10, 2
        );
    }

    // ── Hook handler ─────────────────────────────────────────────────────────

    /**
     * Fired by woocommerce_order_partially_refunded.
     *
     * @param int $order_id
     * @param int $refund_id  ID of the new WC_Order_Refund object
     */
    public static function on_partial_refund( int $order_id, int $refund_id ): void {
        if ( ! get_option( 'ratul_act_enabled', 1 ) ) {
            return;
        }

        $base_key = 'partial_refund_' . $refund_id;

        // Skip if all enabled platforms already processed this refund
        $meta_done   = ! get_option( 'ratul_act_meta_enabled', 0 )   || Ratul_ACT_Dedup::exists( $base_key . '_meta' );
        $tiktok_done = ! get_option( 'ratul_act_tiktok_enabled', 0 ) || Ratul_ACT_Dedup::exists( $base_key . '_tiktok' );
        $google_done = ! get_option( 'ratul_act_google_enabled', 0 ) || Ratul_ACT_Dedup::exists( $base_key . '_google' );

        if ( $meta_done && $tiktok_done && $google_done ) {
            return;
        }

        Ratul_ACT_Logger::log(
            'queued', 'all',
            'Partial refund #' . $refund_id . ' on order #' . $order_id . ' queued.',
            '', '', $order_id, 'PartialRefund'
        );

        wp_schedule_single_event(
            time(),
            'ratul_act_send_partial_refund',
            [ $order_id, $refund_id ]
        );
        spawn_cron();
    }

    // ── Async cron handler ────────────────────────────────────────────────────

    /**
     * Send the partial refund CAPI event. Called by WP-Cron.
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public static function send_partial_refund_async( int $order_id, int $refund_id ): void {
        if ( ! get_option( 'ratul_act_enabled', 1 ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            Ratul_ACT_Logger::log( 'error', 'all', 'Partial refund: order #' . $order_id . ' not found.', '', '', $order_id, 'PartialRefund' );
            return;
        }

        // Retrieve exact partial refund amount from WC_Order_Refund
        $refund = wc_get_order_refund( $refund_id );
        if ( ! $refund ) {
            Ratul_ACT_Logger::log( 'error', 'all', 'Partial refund: refund #' . $refund_id . ' not found.', '', '', $order_id, 'PartialRefund' );
            return;
        }

        // Refund totals are stored as negative; we negate again to get positive amount
        $refund_amount = abs( (float) $refund->get_amount() );
        if ( $refund_amount <= 0 ) {
            Ratul_ACT_Logger::log( 'skipped', 'all', 'Partial refund #' . $refund_id . ' amount is zero — skipped.', '', '', $order_id, 'PartialRefund' );
            return;
        }

        $base_key    = 'partial_refund_' . $refund_id;
        $event_id    = Ratul_ACT_Dedup::generate_event_id( $base_key );
        $user_data   = self::build_user_data( $order );
        $custom_data = [
            'currency'     => $order->get_currency(),
            'value'        => -1 * $refund_amount,  // negative to signal refund
            'content_name' => 'PartialRefund',
            'order_id'     => $order_id,
            'refund_id'    => $refund_id,
            'content_type' => 'product',
            '_dedup_key'   => $base_key,
        ];
        $emq = Ratul_ACT_MatchQuality::score( $user_data );

        // ── META ─────────────────────────────────────────────────────────────
        if ( get_option( 'ratul_act_meta_enabled', 0 )
            && ! Ratul_ACT_Dedup::exists( $base_key . '_meta' )
            && Ratul_ACT_Consent::is_granted( 'meta', $order_id ) ) {
            $e = ( new Ratul_ACT_Event( 'Purchase', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = Ratul_ACT_Meta::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                Ratul_ACT_Dedup::set( $base_key . '_meta' );
            } else {
                Ratul_ACT_Retry::maybe_queue( 'meta', $r, Ratul_ACT_Retry::event_to_args( $e ) );
            }
            Ratul_ACT_Logger::log( $r['status'] ?? 'error', 'meta', 'PartialRefund #' . $refund_id . ' / order #' . $order_id, '', $event_id, $order_id, 'PartialRefund', $emq );
        }

        // ── TIKTOK ───────────────────────────────────────────────────────────
        if ( get_option( 'ratul_act_tiktok_enabled', 0 )
            && ! Ratul_ACT_Dedup::exists( $base_key . '_tiktok' )
            && Ratul_ACT_Consent::is_granted( 'tiktok', $order_id ) ) {
            $e = ( new Ratul_ACT_Event( 'PlaceAnOrder', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = Ratul_ACT_TikTok::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                Ratul_ACT_Dedup::set( $base_key . '_tiktok' );
            } else {
                Ratul_ACT_Retry::maybe_queue( 'tiktok', $r, Ratul_ACT_Retry::event_to_args( $e ) );
            }
            Ratul_ACT_Logger::log( $r['status'] ?? 'error', 'tiktok', 'PartialRefund #' . $refund_id . ' / order #' . $order_id, '', $event_id, $order_id, 'PartialRefund', $emq );
        }

        // ── GOOGLE ───────────────────────────────────────────────────────────
        if ( get_option( 'ratul_act_google_enabled', 0 )
            && ! Ratul_ACT_Dedup::exists( $base_key . '_google' )
            && Ratul_ACT_Consent::is_granted( 'google', $order_id ) ) {
            $e = ( new Ratul_ACT_Event( 'refund', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = Ratul_ACT_Google::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                Ratul_ACT_Dedup::set( $base_key . '_google' );
            } else {
                Ratul_ACT_Retry::maybe_queue( 'google', $r, Ratul_ACT_Retry::event_to_args( $e ) );
            }
            Ratul_ACT_Logger::log( $r['status'] ?? 'error', 'google', 'PartialRefund #' . $refund_id . ' / order #' . $order_id, '', $event_id, $order_id, 'PartialRefund', $emq );
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build hashed user_data from a WC order (matches WooOrderStatus pattern).
     *
     * @param WC_Order $order
     * @return array
     */
    private static function build_user_data( WC_Order $order ): array {
        static $cc_map = [
            'US'=>'1','CA'=>'1','GB'=>'44','AU'=>'61','DE'=>'49','FR'=>'33',
            'IT'=>'39','ES'=>'34','NL'=>'31','SE'=>'46','NO'=>'47','DK'=>'45',
            'FI'=>'358','CH'=>'41','AT'=>'43','IE'=>'353','NZ'=>'64','ZA'=>'27',
            'IN'=>'91','BR'=>'55','BD'=>'880','PK'=>'92','NG'=>'234','MX'=>'52',
            'JP'=>'81','KR'=>'82','SG'=>'65','MY'=>'60','TH'=>'66','PH'=>'63',
            'ID'=>'62','VN'=>'84','HK'=>'852','TW'=>'886','AE'=>'971','SA'=>'966',
        ];

        $data = [];

        $ip = (string) $order->get_customer_ip_address();
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = $ip;

        $ua = $order->get_customer_user_agent();
        if ( $ua ) $data['user_agent'] = $ua;

        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = Ratul_ACT_Hasher::hash_email( $email );

        $phone   = $order->get_billing_phone();
        $country = strtoupper( (string) $order->get_billing_country() );
        if ( $phone ) {
            $cc            = $cc_map[ $country ] ?? '';
            $data['phone'] = Ratul_ACT_Hasher::hash_phone( $phone, $cc );
        }

        foreach ( [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'zip'        => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ] as $key => $val ) {
            if ( $val ) $data[ $key ] = Ratul_ACT_Hasher::hash( (string) $val );
        }

        $stored_ext      = (string) $order->get_meta( '_ratul_act_external_id' );
        $data['external_id'] = ! empty( $stored_ext )
            ? $stored_ext
            : Ratul_ACT_Identity::get_external_id_for_order( $order );

        return $data;
    }
}

