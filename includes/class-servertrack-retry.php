<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Retry  v2.1
 *
 * Persistent retry queue for failed platform API calls.
 *
 * When a platform send() returns a retryable HTTP code (0, 429, 500, 502, 503),
 * the event payload is serialised into a wp_options entry and a WP-Cron job
 * is scheduled to re-attempt delivery with exponential backoff.
 *
 * Backoff schedule (max 3 attempts after the initial failure):
 *   Attempt 1 → 5 minutes
 *   Attempt 2 → 30 minutes
 *   Attempt 3 → 2 hours
 *
 * Non-retryable codes (permanent failures — never queued):
 *   400, 401, 403, 404, 422
 *
 * Storage:
 *   Each queued item is stored as a single wp_options row:
 *   Key: servertrack_retry_{$uid}
 *   TTL: 24 hours (cleaned up after final attempt or success)
 *
 * CRITICAL FIX (v2.1):
 *   Previously used add_option() to persist retry payloads.
 *   add_option() silently does NOTHING if the option key already exists.
 *   Scenario: a retry fires, fails again, and re-queues with the same UID —
 *   the updated payload is never written, so the next retry attempt reads
 *   the stale original payload and loops forever with wrong data.
 *   Fixed: replaced add_option() with update_option() which always writes.
 *
 * Cron hook: servertrack_process_retry
 * Registered in: ServerTrack_Core::init()
 */
class ServerTrack_Retry {

    /** HTTP codes that are safe to retry. */
    const RETRYABLE_CODES = [ 0, 429, 500, 502, 503 ];

    /** HTTP codes that are permanent failures — never retry. */
    const PERMANENT_CODES = [ 400, 401, 403, 404, 422 ];

    /** Maximum retry attempts after the initial failure. */
    const MAX_ATTEMPTS = 3;

    /** Backoff delays in seconds for each attempt (1-indexed). */
    const BACKOFF = [
        1 => 5 * MINUTE_IN_SECONDS,
        2 => 30 * MINUTE_IN_SECONDS,
        3 => 2 * HOUR_IN_SECONDS,
    ];

    /** Option key prefix. */
    const KEY_PREFIX = 'servertrack_retry_';

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Registers the cron hook. Called from ServerTrack_Core::init().
     */
    public static function init(): void {
        add_action( 'servertrack_process_retry', [ self::class, 'process' ], 10, 1 );
    }

