<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TikTok Events API Sender
 * Depends on: ServerTrack_Event (type-hinted), ServerTrack_Logger
 */
class ServerTrack_TikTok {

    const API_ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private static array $event_name_map = [
        'Purchase'         => 'CompletePayment',
        'Lead'             => 'SubmitForm',
        'ViewContent'      => 'ViewContent',
        'AddToCart'        => 'AddToCart',
        'InitiateCheckout' => 'InitiateCheckout',
    ];

    /**
     * Send an event to TikTok Events API.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $pixel_id     = get_option( 'servertrack_tiktok_pixel_id', '' );
        $access_token = get_option( 'servertrack_tiktok_access_token', '' );

        if ( empty( $pixel_id ) || empty( $access_token ) ) {
            return [ 'status' => 'error', 'message' => 'TikTok Pixel ID or Access Token not configured.' ];
        }

        $tiktok_event = self::$event_name_map[ $event->event_name ] ?? $event->event_name;

        // Build user object — omit absent fields entirely (constraint #5)
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
            'page' => [ 'url' => home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ],
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
            ServerTrack_Logger::log( 'error', 'tiktok', $response->get_error_message() );
            return [ 'status' => 'error', 'message' => $response->get_error_message() ];
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log( $status, 'tiktok', (string) $code, $body_raw );
        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
