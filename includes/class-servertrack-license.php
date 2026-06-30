<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}

/**
 * ServerTrack_License
 *
 * Handles license key validation and communication with the WC Key Manager API.
 */
class ServerTrack_License {

    const STORE_URL = 'https://great10.xyz';
    const ITEM_ID = ''; 
    const CACHE_KEY = 'servertrack_license_status';

    public static function init(): void {
        // Disabled for WordPress.org hosted version.
    }

    /**
     * Checks if the current license is active.
     */
    public static function is_active(): bool {
        return true;
    }

    /**
     * Activates a license key with the central server.
     */
    public static function activate( string $license_key ): array {
        return [ 'success' => true, 'message' => 'License activated successfully.' ];
    }

    /**
     * Deactivates a license key.
     */
    public static function deactivate(): array {
        return [ 'success' => true, 'message' => 'License deactivated successfully.' ];
    }

    /**
     * Checks for updates from the licensing server.
     */
    public static function check_for_updates(): array {
        return [];
    }

    /**
     * Parse array data securely, expecting native array or JSON string.
     *
     * Replacing unserialize with JSON decoding mitigates PHP Object Injection vulnerabilities.
     *
     * @param mixed $data The data to parse.
     * @return array The parsed array data.
     */
    private static function parse_array_data( $data ): array {
        if ( is_array( $data ) ) {
            return $data;
        }
        if ( is_string( $data ) ) {
            $decoded = json_decode( $data, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        return [];
    }
}