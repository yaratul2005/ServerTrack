<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}

/**
 * Ratul_ACT_License
 *
 * Handles license key validation and communication with the CRXSM licensing server.
 */
class Ratul_ACT_License {

    const STORE_URL = 'https://great10.xyz';
    const CACHE_KEY = 'ratul_act_license_status';

    public static function init(): void {
        add_action( 'ratul_act_daily_health_check', [ self::class, 'check_license_status' ] );
    }

    /**
     * Instantiates the CRXSM License Client with hardcoded credentials.
     */
    private static function get_client(): CRXSM_License_Client {
        $api_url = self::STORE_URL . '/Tunnel/index.php';
        $client_id = 'sw_542f42eee9271290367d2907fb8bc024';
        $client_secret = '3c3561a18f4c3dfeca728df74c2e5150fbcc1282c6aab3b4';
        $public_key = 'bf04ec5f8f8e5667b0c00f95c4355b847b76eb6f1ed9859eef4312bcc089fa60';

        return new CRXSM_License_Client( $api_url, $client_id, $client_secret, $public_key );
    }

    /**
     * Checks if the current license is active.
     * Combines offline Ed25519 signature validation and local cache checks.
     */
    public static function is_active(): bool {
        $license_key = get_option( 'ratul_act_license_key', '' );
        if ( empty( $license_key ) ) {
            return false;
        }

        // 1. Offline Signature Check (Ed25519 verification)
        $client = self::get_client();
        $payload = $client->verify_signature_offline( $license_key );
        if ( ! $payload ) {
            return false;
        }

        // 2. Check local cached status option
        $status = get_option( self::CACHE_KEY, '' );
        return $status === 'valid';
    }

    /**
     * Activates a license key with the central server.
     */
    public static function activate( string $license_key ): array {
        if ( empty( $license_key ) ) {
            return [ 'success' => false, 'message' => 'License key cannot be empty.' ];
        }

        // 1. Validate signature offline first
        $client = self::get_client();
        $payload = $client->verify_signature_offline( $license_key );
        if ( ! $payload ) {
            return [ 'success' => false, 'message' => 'Invalid license key format or signature.' ];
        }

        // 2. Call remote activation API
        $domain     = wp_parse_url( home_url(), PHP_URL_HOST );
        $machine_id = md5( home_url() );

        $response = $client->activate_license( $license_key, $domain, $machine_id );

        if ( ! empty( $response['success'] ) && $response['success'] === true ) {
            update_option( 'ratul_act_license_key', $license_key );
            update_option( self::CACHE_KEY, 'valid' );
            if ( isset( $payload['expires_at'] ) ) {
                update_option( 'ratul_act_license_expires_at', $payload['expires_at'] );
            } else {
                delete_option( 'ratul_act_license_expires_at' );
            }
            update_option( 'ratul_act_license_last_check', time() );
            return [ 'success' => true, 'message' => 'License activated successfully.' ];
        }

        $error = $response['error'] ?? $response['message'] ?? 'Activation failed.';
        update_option( self::CACHE_KEY, 'invalid' );

        return [ 'success' => false, 'message' => $error ];
    }

    /**
     * Deactivates a license key.
     */
    public static function deactivate(): array {
        $license_key = get_option( 'ratul_act_license_key', '' );
        if ( empty( $license_key ) ) {
            return [ 'success' => false, 'message' => 'No active license key found.' ];
        }

        // Call remote deactivation
        $client     = self::get_client();
        $domain     = wp_parse_url( home_url(), PHP_URL_HOST );
        $machine_id = md5( home_url() );

        $response = $client->deactivate_license( $license_key, $domain, $machine_id );

        // Always delete local state regardless of remote success to allow user to force-reset
        delete_option( 'ratul_act_license_key' );
        delete_option( self::CACHE_KEY );
        delete_option( 'ratul_act_license_expires_at' );
        delete_option( 'ratul_act_license_last_check' );

        if ( empty( $response['success'] ) ) {
            $error = $response['error'] ?? $response['message'] ?? 'Deactivated locally (could not reach server).';
            return [ 'success' => true, 'message' => $error ];
        }

        return [ 'success' => true, 'message' => 'License deactivated successfully.' ];
    }

