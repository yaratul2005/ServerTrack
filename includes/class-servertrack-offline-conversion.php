<?php
/**
 * ServerTrack — Offline Conversion Sync (Feature #4)
 *
 * Sends a fulfilment/CRM signal back to Meta CAPI when a WooCommerce order
 * transitions to 'completed' (i.e. physically shipped / service delivered).
 *
 * Why this matters:
 *   Meta's CAPI Purchase event fires at checkout (payment captured). But Meta's
 *   algorithm learns best from *fulfilment* signals — the moment value is truly
 *   delivered. Sending a second Purchase event with event_name = 'Purchase' and
 *   action_source = 'crm' closes the loop between the ad click and real-world
 *   delivery, dramatically improving ROAS reporting and value-based bidding.
 *
 * Dedup key: offline_{order_id}  — prevents double-counting with the
 *             online Purchase event which uses key purchase_{order_id}.
 *
 * Bug #5 fix (v2.1):
 *   schedule_offline_event() now checks for subscription renewal meta before
 *   scheduling. Previously, subscription renewal orders that transitioned to
 *   'completed' would trigger an Offline Conversion on top of the renewal
 *   CAPI event fired by ServerTrack_Subscriptions — doubling conversions.
 *   The guard mirrors the one already in ServerTrack_WooCommerce::on_order_completed().
 *
 * Bug #5 fix (v2.1) — also:
 *   ServerTrack_Dedup::exists() and ::set() are now correctly defined in
 *   class-servertrack-dedup.php (v2.3). Previously, calling these non-existent
 *   methods caused a PHP fatal error that silently suppressed the dedup guard,
 *   meaning EVERY order completion re-sent the offline event.
 *
 * Bug #6 fix (v2.1):
 *   Logger::log() arg order corrected. Pre-fix, args were passed in the old
 *   pre-v2.0 positional order (platform, message, order_id, status, emq)
 *   instead of the v2.0+ order (status, platform, message, response, event_id,
 *   order_id, event_type, emq). The mismatch silently corrupted log entries
 *   (status field showed 'meta', platform showed 'Purchase (offline/crm)', etc.)
 *   and prevented the servertrack_event_logged action from carrying correct data
 *   to the webhook dispatcher.
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_OfflineConversion {

    /**
     * Register hooks.
     */
    public static function init(): void {
        // Fire when an order status changes TO 'completed'
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'schedule_offline_event' ], 10, 1 );

        // Async cron handler
        add_action( 'servertrack_send_offline_conversion', [ __CLASS__, 'send_offline_conversion' ], 10, 1 );
    }

    /**
     * Schedule the async cron job.
     *
     * BUG #5 FIX: Added subscription renewal guard.
     * WooCommerce Subscriptions creates renewal orders that transition to
     * 'completed' just like regular orders. Without this check, every
     * subscription renewal would fire an Offline Conversion event on top
     * of the renewal-specific CAPI event from ServerTrack_Subscriptions,
     * causing double-counted conversions in Meta's Events Manager.
     *
     * The guard checks the '_subscription_renewal' meta key set by
     * WooCommerce Subscriptions — the same approach used in
     * ServerTrack_WooCommerce::on_order_completed() and on_thankyou().
     *
     * @param int $order_id
     */
    public static function schedule_offline_event( int $order_id ): void {
        // BUG #5 FIX: Skip subscription renewal orders.
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_meta( '_subscription_renewal' ) ) {
            return;
        }

        $dedup_key = 'offline_' . $order_id;

        // Already sent? (Uses ServerTrack_Dedup::exists() — fixed in Dedup v2.3)
        if ( ServerTrack_Dedup::exists( $dedup_key ) ) {
            return;
        }

        if ( ! wp_next_scheduled( 'servertrack_send_offline_conversion', [ $order_id ] ) ) {
            wp_schedule_single_event( time() + 30, 'servertrack_send_offline_conversion', [ $order_id ] );
        }
    }

    /**
     * Build and send the offline CRM fulfilment signal.
     *
     * BUG #6 FIX: Logger::log() args corrected to v2.0+ positional order:
     *   ( status, platform, message, response, event_id, order_id, event_type, emq )
     * Previously called as (platform, message, order_id, status, emq) which
     * mapped to: status='meta', platform='Purchase (offline/crm)', message=(int),
     * response=(string) — completely wrong, corrupting every log entry.
     *
     * @param int $order_id
     */
    public static function send_offline_conversion( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $dedup_key  = 'offline_' . $order_id;
        $event_time = $order->get_date_completed()
            ? $order->get_date_completed()->getTimestamp()
            : time();

        // ── User data ──────────────────────────────────────────────────────────
        $user_data = self::build_user_data( $order );

        // ── EMQ score ─────────────────────────────────────────────────────────
        $emq = class_exists( 'ServerTrack_MatchQuality' )
            ? ServerTrack_MatchQuality::score( $user_data )
            : [];

        // ── Build custom data ──────────────────────────────────────────────────
        $contents = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $contents[] = [
                'id'         => (string) ( $product->get_sku() ?: $product->get_id() ),
                'quantity'   => $item->get_quantity(),
                'item_price' => (float) $item->get_total() / max( 1, $item->get_quantity() ),
            ];
        }

        $custom_data = [
            'value'             => (float) $order->get_total(),
            'currency'          => strtoupper( get_woocommerce_currency() ),
            'order_id'          => (string) $order_id,
            'contents'          => $contents,
            'content_type'      => 'product',
            'delivery_category' => 'home_delivery', // filterable
        ];
        $custom_data = apply_filters( 'servertrack_offline_custom_data', $custom_data, $order );

        // ── Meta CRM signal ────────────────────────────────────────────────────
        $payload = [
            'event_name'    => 'Purchase',
            'event_time'    => $event_time,
            'action_source' => 'crm',          // <-- key differentiator
            'event_id'      => $dedup_key,
            'user_data'     => $user_data,
            'custom_data'   => $custom_data,
        ];

        // Allow other platforms / custom extensions
        do_action( 'servertrack_offline_conversion_before_send', $payload, $order );

        if ( class_exists( 'ServerTrack_Meta' ) ) {
            $result = ServerTrack_Meta::send( $payload );
            $status = ( ! is_wp_error( $result ) && isset( $result['events_received'] ) && $result['events_received'] > 0 )
                ? 'success' : 'error';

            // BUG #6 FIX: Correct Logger::log() arg order (v2.0+ signature):
            //   ( status, platform, message, response, event_id, order_id, event_type, emq )
            // Old (broken) call was: ( platform, message, order_id, status, emq )
            // which silently mapped: status='meta', platform='Purchase (offline/crm)',
            // message=(int)order_id, response=(string)status — all wrong.
            ServerTrack_Logger::log(
                $status,                        // status    — 'success'|'error'
                'meta',                         // platform  — 'meta'
                'Offline conversion (crm)',     // message
                '',                             // response  — raw API response omitted here
                $dedup_key,                     // event_id  — 'offline_{order_id}'
                $order_id,                      // order_id
                'Purchase',                     // event_type
                $emq                            // emq       — score array
            );

            if ( 'success' === $status ) {
                // Uses ServerTrack_Dedup::set() — fixed in Dedup v2.3
                ServerTrack_Dedup::set( $dedup_key );
                $order->update_meta_data( '_servertrack_offline_sent', current_time( 'mysql' ) );
                $order->save();
            }
        }

        do_action( 'servertrack_offline_conversion_after_send', $payload, $order );
    }

    /**
     * Build normalised user_data from an order.
     *
     * Priority: identity store → server click store → order billing fields.
     *
     * @param WC_Order $order
     * @return array
     */
    private static function build_user_data( WC_Order $order ): array {
        $user_id = $order->get_user_id();

        // external_id
        $external_id = '';
        if ( $user_id && class_exists( 'ServerTrack_Identity' ) ) {
            $external_id = ServerTrack_Identity::get_uid( $user_id );
        }
        if ( ! $external_id ) {
            $email = $order->get_billing_email();
            if ( $email && class_exists( 'ServerTrack_Identity' ) ) {
                $matched = ServerTrack_Identity::find_user_by_email( $email );
                $external_id = $matched
                    ? ServerTrack_Identity::get_uid( $matched )
                    : ServerTrack_Hasher::hash( $email );
            }
        }

        // fbc / fbp — from server click store first
        $fbc = $fbp = '';
        if ( $user_id && class_exists( 'ServerTrack_ClickCapture' ) ) {
            $stored = ServerTrack_ClickCapture::get_stored( $user_id );
            $fbc    = $stored['fbc'] ?? '';
            $fbp    = $stored['fbp'] ?? '';
        }
        if ( ! $fbc ) {
            $fbc = $order->get_meta( '_servertrack_fbc' ) ?: '';
        }
        if ( ! $fbp ) {
            $fbp = $order->get_meta( '_servertrack_fbp' ) ?: '';
        }

        $email   = $order->get_billing_email();
        $phone   = preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() );
        $first   = $order->get_billing_first_name();
        $last    = $order->get_billing_last_name();
        $city    = $order->get_billing_city();
        $state   = $order->get_billing_state();
        $zip     = $order->get_billing_postcode();
        $country = strtolower( $order->get_billing_country() );

        return array_filter( [
            'em'          => $email  ? ServerTrack_Hasher::hash( strtolower( trim( $email ) ) ) : '',
            'ph'          => $phone  ? ServerTrack_Hasher::hash( $phone ) : '',
            'fn'          => $first  ? ServerTrack_Hasher::hash( strtolower( $first ) ) : '',
            'ln'          => $last   ? ServerTrack_Hasher::hash( strtolower( $last ) ) : '',
            'ct'          => $city   ? ServerTrack_Hasher::hash( strtolower( $city ) ) : '',
            'st'          => $state  ? ServerTrack_Hasher::hash( strtolower( $state ) ) : '',
            'zp'          => $zip    ? ServerTrack_Hasher::hash( $zip ) : '',
            'country'     => $country ?: '',
            'external_id' => $external_id,
            'fbc'         => $fbc,
            'fbp'         => $fbp,
        ] );
    }
}