    /**
     * Evaluates the result of a platform send() call and, if the failure
     * is retryable, queues the event for a future re-attempt.
     *
     * @param string $platform   'meta' | 'google' | 'tiktok'
     * @param array  $result     Return value from the platform send() method.
     * @param array  $event_args [ event_name, event_id, user_data, custom_data ]
     * @param int    $attempt    Current attempt number (1 = first retry queue).
     */
    public static function maybe_queue(
        string $platform,
        array  $result,
        array  $event_args,
        int    $attempt = 1
    ): void {
        $http_code = (int) ( $result['http_code'] ?? 0 );

        // Only queue on retryable failures
        if ( ! in_array( $http_code, self::RETRYABLE_CODES, true ) ) {
            return;
        }

        // Do not keep retrying beyond max attempts
        if ( $attempt > self::MAX_ATTEMPTS ) {
            ServerTrack_Logger::log(
                'error', $platform,
                'Retry exhausted after ' . self::MAX_ATTEMPTS . ' attempts. Event permanently dropped.',
                '', $event_args['event_id'] ?? '', (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
                $event_args['event_name'] ?? '', $http_code
            );
            return;
        }

        $uid  = md5( $platform . '_' . ( $event_args['event_id'] ?? uniqid( '', true ) ) . '_' . $attempt );
        $item = [
            'platform'   => $platform,
            'event_args' => $event_args,
            'attempt'    => $attempt,
            'queued_at'  => time(),
            'expires_at' => time() + DAY_IN_SECONDS,
        ];

        // CRITICAL FIX: was add_option() which silently does nothing if the key
        // already exists. If the same event is re-queued (e.g., retry fails again
        // with the same UID), the updated payload was never written — the retry
        // loop read stale data and could cycle forever.
        // update_option() always writes, regardless of whether the key exists.
        update_option( self::KEY_PREFIX . $uid, $item, false ); // false = no autoload

        // Schedule the retry cron
        $delay = self::BACKOFF[ $attempt ] ?? self::BACKOFF[ self::MAX_ATTEMPTS ];
        wp_schedule_single_event(
            time() + $delay,
            'servertrack_process_retry',
            [ $uid ]
        );

        ServerTrack_Logger::log(
            'queued', $platform,
            'Retry #' . $attempt . ' scheduled in ' . ( $delay / 60 ) . ' min. HTTP ' . $http_code,
            '', $event_args['event_id'] ?? '', (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
            $event_args['event_name'] ?? '', $http_code
        );
    }

    /**
     * Cron handler — called by servertrack_process_retry hook.
     * Retrieves the queued item, rebuilds the event, and re-sends.
     *
     * @param string $uid  Unique ID of the queued item.
     */
    public static function process( string $uid ): void {
        $item = get_option( self::KEY_PREFIX . $uid, null );

        if ( ! is_array( $item ) ) {
            return; // Already processed or expired
        }

        // Hard TTL — drop items older than 24 hours
        if ( isset( $item['expires_at'] ) && time() > $item['expires_at'] ) {
            delete_option( self::KEY_PREFIX . $uid );
            return;
        }

        $platform   = $item['platform']   ?? '';
        $event_args = $item['event_args'] ?? [];
        $attempt    = (int) ( $item['attempt'] ?? 1 );

        // Always clean up the stored item first — prevents duplicate runs
        delete_option( self::KEY_PREFIX . $uid );

        if ( empty( $platform ) || empty( $event_args ) ) {
            return;
        }

        // Rebuild the ServerTrack_Event DTO from stored args
        $event = new ServerTrack_Event(
            $event_args['event_name'] ?? 'Purchase',
            $event_args['event_id']   ?? ''
        );
        $event->set_user_data(   $event_args['user_data']   ?? [] );
        $event->set_custom_data( $event_args['custom_data'] ?? [] );

        // Re-attempt the send
        $result = self::send_to_platform( $platform, $event );

        $http_code = (int) ( $result['http_code'] ?? 0 );
        $success   = ( $result['status'] ?? '' ) === 'success';

        if ( $success ) {
            $order_id = (int) ( $event_args['custom_data']['order_id'] ?? 0 );
            if ( $order_id > 0 ) {
                ServerTrack_Dedup::mark_as_sent( $order_id, $platform );
            }
            ServerTrack_Logger::log(
                'success', $platform,
                'Retry #' . $attempt . ' succeeded. HTTP ' . $http_code,
                $result['response'] ?? '', $event_args['event_id'] ?? '',
                (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
                $event_args['event_name'] ?? '', $http_code
            );
            return;
        }

        // Still failing — queue next attempt if retryable
        self::maybe_queue( $platform, $result, $event_args, $attempt + 1 );
    }

    // ── Platform dispatcher ───────────────────────────────────────────────

    /**
     * Routes the event to the correct platform send() method.
     *
     * @return array { status, http_code, response }
     */
    private static function send_to_platform( string $platform, ServerTrack_Event $event ): array {
        switch ( $platform ) {
            case 'meta':
                return ServerTrack_Meta::send( $event );
            case 'google':
                return ServerTrack_Google::send( $event );
            case 'tiktok':
                return ServerTrack_TikTok::send( $event );
            default:
                return [ 'status' => 'error', 'http_code' => 0, 'response' => 'Unknown platform: ' . $platform ];
        }
    }

    // ── Utility: build event_args array from a ServerTrack_Event ─────────

    /**
     * Serialises a ServerTrack_Event into the flat array format used
     * by the retry queue. Call this immediately after a failed send().
     */
    public static function event_to_args( ServerTrack_Event $event ): array {
        return [
            'event_name'  => $event->event_name,
            'event_id'    => $event->event_id,
            'user_data'   => $event->user_data,
            'custom_data' => $event->custom_data,
        ];
    }

    // ── Cleanup: remove all queued items (called on deactivation) ─────────

    /**
     * Deletes all pending retry option rows.
     * Called from the deactivation hook in servertrack-native.php.
     */
    public static function flush(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::KEY_PREFIX ) . '%'
            )
        );
    }
}
