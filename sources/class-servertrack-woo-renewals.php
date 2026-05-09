<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooRenewals
 *
 * Handles WooCommerce Subscriptions renewal orders.
 *
 * Background:
 *   When a subscription renews, WooCommerce Subscriptions creates a new
 *   child order and marks it complete server-side. There is no browser
 *   session and no thank-you page hit, so the standard on_thankyou() path
 *   in ServerTrack_WooCommerce is never reached for these orders.
 *
 *   This class hooks into `woocommerce_subscription_renewal_payment_complete`
 *   which fires after the payment gateway confirms the renewal charge.
 *   It sends a server-side Purchase event to all enabled platforms
 *   using the renewal child order data, fully async via WP-Cron.
 *
 * Dedup:
 *   Renewal events use the child order ID as the dedup key, not the
 *   parent subscription ID. This allows multiple renewals for the same
 *   subscription to be tracked independently.
 *
 * Note:
 *   The parent on_thankyou() guard in ServerTrack_WooCommerce already
 *   checks for `_subscription_renewal` meta and logs + returns early,
 *   so there is no risk of double-counting the initial subscription order.
 */
class ServerTrack_WooRenewals {

    public static function init() {
        if ( ! get_option( 'servertrack_source_woo_enabled', 1 ) ) return;

        // Only register if WooCommerce Subscriptions is active
        if ( ! class_exists( 'WC_Subscriptions' ) ) return;

        // Fires after gateway confirms renewal payment
        add_action(
            'woocommerce_subscription_renewal_payment_complete',
            [ self::class, 'on_renewal_complete' ],
            10, 2
        );

        // Async cron handler
        add_action(
            'servertrack_send_renewal_purchase',
            [ self::class, 'send_renewal_async' ],
            10, 1
        );
    }

    /**
     * Fires synchronously after renewal payment confirmed.
     * Seeds event_id and schedules the async cron — no API calls here.
     *
     * @param WC_Subscription $subscription  The subscription object.
     * @param WC_Order        $renewal_order  The new renewal child order.
     */
    public static function on_renewal_complete( $subscription, $renewal_order ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $renewal_order_id = $renewal_order->get_id();

        // Guard: do not double-fire if already sent
        if ( ServerTrack_Dedup::was_sent( $renewal_order_id, 'meta' )
            || ServerTrack_Dedup::was_sent( $renewal_order_id, 'google' )
            || ServerTrack_Dedup::was_sent( $renewal_order_id, 'tiktok' ) ) {
            ServerTrack_Logger::log(
                'dedup_blocked', 'all',
                'Renewal order #' . $renewal_order_id . ' already sent — skipping.',
                '', ServerTrack_Dedup::get_event_id( $renewal_order_id ), $renewal_order_id, 'Purchase'
            );
            return;
        }

        // Generate + store event_id synchronously before scheduling
        $event_id = ServerTrack_Dedup::generate_event_id( 'renewal_' . $renewal_order_id );
        ServerTrack_Dedup::store_event_id( $renewal_order_id, $event_id );

        ServerTrack_Logger::log(
            'queued', 'all',
            'Subscription renewal order #' . $renewal_order_id . ' queued for server-side tracking.',
            '', $event_id, $renewal_order_id, 'Purchase'
        );

        wp_schedule_single_event(
            time(),
            'servertrack_send_renewal_purchase',
            [ $renewal_order_id ]
        );
    }

    /**
     * Async cron handler — builds event from the renewal order and sends.
     *
     * @param int $renewal_order_id  The renewal child order ID.
     */
    public static function send_renewal_async( int $renewal_order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $order = wc_get_order( $renewal_order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log(
                'error', 'all',
                'Renewal order #' . $renewal_order_id . ' not found in cron.',
                '', '', $renewal_order_id, 'Purchase'
            );
            return;
        }

        $event_id = ServerTrack_Dedup::get_event_id( $renewal_order_id );

        // Build user data from the renewal order (no browser session available)
        $user_data   = self::build_renewal_user_data( $order );
        $custom_data = self::build_renewal_custom_data( $order );

        // ── Meta ────────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'meta' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_Meta::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'meta' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }

        // ── Google ─────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'google' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_Google::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'google' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }

        // ── TikTok ────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'tiktok' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                    ->set_user_data( $user_data )
                    ->set_custom_data( $custom_data );
                $result = ServerTrack_TikTok::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'tiktok' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }
    }

    // ── Helper: user data for renewal order (PII only — no browser signals) ────

    private static function build_renewal_user_data( WC_Order $order ): array {
        $data = [];

        $email = $order->get_billing_email();
        if ( ! empty( $email ) ) $data['email'] = ServerTrack_Hasher::hash_email( $email );

        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) $data['phone'] = ServerTrack_Hasher::hash_phone( $phone );

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

        // Raw geo fields for Google (unhashed)
        $raw_map = [
            'city_raw'    => $order->get_billing_city(),
            'state_raw'   => $order->get_billing_state(),
            'zip_raw'     => $order->get_billing_postcode(),
            'country_raw' => $order->get_billing_country(),
        ];
        foreach ( $raw_map as $key => $val ) {
            if ( ! empty( $val ) ) $data[ $key ] = $val;
        }

        // IP from the original order record (no live session in renewal)
        $order_ip = $order->get_customer_ip_address();
        if ( ! empty( $order_ip ) ) $data['ip'] = $order_ip;

        return $data;
    }

    // ── Helper: custom data for renewal order ──────────────────────────────

    private static function build_renewal_custom_data( WC_Order $order ): array {
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