    /**
     * Scheduled heartbeat check-in to verify active licenses on the server.
     */
    public static function check_license_status(): void {
        $license_key = get_option( 'ratul_act_license_key', '' );
        if ( empty( $license_key ) ) {
            return;
        }

        $client = self::get_client();

        // 1. Offline Signature Check (quick tamper/expiry check)
        $payload = $client->verify_signature_offline( $license_key );
        if ( ! $payload ) {
            update_option( self::CACHE_KEY, 'invalid' );
            return;
        }

        // 2. Call remote heartbeat API
        $domain     = wp_parse_url( home_url(), PHP_URL_HOST );
        $machine_id = md5( home_url() );

        $response = $client->heartbeat( $license_key, $domain, $machine_id );

        if ( ! empty( $response['success'] ) && $response['success'] === true ) {
            $status = $response['status'] ?? 'active';
            if ( $status === 'active' || $status === 'generated' ) {
                update_option( self::CACHE_KEY, 'valid' );
            } else {
                update_option( self::CACHE_KEY, 'invalid' );
            }
            if ( isset( $response['expires_at'] ) ) {
                update_option( 'ratul_act_license_expires_at', $response['expires_at'] );
            }
            update_option( 'ratul_act_license_last_check', time() );
        } else {
            // If server explicitly states installation registration not found, invalidate license
            if ( isset( $response['error'] ) && strpos( $response['error'], 'registration not found' ) !== false ) {
                update_option( self::CACHE_KEY, 'invalid' );
            }
        }
    }
}

/**
 * CRXSM License Verification Client Helper Class
 */
class CRXSM_License_Client {

    private string $api_url;
    private string $client_id;
    private string $client_secret;
    private string $public_key;

    /**
     * Constructor
     */
    public function __construct( string $api_url, string $client_id, string $client_secret, string $public_key ) {
        $this->api_url       = rtrim( $api_url, '/' );
        $this->client_id     = $client_id;
        $this->client_secret = $client_secret;
        $this->public_key    = hex2bin( $public_key );
    }

    /**
     * Offline Signature Check (Fast, No HTTP Requests)
     */
    public function verify_signature_offline( string $license_key ) {
        $parts = explode( '.', trim( $license_key ) );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        $payload_json = $this->base64url_decode( $parts[0] );
        $signature    = $this->base64url_decode( $parts[1] );

        if ( ! $payload_json || ! $signature ) {
            return false;
        }

        if ( strlen( $signature ) !== 64 || strlen( $this->public_key ) !== 32 ) {
            return false;
        }

        if ( ! extension_loaded( 'sodium' ) ) {
            error_log( 'CRXSM Client: Sodium extension is not enabled in this PHP environment.' );
            return false;
        }

        // Verify cryptographic signature
        $is_valid = sodium_crypto_sign_verify_detached( $signature, $payload_json, $this->public_key );
        if ( ! $is_valid ) {
            return false;
        }

        $payload = json_decode( $payload_json, true );
        if ( ! is_array( $payload ) ) {
            return false;
        }

        // Check expiration date locally
        if ( isset( $payload['expires_at'] ) && $payload['expires_at'] !== null ) {
            $expiry_time = strtotime( $payload['expires_at'] );
            if ( $expiry_time && time() > $expiry_time ) {
                return false; // Expired locally
            }
        }

        return $payload;
    }

    /**
     * Calls remote activation endpoint.
     */
    public function activate_license( string $license_key, string $domain, string $machine_id ): array {
        return $this->send_api_request( 'activate', [
            'license_key' => $license_key,
            'domain'      => $domain,
            'machine_id'  => $machine_id,
        ] );
    }

    /**
     * Calls remote deactivation endpoint.
     */
    public function deactivate_license( string $license_key, string $domain, string $machine_id ): array {
        return $this->send_api_request( 'deactivate', [
            'license_key' => $license_key,
            'domain'      => $domain,
            'machine_id'  => $machine_id,
        ] );
    }

    /**
     * Calls remote heartbeat check-in endpoint.
     */
    public function heartbeat( string $license_key, string $domain, string $machine_id ): array {
        return $this->send_api_request( 'heartbeat', [
            'license_key' => $license_key,
            'domain'      => $domain,
            'machine_id'  => $machine_id,
        ] );
    }

    /**
     * Decodes Base64URL string.
     */
    private function base64url_decode( string $data ): string {
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }

    /**
     * Sends signed API requests to the CRXSM Tunnel.
     */
    private function send_api_request( string $action, array $payload ): array {
        $timestamp        = (string) time();
        $nonce            = bin2hex( random_bytes( 16 ) );
        $raw_post_payload = json_encode( $payload );

        $data_to_sign = $timestamp . '.' . $nonce . '.' . $raw_post_payload;
        $signature    = hash_hmac( 'sha256', $data_to_sign, $this->client_secret );

        $url = add_query_arg( 'action', $action, $this->api_url );

        $args = [
            'method'    => 'POST',
            'headers'   => [
                'Content-Type'      => 'application/json; charset=utf-8',
                'X-CRXSM-Client-ID' => $this->client_id,
                'X-CRXSM-Timestamp' => $timestamp,
                'X-CRXSM-Nonce'     => $nonce,
                'X-CRXSM-Signature' => $signature,
            ],
            'body'      => $raw_post_payload,
            'timeout'   => 15,
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return [ 'success' => false, 'error' => 'Invalid server response. HTTP Code: ' . $code ];
        }

        return $data;
    }
}
