<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Logger  v2.1
 *
 * Feature #7 — Enhanced Debug Logger with EMQ Score Storage.
 *
 * Changes in v2.1:
 *   - log() now fires do_action('servertrack_event_logged') after writing
 *     the entry, so ServerTrack_Webhook::maybe_fire_webhook() is triggered.
 *     Arguments: ( $platform, $event_type, $order_id, $status, $emq )
 *
 * Changes in v2.0:
 *   - log() accepts optional $emq array and persists emq_score + emq_grade.
 *   - Log entries now include event_type field.
 *   - Max log size increased from 500 to 1000 entries (FIFO pruning).
 *
 * Log entry format:
 * [
 *   'timestamp'  => '2026-05-11 08:30:00',
 *   'status'     => 'success'|'error'|'skipped'|'queued'|'dedup_blocked',
 *   'platform'   => 'meta'|'tiktok'|'google'|'all'|'identity',
 *   'message'    => 'Human-readable description',
 *   'event_id'   => 'uuid-v4-string',
 *   'order_id'   => 12345,
 *   'event_type' => 'Purchase'|'ViewContent'|...,
 *   'emq_score'  => 7.2,   // optional — only present when EMQ was computed
 *   'emq_grade'  => 'good', // optional — only present when EMQ was computed
 * ]
 *
 * Usage (same signature as v2.0, emq is optional):
 *   ServerTrack_Logger::log('success','meta','Order sent','event-id-here',12345,'Purchase');
 *   ServerTrack_Logger::log('success','meta','Order sent','','event-id',12345,'Purchase', ['score'=>7.2,'grade'=>'good']);
 */
class ServerTrack_Logger {

    const OPTION_KEY  = 'servertrack_debug_log';
    const MAX_ENTRIES = 1000;

    /**
     * Append a log entry and broadcast the servertrack_event_logged action.
     *
     * @param string $status      success|error|skipped|queued|dedup_blocked|webhook
     * @param string $platform    meta|tiktok|google|all|identity|webhook
     * @param string $message     Human-readable description
     * @param string $response    Raw API response string (optional)
     * @param string $event_id    UUID event ID (optional)
     * @param int    $order_id    WC order ID (optional, 0 for non-order events)
     * @param string $event_type  Purchase|ViewContent|AddToCart|... (optional)
     * @param array  $emq         [ 'score' => float, 'grade' => string ] (optional)
     */
    public static function log(
        string $status,
        string $platform,
        string $message,
        string $response   = '',
        string $event_id   = '',
        int    $order_id   = 0,
        string $event_type = '',
        array  $emq        = []
    ): void {
        if ( ! get_option( 'servertrack_debug_mode', 0 ) ) {
            return;
        }

        $entry = [
            'timestamp'  => current_time( 'Y-m-d H:i:s' ),
            'status'     => $status,
            'platform'   => $platform,
            'message'    => $message,
            'event_id'   => $event_id,
            'order_id'   => $order_id,
            'event_type' => $event_type,
        ];

        if ( ! empty( $emq ) && isset( $emq['score'] ) ) {
            $entry['emq_score'] = $emq['score'];
            $entry['emq_grade'] = $emq['grade'] ?? '';
        }

        $logs   = get_option( self::OPTION_KEY, [] );
        $logs[] = $entry;

        // Prune oldest entries if limit exceeded
        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $logs, false );

        /**
         * Fires after a CAPI event is logged.
         *
         * Used by ServerTrack_Webhook::maybe_fire_webhook() to dispatch
         * outbound webhook notifications. Fires even when the log entry
         * is written — webhooks apply their own event/status filtering.
         *
         * @param string $platform   e.g. 'meta', 'tiktok', 'google'
         * @param string $event_type e.g. 'Purchase', 'ViewContent'
         * @param int    $order_id   WC order ID (0 for non-order events)
         * @param string $status     'success'|'error'|'skipped'|...
         * @param array  $emq        EMQ data (may be empty array)
         */
        do_action( 'servertrack_event_logged', $platform, $event_type, $order_id, $status, $emq );
    }

    /**
     * Clear all log entries.
     */
    public static function clear(): void {
        update_option( self::OPTION_KEY, [], false );
    }

    /**
     * Get recent log entries.
     *
     * @param int $limit  Max entries to return (most recent first)
     * @return array
     */
    public static function get_recent( int $limit = 100 ): array {
        $logs = get_option( self::OPTION_KEY, [] );
        return array_slice( array_reverse( $logs ), 0, $limit );
    }
}
