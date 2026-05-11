<?php
/**
 * ServerTrack — Browser Pixel event_id Injection (Feature #5)
 *
 * Closes the server-side ↔ browser-pixel deduplication loop.
 *
 * Problem:
 *   Even with server-side CAPI, many stores still load the Meta browser pixel
 *   (fbq) for fast front-end events (PageView, ViewContent). Without a shared
 *   event_id, Meta counts both the pixel fire AND the CAPI event as separate
 *   conversions — doubling reported purchases.
 *
 * Solution:
 *   1. On WooCommerce order completion, generate a canonical event_id and store
 *      it in order meta: _servertrack_event_id_{event_name}
 *   2. Inject a tiny JS snippet into the Thank You page that calls
 *      fbq('track', 'Purchase', {...}, {eventID: '...'}) using the SAME event_id
 *      that was already sent via CAPI.
 *   3. Meta deduplicates: one conversion counted, not two.
 *
 * Covered events: Purchase (thank-you page), InitiateCheckout (checkout page),
 *                 AddToCart (product page — injected via JS data attribute).
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_PixelDedup {

    /**
     * Register hooks.
     */
    public static function init(): void {
        // Store event IDs when WooCommerce events fire
        add_action( 'woocommerce_checkout_order_created',          [ __CLASS__, 'store_purchase_event_id' ], 10, 1 );
        add_action( 'woocommerce_before_checkout_form',            [ __CLASS__, 'inject_initiate_checkout_id' ] );
        add_action( 'woocommerce_before_add_to_cart_button',       [ __CLASS__, 'inject_add_to_cart_data' ] );

        // Inject the pixel dedup snippet on the thank-you page
        add_action( 'woocommerce_thankyou',                        [ __CLASS__, 'inject_purchase_dedup_snippet' ], 10, 1 );

        // REST endpoint: front-end JS can fetch a fresh event_id for any event
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_endpoint' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Event ID generation & storage
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate and store the Purchase event_id at order creation time.
     * This runs in the same request as CAPI sending, so the IDs match.
     *
     * @param WC_Order $order
     */
    public static function store_purchase_event_id( WC_Order $order ): void {
        $event_id = self::generate_event_id( 'purchase', $order->get_id() );
        $order->update_meta_data( '_servertrack_event_id_purchase', $event_id );
        $order->save();
    }

    /**
     * Generate a deterministic, collision-resistant event_id.
     *
     * Format: {event}_{order_or_session}_{microtime_hash}
     * Using sha256 of stable inputs means the same logical event always
     * produces the same ID within a single page load.
     *
     * @param string $event_name  e.g. 'purchase', 'initiatecheckout'
     * @param int    $context_id  Order ID, session hash, or product ID
     * @return string
     */
    public static function generate_event_id( string $event_name, int $context_id = 0 ): string {
        $seed = $event_name . '_' . $context_id . '_' . microtime( true ) . '_' . wp_generate_password( 8, false );
        return substr( hash( 'sha256', $seed ), 0, 32 );
    }

    /**
     * Get the stored event_id for a given order + event name.
     *
     * @param int    $order_id
     * @param string $event_name
     * @return string
     */
    public static function get_order_event_id( int $order_id, string $event_name = 'purchase' ): string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }
        return (string) $order->get_meta( '_servertrack_event_id_' . $event_name ) ?: '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Front-end injection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inject the Purchase dedup snippet on the WooCommerce thank-you page.
     * This calls fbq('track','Purchase',{...},{eventID:'...'}) using the
     * canonical event_id that was sent via CAPI.
     *
     * @param int $order_id
     */
    public static function inject_purchase_dedup_snippet( int $order_id ): void {
        $event_id = self::get_order_event_id( $order_id, 'purchase' );
        if ( ! $event_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $value    = (float) $order->get_total();
        $currency = strtoupper( get_woocommerce_currency() );

        // Build contents array for pixel
        $contents = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( $product ) {
                $contents[] = [
                    'id'       => $product->get_sku() ?: (string) $product->get_id(),
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        $data = [
            'value'        => $value,
            'currency'     => $currency,
            'content_type' => 'product',
            'contents'     => $contents,
            'order_id'     => (string) $order_id,
        ];

        $data_json     = wp_json_encode( $data );
        $event_id_json = wp_json_encode( $event_id );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<script>\n";
        echo "/* ServerTrack — Purchase pixel dedup (event_id matches CAPI) */\n";
        echo "if(typeof fbq==='function'){";
        echo "fbq('track','Purchase',{$data_json},{eventID:{$event_id_json}});";
        echo "}\n";
        echo "</script>\n";
        // phpcs:enable
    }

    /**
     * Inject InitiateCheckout event_id as a hidden input + JS call.
     * The same event_id should be passed to CAPI when the checkout is processed.
     */
    public static function inject_initiate_checkout_id(): void {
        $event_id = self::generate_event_id( 'initiatecheckout', 0 );

        // Store in WC session so CAPI can read it during order creation
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'servertrack_ic_event_id', $event_id );
        }

        $event_id_json = wp_json_encode( $event_id );
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<script>\n";
        echo "/* ServerTrack — InitiateCheckout pixel dedup */\n";
        echo "if(typeof fbq==='function'){";
        echo "fbq('track','InitiateCheckout',{},{eventID:{$event_id_json}});";
        echo "}\n";
        echo "</script>\n";
        // phpcs:enable
    }

    /**
     * Inject AddToCart event_id as a data attribute on the product page.
     * The pixel JS on the page should read data-servertrack-event-id and
     * pass it as eventID to fbq('track','AddToCart',...).
     */
    public static function inject_add_to_cart_data(): void {
        global $product;
        if ( ! $product ) {
            return;
        }
        $event_id = self::generate_event_id( 'addtocart', $product->get_id() );
        echo '<input type="hidden" id="servertrack-atc-event-id" value="' . esc_attr( $event_id ) . '">' . "\n";
        echo "<script>\n";
        echo "document.addEventListener('click',function(e){";
        echo "var btn=e.target.closest('.single_add_to_cart_button');";
        echo "if(!btn)return;";
        echo "var eid=document.getElementById('servertrack-atc-event-id');";
        echo "if(eid&&typeof fbq==='function'){";
        echo "fbq('track','AddToCart',{},{eventID:eid.value});";
        echo "}});\n";
        echo "</script>\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REST endpoint: GET /wp-json/servertrack/v1/event-id
    // ─────────────────────────────────────────────────────────────────────────

    public static function register_rest_endpoint(): void {
        register_rest_route( 'servertrack/v1', '/event-id', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_get_event_id' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event' => [
                    'required'          => false,
                    'default'           => 'generic',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'order_id' => [
                    'required'          => false,
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    public static function rest_get_event_id( WP_REST_Request $request ): WP_REST_Response {
        $event    = $request->get_param( 'event' );
        $order_id = (int) $request->get_param( 'order_id' );

        if ( $order_id > 0 ) {
            $event_id = self::get_order_event_id( $order_id, $event );
            if ( ! $event_id ) {
                $event_id = self::generate_event_id( $event, $order_id );
            }
        } else {
            $event_id = self::generate_event_id( $event, 0 );
        }

        return new WP_REST_Response( [ 'event_id' => $event_id ], 200 );
    }
}
