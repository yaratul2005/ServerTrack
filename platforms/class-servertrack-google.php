<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Ads Enhanced Conversions Sender
 * Depends on: ServerTrack_Event (type-hinted), ServerTrack_Logger
 */
class ServerTrack_Google {

    const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
    const UPLOAD_ENDPOINT = 'https://googleads.googleapis.com/v14/customers/%s/conversionActions/%s:uploadClickConversions';

    /**
     * Send an Enhanced Conversion to Google Ads.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $customer_id   = get_option( 'servertrack_google_customer_id', '' );
        $conversion_id = get_option( 'servertrack_google_conversion_id', '' );
        $dev_token     = get_option( 'servertrack_google_developer_token', '' );

        if ( empty( $customer_id ) || empty( $conversion_id ) || empty( $dev_token ) ) {
            return [ 'status' => 'error', 'message' => 'Google Ads credentials not fully configured.' ];
        }

        // Constraint #4: ALWAYS check token expiry before every API call — never assume valid
        $access_token = self::get_valid_access_token();
        if ( ! $access_token ) {
            return [ 'status' => 'error', 'message' => 'Google OAuth token refresh failed.' ];
        }

        $conversion = [
            'conversion_action'    => sprintf( 'customers/%s/conversionActions/%s', $customer_id, $conversion_id ),
            'conversion_date_time' => gmdate( 'Y-m-d H:i:sP' ),
            'conversion_value'     => $event->custom_data['value'] ?? 0.0,
            'currency_code'        => $event->custom_data['currency'] ?? 'USD',
            'order_id'             => $event->custom_data['order_id'] ?? $event->event_id,
        ];

        // GCLID — only include if present (constraint #5)
        if ( ! empty( $event->user_data['gclid'] ) ) {
            $conversion['gclid'] = $event->user_data['gclid'];
        }

        // User identifiers
        $user_identifiers = [];
        if ( ! empty( $event->user_data['email'] ) ) {
            $user_identifiers[] = [ 'hashed_email' => $event->user_data['email'] ];
        }

        $address = [];
        if ( ! empty( $event->user_data['first_name'] ) ) {
            $address['hashed_first_name'] = $event->user_data['first_name'];
        }
        if ( ! empty( $event->user_data['last_name'] ) ) {
            $address['hashed_last_name'] = $event->user_data['last_name'];
        }
        // Google accepts raw (unhashed) geo fields — only include if present (constraint #5)
        $raw_addr_map = [
            'city_raw'    => 'city',
            'state_raw'   => 'state',
            'zip_raw'     => 'postal_code',
            'country_raw' => 'country_code',
        ];
        foreach ( $raw_addr_map as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $address[ $dest ] = $event->user_data[ $src ];
            }
        }
        if ( ! empty( $address ) ) {
            $user_identifiers[] = [ 'address_info' => $address ];
        }
        if ( ! empty( $user_identifiers ) ) {
            $conversion['user_identifiers'] = $user_identifiers;
        }

        $payload  = [ 'conversions' => [ $conversion ], 'partial_failure' => true ];
        $endpoint = sprintf( self::UPLOAD_ENDPOINT, $customer_id, $conversion_id );

        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                'Authorization'   => 'Bearer ' . $access_token,
                'developer-token' => $dev_token,
            ],
            'body' => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log( 'error', 'google', $response->get_error_message() );
            return [ 'status' => 'error', 'message' => $response->get_error_message() ];
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log( $status, 'google', (string) $code, $body_raw );
        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }

    /**
     * Constraint #4: Compare token expiry against time() before EVERY call.
     * A 60-second buffer prevents edge cases at expiry boundary.
     */
    private static function get_valid_access_token(): string {
        $access_token  = (string) get_option( 'servertrack_google_access_token', '' );
        $token_expires = (int) get_option( 'servertrack_google_token_expires', 0 );

        if ( ! empty( $access_token ) && $token_expires > ( time() + 60 ) ) {
            return $access_token; // Still valid
        }

        // Expired or missing — refresh now
        $client_id     = get_option( 'servertrack_google_client_id', '' );
        $client_secret = get_option( 'servertrack_google_client_secret', '' );
        $refresh_token = get_option( 'servertrack_google_refresh_token', '' );

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
            ServerTrack_Logger::log( 'error', 'google', 'OAuth credentials missing — cannot refresh token.' );
            return '';
        }

        $response = wp_remote_post( self::TOKEN_ENDPOINT, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log( 'error', 'google', 'Token refresh failed: ' . $response->get_error_message() );
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['access_token'] ) ) {
            ServerTrack_Logger::log( 'error', 'google', 'Token refresh returned no access_token. Response: ' . wp_remote_retrieve_body( $response ) );
            return '';
        }

        $new_token  = $data['access_token'];
        $expires_in = (int) ( $data['expires_in'] ?? 3600 );

        update_option( 'servertrack_google_access_token', $new_token );
        update_option( 'servertrack_google_token_expires', time() + $expires_in );

        return $new_token;
    }
}
