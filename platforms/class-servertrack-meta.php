<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Meta  v2.1
 *
 * Meta Conversions API Sender.
 * Depends on: ServerTrack_Event, ServerTrack_Logger
 *
 * IMPORTANT FIXES (v2.1):
 *
 *   1. event_source_url was built from $_SERVER['REQUEST_URI'] inside the
 *      send() method. In async WP-Cron, REQUEST_URI = '/wp-cron.php' — not
 *      the product/checkout page. Meta uses event_source_url for attribution
 *      and retargeting audience matching. Sending '/wp-cron.php' poisons
 *      every Purchase event's attribution data.
 *
 *      Fix: send() now reads $event->event_source_url (set in browser context
 *      by the WooCommerce source before dispatching async cron). Falls back to
 *      home_url() only when the field is empty (non-order events like ViewContent
 *      that already run with correct URL context).
 *
 *   2. external_id was missing from user_data. Meta's Advanced Matching uses
 *      external_id as the highest-signal identifier for logged-in users.
 *      For guest checkouts it falls back to hashed order ID.
 *      external_id is now included from $event->user_data['external_id'] if set.
 *
 * Changelog:
 *   - Bumped Graph API to v22.0 (v21.0 deprecated — caused silent drops)
 *   - action_source fallback to 'website' when REQUEST_URI is empty (cron)
 *   - Hardened empty pixel_id / access_token guard with early return
 */
class ServerTrack_Meta {

    // v22.0 — current stable. v21.0 was deprecated and caused silent drops.
    const API_ENDPOINT = 'https://graph.facebook.com/v22.0/%s/events';

    /**
     * Send an event to Meta CAPI.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $pixel_id     = trim( (string) get_option( 'servertrack_meta_pixel_id', '' ) );
        $access_token = trim( (string) get_option( 'servertrack_meta_access_token', '' ) );

        if ( '' === $pixel_id || '' === $access_token ) {
            return [
                'status'  => 'error',
                'message' => 'Meta Pixel ID or Access Token not configured. Please save your credentials in the Meta CAPI tab first.',
            ];
        }

        // ── Build hashed user_data object ─────────────────────────────────────
        $ud = [];

        $hashed_map = [
            'email'      => 'em',
            'phone'      => 'ph',
            'first_name' => 'fn',
            'last_name'  => 'ln',
            'city'       => 'ct',
            'state'      => 'st',
            'zip'        => 'zp',
            'country'    => 'country',
        ];
        foreach ( $hashed_map as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $ud[ $dest ] = [ $event->user_data[ $src ] ];
            }
        }

        $raw_map = [
            'ip'         => 'client_ip_address',
            'user_agent' => 'client_user_agent',
            'fbp'        => 'fbp',
            'fbc'        => 'fbc',
        ];
        foreach ( $raw_map as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $ud[ $dest ] = $event->user_data[ $src ];
            }
        }

        // FIX (v2.1): include external_id for Advanced Matching.
        // Meta treats external_id as the strongest identity signal for logged-in users.
        // Already hashed (SHA-256) by build_order_user_data() in the WooCommerce source.
        if ( ! empty( $event->user_data['external_id'] ) ) {
            $ud['external_id'] = [ $event->user_data['external_id'] ];
        }

        // ── Build event_source_url ───────────────────────────────────────────
        // FIX (v2.1): read from event DTO instead of $_SERVER['REQUEST_URI'].
        // In async WP-Cron, REQUEST_URI = '/wp-cron.php' — not the real page.
        // The WooCommerce source captures the real URL in browser context and
        // stores it on the event via set_source_url() / order meta lookup.
        if ( ! empty( $event->event_source_url ) ) {
            $source_url = $event->event_source_url;
        } else {
            // Fallback for synchronous browser-context sends (ViewContent, AddToCart)
            // where REQUEST_URI IS the correct page URL.
            $request_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '/';
            $source_url = home_url( $request_uri );
        }

        // ── Assemble event payload ───────────────────────────────────────────
        $event_payload = [
            'event_name'       => $event->event_name,
            'event_time'       => time(),
            'event_id'         => $event->event_id,
            'event_source_url' => $source_url,
            'action_source'    => 'website',
            'user_data'        => $ud,
            'custom_data'      => $event->custom_data,
        ];

        $body = [
            'data'         => [ $event_payload ],
            'access_token' => $access_token,
        ];

        // Attach test_event_code if present (required for Meta Test Events tool)
        $test_code = ! empty( $event->custom_data['_test_event_code'] )
            ? $event->custom_data['_test_event_code']
            : trim( (string) get_option( 'servertrack_meta_test_event_code', '' ) );

        if ( '' !== $test_code ) {
            $body['test_event_code'] = $test_code;
            unset( $event_payload['custom_data']['_test_event_code'] );
        }

        $endpoint = sprintf( self::API_ENDPOINT, $pixel_id );

        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log(
                'error', 'meta',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name, 0
            );
            return [ 'status' => 'error', 'message' => $response->get_error_message(), 'http_code' => 0 ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log(
            $status, 'meta',
            (string) $code,
            $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name,
            $code
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
