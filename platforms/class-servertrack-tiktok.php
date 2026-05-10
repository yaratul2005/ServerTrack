<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_TikTok  v2.1
 *
 * TikTok Events API Sender.
 * Depends on: ServerTrack_Event, ServerTrack_Logger
 *
 * IMPORTANT FIX (v2.1) — page.url built from $_SERVER in async cron:
 *
 *   Same root cause as the Meta event_source_url bug (fixed in class-servertrack-meta.php):
 *   page.url was built from $_SERVER['REQUEST_URI'] inside send(). In async WP-Cron,
 *   REQUEST_URI = '/wp-cron.php'. TikTok Events API uses page.url for:
 *     - Attribution (which landing page drove the conversion)
 *     - Audience building (retargeting visitors of specific pages)
 *     - Event Match Quality score
 *
 *   Sending '/wp-cron.php' as the page URL poisons TikTok attribution on every
 *   Purchase / AddToCart event that runs through the async cron path.
 *
 *   Fix: send() now reads $event->event_source_url (set in browser context by the
 *   WooCommerce source via set_source_url()). Falls back to home_url() only when
 *   the field is empty — which is safe for synchronous browser-context sends
 *   (ViewContent, AddToCart, InitiateCheckout) where REQUEST_URI IS correct.
 *
 * Additional fix (v2.1) — missing external_id:
 *   TikTok's "Advanced Matching" uses external_id as its highest-confidence
 *   identifier for logged-in users. It was absent from the user object.
 *   Now included from $event->user_data['external_id'] when present (already
 *   SHA-256 hashed by build_order_user_data in the WooCommerce source).
 */
class ServerTrack_TikTok {

    const API_ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private static array $event_name_map = [
        'Purchase'              => 'CompletePayment',
        'Lead'                  => 'SubmitForm',
        'ViewContent'           => 'ViewContent',
        'AddToCart'             => 'AddToCart',
        'InitiateCheckout'      => 'InitiateCheckout',
        'CompleteRegistration'  => 'CompleteRegistration',
        'AddPaymentInfo'        => 'AddPaymentInfo',
    ];

    /**
     * Send an event to TikTok Events API.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $pixel_id     = trim( (string) get_option( 'servertrack_tiktok_pixel_id', '' ) );
        $access_token = trim( (string) get_option( 'servertrack_tiktok_access_token', '' ) );

        if ( '' === $pixel_id || '' === $access_token ) {
            return [ 'status' => 'error', 'message' => 'TikTok Pixel ID or Access Token not configured.' ];
        }

        $tiktok_event = self::$event_name_map[ $event->event_name ] ?? $event->event_name;

        // ── Build user object (omit absent fields entirely) ─────────────────
        $user = [];

        $hashed_fields = [
            'email'      => 'email',
            'phone'      => 'phone_number',
            'first_name' => 'first_name',
            'last_name'  => 'last_name',
        ];
        foreach ( $hashed_fields as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $user[ $dest ] = $event->user_data[ $src ];
            }
        }

        $raw_fields = [
            'ip'         => 'ip',
            'user_agent' => 'user_agent',
            'ttclid'     => 'ttclid',
        ];
        foreach ( $raw_fields as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $user[ $dest ] = $event->user_data[ $src ];
            }
        }

        // FIX (v2.1): include external_id for Advanced Matching.
        // Already SHA-256 hashed. Strongest identity signal for logged-in users.
        if ( ! empty( $event->user_data['external_id'] ) ) {
            $user['external_id'] = $event->user_data['external_id'];
        }

        // ── FIX (v2.1): page.url from event DTO instead of $_SERVER ─────────
        // In async WP-Cron, REQUEST_URI = '/wp-cron.php' — not the real page.
        // Read event_source_url captured in browser context by the source.
        if ( ! empty( $event->event_source_url ) ) {
            $page_url = $event->event_source_url;
        } else {
            // Fallback: synchronous browser-context sends (ViewContent, AddToCart)
            // where REQUEST_URI is the correct page URL.
            $request_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '/';
            $page_url = home_url( $request_uri );
        }

        // ── Assemble event payload ──────────────────────────────────────────
        $event_data = [
            'event'      => $tiktok_event,
            'event_time' => time(),
            'event_id'   => $event->event_id,
            'user'       => $user,
            'properties' => [
                'currency'     => $event->custom_data['currency'] ?? 'USD',
                'value'        => $event->custom_data['value'] ?? 0.0,
                'contents'     => $event->custom_data['contents'] ?? [],
                'content_type' => 'product',
            ],
            'page' => [ 'url' => $page_url ],
        ];

        $payload = [
            'pixel_code'   => $pixel_id,
            'event_source' => 'web',
            'partner_name' => 'ServerTrack',
            'data'         => [ $event_data ],
        ];

        $response = wp_remote_post( self::API_ENDPOINT, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token,
            ],
            'body' => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log(
                'error', 'tiktok',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name, 0
            );
            return [ 'status' => 'error', 'message' => $response->get_error_message() ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log(
            $status, 'tiktok',
            (string) $code, $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name, $code
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
