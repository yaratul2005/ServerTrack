<?php
/**
 * ServerTrack — Customer LTV Signal in Purchase Payload (Feature #6)
 *
 * Enriches every Purchase CAPI event with the customer's historical
 * lifetime value and order count, enabling Meta's value-based lookalike
 * audiences and LTV-optimised bidding strategies.
 *
 * Meta uses `predicted_ltv` (custom_data) and `value` differences across
 * events to model customer quality. The more accurate these signals are,
 * the better the algorithm can find high-LTV lookalikes.
 *
 * Data points added to custom_data:
 *   predicted_ltv   — total spend to date (including this order)
 *   order_count     — total completed orders for this customer
 *   customer_type   — 'new' | 'returning'
 *   avg_order_value — predicted_ltv / order_count (rounded 2dp)
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_LTV {

    /**
     * Attach LTV filter to WooCommerce purchase custom_data.
     * Hooked by the plugin bootstrap — not by init() here.
     */
    public static function init(): void {
        // Filter applied when WooCommerce source builds the purchase payload
        add_filter( 'servertrack_purchase_custom_data', [ __CLASS__, 'enrich_custom_data' ], 10, 2 );
    }

    /**
     * Add LTV signals to the purchase custom_data array.
     *
     * @param array    $custom_data  Existing custom_data array
     * @param WC_Order $order        The WooCommerce order
     * @return array
     */
    public static function enrich_custom_data( array $custom_data, WC_Order $order ): array {
        $ltv_data = self::calculate_ltv( $order );

        return array_merge( $custom_data, $ltv_data );
    }

    /**
     * Calculate LTV data for the customer associated with an order.
     *
     * @param WC_Order $order
     * @return array
     */
    public static function calculate_ltv( WC_Order $order ): array {
        $user_id = $order->get_user_id();
        $email   = $order->get_billing_email();

        $historical_value = 0.0;
        $order_count      = 0;

        if ( $user_id > 0 ) {
            // Logged-in customer: query all their completed orders
            $past_orders = wc_get_orders( [
                'customer_id' => $user_id,
                'status'      => [ 'completed', 'processing' ],
                'limit'       => -1,
                'return'      => 'ids',
                'exclude'     => [ $order->get_id() ], // exclude current order from history
            ] );

            foreach ( $past_orders as $past_order_id ) {
                $past_order        = wc_get_order( $past_order_id );
                $historical_value += (float) $past_order->get_total();
                $order_count++;
            }
        } elseif ( $email ) {
            // Guest customer: match by billing email
            $past_orders = wc_get_orders( [
                'billing_email' => $email,
                'status'        => [ 'completed', 'processing' ],
                'limit'         => -1,
                'return'        => 'ids',
                'exclude'       => [ $order->get_id() ],
            ] );

            foreach ( $past_orders as $past_order_id ) {
                $past_order        = wc_get_order( $past_order_id );
                $historical_value += (float) $past_order->get_total();
                $order_count++;
            }
        }

        // predicted_ltv = historical spend + current order value
        $current_value  = (float) $order->get_total();
        $predicted_ltv  = round( $historical_value + $current_value, 2 );
        $total_orders   = $order_count + 1; // include this order
        $avg_order_value = $total_orders > 0
            ? round( $predicted_ltv / $total_orders, 2 )
            : $current_value;

        $customer_type = $order_count > 0 ? 'returning' : 'new';

        $ltv_data = [
            'predicted_ltv'   => $predicted_ltv,
            'order_count'     => $total_orders,
            'customer_type'   => $customer_type,
            'avg_order_value' => $avg_order_value,
        ];

        // Allow stores to override/extend LTV calculation
        return apply_filters( 'servertrack_ltv_data', $ltv_data, $order, $historical_value );
    }

    /**
     * Get LTV stats for a customer by user ID or email.
     * Useful for dashboard display or external CRM integrations.
     *
     * @param int    $user_id
     * @param string $email
     * @return array { total_spend, order_count, avg_order_value, first_order_date, last_order_date }
     */
    public static function get_customer_stats( int $user_id = 0, string $email = '' ): array {
        $query_args = [
            'status' => [ 'completed', 'processing' ],
            'limit'  => -1,
        ];

        if ( $user_id > 0 ) {
            $query_args['customer_id'] = $user_id;
        } elseif ( $email ) {
            $query_args['billing_email'] = $email;
        } else {
            return [];
        }

        $orders = wc_get_orders( $query_args );

        if ( empty( $orders ) ) {
            return [ 'total_spend' => 0, 'order_count' => 0, 'avg_order_value' => 0 ];
        }

        $total_spend = 0.0;
        $dates       = [];

        foreach ( $orders as $o ) {
            $total_spend += (float) $o->get_total();
            if ( $o->get_date_created() ) {
                $dates[] = $o->get_date_created()->getTimestamp();
            }
        }

        $count = count( $orders );
        sort( $dates );

        return [
            'total_spend'      => round( $total_spend, 2 ),
            'order_count'      => $count,
            'avg_order_value'  => round( $total_spend / $count, 2 ),
            'first_order_date' => $dates ? date( 'Y-m-d', $dates[0] ) : '',
            'last_order_date'  => $dates ? date( 'Y-m-d', end( $dates ) ) : '',
        ];
    }
}
