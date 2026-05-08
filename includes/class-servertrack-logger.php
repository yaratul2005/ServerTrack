<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Logger {

    /**
     * Logs an event send attempt.
     * 
     * @param string $status  'success', 'error', 'skipped', 'dedup_blocked'
     * @param string $platform 'meta', 'google', 'tiktok'
     * @param string $message  Message or HTTP Code
     * @param string $response API response body (optional)
     */
    public static function log( string $status, string $platform, string $message, string $response = '' ) {
        $log_entry = [
            'timestamp' => current_time( 'mysql' ),
            'platform'  => $platform,
            'status'    => $status,
            'message'   => $message,
            'response'  => $response,
        ];

        $logs = get_option( 'servertrack_debug_log', [] );
        if ( ! is_array( $logs ) ) {
            $logs = [];
        }

        array_unshift( $logs, $log_entry );

        // Keep only the last 50 entries
        if ( count( $logs ) > 50 ) {
            $logs = array_slice( $logs, 0, 50 );
        }

        update_option( 'servertrack_debug_log', $logs, false );
    }

    public static function clear_logs() {
        update_option( 'servertrack_debug_log', [], false );
    }
}
