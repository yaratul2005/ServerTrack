<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Logger
 *
 * Bug fix: log was capped at 50 entries. On busy WooCommerce stores with
 * multiple platforms firing per order (Meta + TikTok + Google), 50 entries
 * can be exhausted in under 10 minutes, hiding older errors from view.
 * Raised to 200 entries (still stored as a single wp_options row — each
 * entry is small). autoload is 'no' to avoid loading 200 entries on every
 * WordPress page load.
 *
 * Bug fix: update_option() was called without the autoload parameter,
 * defaulting to 'yes'. Debug log data is only needed in the admin panel —
 * loading it on every front-end page load wastes memory. Explicitly set
 * to 'no'.
 */
class ServerTrack_Logger {

    /** Maximum number of log entries to retain. */
    const MAX_ENTRIES = 200;

    /**
     * Logs an event send attempt.
     *
     * @param string $status    'success', 'error', 'skipped', 'dedup_blocked', 'queued'
     * @param string $platform  'meta', 'google', 'tiktok', 'all'
     * @param string $message   Short description or HTTP code
     * @param string $response  Raw API response body (optional)
     * @param string $event_id  Dedup event UUID (optional)
     * @param int    $order_id  WooCommerce order ID (optional)
     * @param string $event_name  Event name e.g. 'Purchase' (optional)
     * @param int    $http_code   HTTP response code (optional)
     */
    public static function log(
        string $status,
        string $platform,
        string $message,
        string $response  = '',
        string $event_id  = '',
        int    $order_id  = 0,
        string $event_name = '',
        int    $http_code  = 0
    ) {
        $log_entry = [
            'timestamp'  => current_time( 'mysql' ),
            'platform'   => $platform,
            'status'     => $status,
            'message'    => $message,
            'response'   => $response,
            'event_id'   => $event_id,
            'order_id'   => $order_id,
            'event_name' => $event_name,
            'http_code'  => $http_code,
        ];

        $logs = get_option( 'servertrack_debug_log', [] );
        if ( ! is_array( $logs ) ) {
            $logs = [];
        }

        array_unshift( $logs, $log_entry );

        // BUG FIX: was 50 — raised to 200 so errors on busy stores are not
        // immediately overwritten by success entries.
        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, 0, self::MAX_ENTRIES );
        }

        // BUG FIX: autoload='no' — debug log is admin-only data, no need to
        // load it on every front-end page load.
        update_option( 'servertrack_debug_log', $logs, false );
    }

    public static function clear_logs(): void {
        update_option( 'servertrack_debug_log', [], false );
    }
}
