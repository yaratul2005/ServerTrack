<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Logger  v3.0
 *
 * CRITICAL FIX (v3.0) — C-1: Logger gated behind debug_mode → silent production loss.
 *
 * Root cause:
 *   Logger::log() returned early when servertrack_debug_mode = 0 (production default).
 *   This silently killed everything downstream:
 *     - do_action('servertrack_event_logged') never fired → webhooks never triggered.
 *     - Retry queue log calls were swallowed → failed events vanished without trace.
 *     - Dashboard AJAX reads the log → showed zero stats in production.
 *
 * Fix — two-layer architecture:
 *   Layer 1 (always-on): Every log() call writes a minimal stat entry to
 *     'servertrack_event_stats' (timestamp, status, platform, event_type, order_id).
 *     This is ALWAYS written, debug_mode has no effect on it.
 *     Used by: dashboard stats, webhook action, retry queue.
 *   Layer 2 (debug-only): Full verbose entry (message, response, event_id, emq)
 *     written to 'servertrack_debug_log' ONLY when debug_mode = 1.
 *     Used by: admin debug log viewer.
 *
 *   do_action('servertrack_event_logged') now fires ALWAYS (after layer 1 write),
 *   so webhooks and all downstream hooks are never gated behind debug_mode.
 *
 * Back-compat:
 *   - log() signature is identical — all call sites unchanged.
 *   - get_recent() still reads verbose debug log (unchanged).
 *   - New get_stats() reads the always-on stats log.
 *
 * Changes in v2.1:
 *   - log() fires do_action('servertrack_event_logged') after writing.
 *
 * Changes in v2.0:
 *   - log() accepts optional $emq array and persists emq_score + emq_grade.
 *   - Log entries include event_type field.
 *   - Max log size increased from 500 to 1000 entries (FIFO pruning).
 *
 * Log entry format (verbose debug log):
 * [
 *   'timestamp'  => '2026-05-11 08:30:00',
 *   'status'     => 'success'|'error'|'skipped'|'queued'|'dedup_blocked',
 *   'platform'   => 'meta'|'tiktok'|'google'|'all'|'identity',
 *   'message'    => 'Human-readable description',
 *   'event_id'   => 'uuid-v4-string',
 *   'order_id'   => 12345,
 *   'event_type' => 'Purchase'|'ViewContent'|...,
 *   'emq_score'  => 7.2,   // optional
 *   'emq_grade'  => 'good', // optional
 * ]
 *
 * Stat entry format (always-on stats log):
 * [
 *   'timestamp'  => '2026-05-11 08:30:00',
 *   'status'     => 'success'|'error'|'skipped'|'queued'|'dedup_blocked',
 *   'platform'   => 'meta'|'tiktok'|'google'|'all'|'identity',
 *   'event_type' => 'Purchase'|'ViewContent'|...,
 *   'order_id'   => 12345,
 * ]
 */
class ServerTrack_Logger {

    /** Option key for full verbose debug log (debug_mode=1 only). */
    const OPTION_KEY  = 'servertrack_debug_log';

    /**
     * Option key for always-on lightweight stats log.
     * Written on every log() call regardless of debug_mode.
     * Read by dashboard AJAX, webhooks, and retry queue reporters.
     */
    const STATS_KEY   = 'servertrack_event_stats';

    const MAX_ENTRIES = 1000;

    /**
     * Append a log entry.
     *
     * CRITICAL FIX (v3.0): No longer gated behind debug_mode.
     * Two-layer write — stat entry always, verbose entry only in debug mode.
     * do_action('servertrack_event_logged') always fires.
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

        // ── Layer 1: Always-on stat entry ─────────────────────────────────
        // Written regardless of debug_mode. Powers dashboard stats + webhooks.
        $stat_entry = [
            'timestamp'  => current_time( 'Y-m-d H:i:s' ),
            'status'     => $status,
            'platform'   => $platform,
            'event_type' => $event_type,
            'order_id'   => $order_id,
        ];

        $stats   = get_option( self::STATS_KEY, [] );
        $stats[] = $stat_entry;
        if ( count( $stats ) > self::MAX_ENTRIES ) {
            $stats = array_slice( $stats, -self::MAX_ENTRIES );
        }
        update_option( self::STATS_KEY, $stats, false );

        // ── Layer 2: Verbose debug entry (debug_mode only) ────────────────
        if ( get_option( 'servertrack_debug_mode', 0 ) ) {
            $entry = [
                'timestamp'  => $stat_entry['timestamp'],
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
            if ( count( $logs ) > self::MAX_ENTRIES ) {
                $logs = array_slice( $logs, -self::MAX_ENTRIES );
            }
            update_option( self::OPTION_KEY, $logs, false );
        }

        // ── Always fire downstream hooks ──────────────────────────────────
        /**
         * Fires after a CAPI event is logged.
         *
         * CRITICAL FIX (v3.0): Previously only fired when debug_mode=1.
         * Now fires on EVERY log() call so webhooks and all downstream
         * hooks are never silently blocked in production.
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
     * Clear all verbose debug log entries.
     */
    public static function clear(): void {
        update_option( self::OPTION_KEY, [], false );
    }

    /**
     * Clear the always-on stats log.
     */
    public static function clear_stats(): void {
        update_option( self::STATS_KEY, [], false );
    }

    /**
     * Get recent verbose debug log entries (most recent first).
     * Only populated when debug_mode = 1.
     *
     * @param int $limit  Max entries to return
     * @return array
     */
    public static function get_recent( int $limit = 100 ): array {
        $logs = get_option( self::OPTION_KEY, [] );
        return array_slice( array_reverse( $logs ), 0, $limit );
    }

    /**
     * Get recent stat entries from the always-on stats log (most recent first).
     * Available in production regardless of debug_mode.
     *
     * @param int $limit  Max entries to return
     * @return array
     */
    public static function get_stats( int $limit = 100 ): array {
        $stats = get_option( self::STATS_KEY, [] );
        return array_slice( array_reverse( $stats ), 0, $limit );
    }
}
