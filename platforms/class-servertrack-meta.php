<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta Conversions API Sender
 * Depends on: ServerTrack_Event (type-hinted), ServerTrack_Logger
 *
 * Changelog:
 *   - Bumped Graph API to v22.0 (v21.0 deprecated — caused silent drops in Test Events tool)
 *   - action_source fallback to 'website' when REQUEST_URI is empty (cron context)
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

        // Build hashed user_data object
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

        // Fallback for cron context where REQUEST_URI is not set
        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '/';

        $event_payload = [
            'event_name'       => $event->event_name,
            'event_time'       => time(),
            'event_id'         => $event->event_id,
            'event_source_url' => home_url( $request_uri ),
            'action_source'    => 'website',
            'user_data'        => $ud,
            'custom_data'      => $event->custom_data,
        ];

        $body = [
            'data'         => [ $event_payload ],
            'access_token' => $access_token,
        ];

        // Attach test_event_code if present (required for Meta Test Events tool)
        // Priority: event custom_data (set by fire_test_event AJAX handler)
        //           then saved wp_option (set after Save Changes)
        $test_code = ! empty( $event->custom_data['_test_event_code'] )
            ? $event->custom_data['_test_event_code']
            : trim( (string) get_option( 'servertrack_meta_test_event_code', '' ) );

        if ( '' !== $test_code ) {
            $body['test_event_code'] = $test_code;
            // Remove internal key from custom_data before sending
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
