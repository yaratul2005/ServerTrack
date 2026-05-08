<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta Conversions API Sender
 * Depends on: ServerTrack_Event (type-hinted), ServerTrack_Logger
 */
class ServerTrack_Meta {

    const API_ENDPOINT = 'https://graph.facebook.com/v18.0/%s/events';

    /**
     * Send an event to Meta CAPI.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $pixel_id     = get_option( 'servertrack_meta_pixel_id', '' );
        $access_token = get_option( 'servertrack_meta_access_token', '' );

        if ( empty( $pixel_id ) || empty( $access_token ) ) {
            return [ 'status' => 'error', 'message' => 'Meta Pixel ID or Access Token not configured.' ];
        }

        $ud = [];

        // Hashed PII — only include if present (constraint #5)
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

        // Raw non-PII — only include if present (constraint #5)
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

        $event_payload = [
            'event_name'       => $event->event_name,
            'event_time'       => time(),
            'event_id'         => $event->event_id,
            'event_source_url' => home_url( $_SERVER['REQUEST_URI'] ?? '/' ),
            'action_source'    => 'website',
            'user_data'        => $ud,
            'custom_data'      => $event->custom_data,
        ];

        $body = [ 'data' => [ $event_payload ], 'access_token' => $access_token ];

        $test_code = get_option( 'servertrack_meta_test_event_code', '' );
        if ( ! empty( $test_code ) ) {
            $body['test_event_code'] = $test_code;
        }

        $endpoint = sprintf( self::API_ENDPOINT, $pixel_id );

        // constraint #8: wp_remote_post only — no curl
        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log( 'error', 'meta', $response->get_error_message() );
            return [ 'status' => 'error', 'message' => $response->get_error_message() ];
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log( $status, 'meta', (string) $code, $body_raw );
        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
