<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Google  v2.1
 *
 * Google Ads Enhanced Conversions Sender.
 * Depends on: ServerTrack_Event, ServerTrack_Logger
 *
 * Changelog:
 *   v2.0 — Upgraded Google Ads API from v17 (EOL 2025-04-02) to v19 (current stable).
 *           Stale API versions return HTTP 400/404 and drop all conversions silently.
 *   v2.0 — get_valid_access_token() now compares expiry with 60s buffer before every call.
 *
 * IMPORTANT FIX (v2.1) — hashed_phone_number missing from user_identifiers:
 *
 *   send() built user_identifiers with hashed_email and address_info but never
 *   included hashed_phone_number. Google Enhanced Conversions uses phone as an
 *   independent match key — it is especially valuable when the user did not come
 *   via a Google click (no gclid) and email is absent or not matched.
 *
 *   The Google Ads API expects hashed_phone_number to be SHA-256 of the E.164
 *   string (same format as Meta/TikTok). The $event->user_data['phone'] field
 *   is already correctly hashed by build_order_user_data() (fixed in v2.1).
 *
 *   Fix: user_identifiers now includes { hashed_phone_number: $event->user_data['phone'] }
 *   as a standalone identifier entry when the field is present.
 *
 * Note on address_info city/state/zip/country:
 *   Google Enhanced Conversions requires these fields UNHASHED (raw plaintext).
 *   They are intentionally read from user_data['city_raw'] etc, not the hashed
 *   user_data['city'] fields that Meta/TikTok use. This is correct by design.
 */
class ServerTrack_Google {

    const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
    // v19 — current stable. v17 was EOL on 2025-04-02 and caused silent 404s.
    const UPLOAD_ENDPOINT = 'https://googleads.googleapis.com/v19/customers/%s/conversionActions/%s:uploadClickConversions';

    /**
     * Send an Enhanced Conversion to Google Ads.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        $customer_id   = trim( (string) get_option( 'servertrack_google_customer_id', '' ) );
        $conversion_id = trim( (string) get_option( 'servertrack_google_conversion_id', '' ) );
        $dev_token     = trim( (string) get_option( 'servertrack_google_developer_token', '' ) );

        if ( '' === $customer_id || '' === $conversion_id || '' === $dev_token ) {
            return [ 'status' => 'error', 'message' => 'Google Ads credentials not fully configured.' ];
        }

        // Always check token expiry before every API call (60s safety buffer)
        $access_token = self::get_valid_access_token();
        if ( ! $access_token ) {
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'Google OAuth token refresh failed.' ];
        }

        $conversion = [
            'conversion_action'    => sprintf( 'customers/%s/conversionActions/%s', $customer_id, $conversion_id ),
            'conversion_date_time' => gmdate( 'Y-m-d H:i:sP' ),
            'conversion_value'     => $event->custom_data['value'] ?? 0.0,
            'currency_code'        => $event->custom_data['currency'] ?? 'USD',
            'order_id'             => $event->custom_data['order_id'] ?? $event->event_id,
        ];

        // ── GCLID resolution ────────────────────────────────────────────────
        $gclid = $event->user_data['gclid'] ?? '';

        if ( empty( $gclid ) ) {
            $order_id = (int) ( $event->custom_data['order_id'] ?? 0 );
            if ( $order_id > 0 ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $meta_gclid = $order->get_meta( '_servertrack_gclid', true );
                    if ( ! empty( $meta_gclid ) ) {
                        $gclid = (string) $meta_gclid;
                    }
                }
            }
        }

        if ( ! empty( $gclid ) ) {
            $conversion['gclid'] = $gclid;
        }

        // ── User identifiers for Enhanced Conversions ───────────────────────
        // Google EC accepts three identifier types as separate array entries:
        //   { hashed_email }  { hashed_phone_number }  { address_info (raw unhashed) }
        $user_identifiers = [];

        if ( ! empty( $event->user_data['email'] ) ) {
            $user_identifiers[] = [ 'hashed_email' => $event->user_data['email'] ];
        }

        // FIX (v2.1): add hashed_phone_number as a standalone identifier.
        // Google EC uses phone as an independent match key. Already SHA-256 hashed
        // to E.164 by build_order_user_data() / build_renewal_user_data().
        if ( ! empty( $event->user_data['phone'] ) ) {
            $user_identifiers[] = [ 'hashed_phone_number' => $event->user_data['phone'] ];
        }

        // address_info fields are UNHASHED for Google (intentional — see class docblock)
        $address = [];
        if ( ! empty( $event->user_data['first_name'] ) ) {
            $address['hashed_first_name'] = $event->user_data['first_name'];
        }
        if ( ! empty( $event->user_data['last_name'] ) ) {
            $address['hashed_last_name'] = $event->user_data['last_name'];
        }
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
            ServerTrack_Logger::log(
                'error', 'google',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name, 0
            );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => $response->get_error_message() ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log(
            $status, 'google',
            (string) $code, $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name, $code
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }

    /**
     * Always compare token expiry against time() before every API call.
     * A 60-second buffer prevents edge cases at the expiry boundary.
     */
    private static function get_valid_access_token(): string {
        $access_token  = (string) get_option( 'servertrack_google_access_token', '' );
        $token_expires = (int) get_option( 'servertrack_google_token_expires', 0 );

        if ( ! empty( $access_token ) && $token_expires > ( time() + 60 ) ) {
            return $access_token;
        }

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
            ServerTrack_Logger::log(
                'error', 'google',
                'Token refresh returned no access_token. Response: ' . wp_remote_retrieve_body( $response )
            );
            return '';
        }

        $new_token  = $data['access_token'];
        $expires_in = (int) ( $data['expires_in'] ?? 3600 );

        update_option( 'servertrack_google_access_token', $new_token );
        update_option( 'servertrack_google_token_expires', time() + $expires_in );

        return $new_token;
    }
}
