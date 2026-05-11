<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Retry  v3.0
 *
 * Persistent retry queue for failed platform API calls.
 *
 * HIGH FIX (H-3) in v3.0:
 *   Retry UID was computed as md5(platform + event_id + attempt).
 *   Including the attempt number in the hash created a different options key
 *   for every retry attempt, bypassing the update_option() overwrite guard
 *   added in v2.1. Each failure queued an entirely new key, leaving orphaned
 *   option rows and scheduling duplicate cron entries for the same event.
 *
 *   Fix: UID is now md5(platform + event_id) — stable per logical event.
 *   Attempt count is stored inside the serialised payload, not in the key.
 *   update_option() always writes to the same key, so stale entries are
 *   correctly overwritten and the cron schedule is never duplicated.
 *
 * Backoff (max 3 attempts after initial failure):
 *   Attempt 1 → 5 min  |  Attempt 2 → 30 min  |  Attempt 3 → 2 hours
 *
 * Non-retryable (permanent): 400, 401, 403, 404, 422
 */
class ServerTrack_Retry {

    const RETRYABLE_CODES = [ 0, 429, 500, 502, 503 ];
    const PERMANENT_CODES = [ 400, 401, 403, 404, 422 ];
    const MAX_ATTEMPTS    = 3;
    const BACKOFF         = [
        1 => 5  * MINUTE_IN_SECONDS,
        2 => 30 * MINUTE_IN_SECONDS,
        3 => 2  * HOUR_IN_SECONDS,
    ];
    const KEY_PREFIX = 'servertrack_retry_';

    public static function init(): void {
        add_action( 'servertrack_process_retry', [ self::class, 'process' ], 10, 1 );
    }

    /**
     * Queue a failed send for retry if the HTTP code is retryable.
     *
     * HIGH FIX (H-3 — v3.0):
     *   UID = md5( platform + '_' + event_id )   ← stable, no attempt in hash
     *   Attempt stored inside $item payload.
     *
     * @param string $platform    'meta'|'google'|'tiktok'
     * @param array  $result      Return from platform send()
     * @param array  $event_args  Serialised event (use event_to_args())
     * @param int    $attempt     1-indexed retry attempt number
     */
    public static function maybe_queue(
        string $platform,
        array  $result,
        array  $event_args,
        int    $attempt = 1
    ): void {
        $http_code = (int) ( $result['http_code'] ?? 0 );

        if ( ! in_array( $http_code, self::RETRYABLE_CODES, true ) ) return;

        if ( $attempt > self::MAX_ATTEMPTS ) {
            ServerTrack_Logger::log(
                'error', $platform,
                'Retry exhausted after ' . self::MAX_ATTEMPTS . ' attempts — event permanently dropped.',
                '', $event_args['event_id'] ?? '',
                (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
                $event_args['event_name'] ?? '', $http_code
            );
            return;
        }

        // H-3 fix: stable UID — no attempt number in hash.
        $event_id = $event_args['event_id'] ?? uniqid( $platform . '_', true );
        $uid      = md5( $platform . '_' . $event_id );

        $item = [
            'platform'   => $platform,
            'event_args' => $event_args,
            'attempt'    => $attempt,      // stored in payload, not in key
            'queued_at'  => time(),
            'expires_at' => time() + DAY_IN_SECONDS,
        ];

        // Always overwrites same key — prevents duplicate orphaned entries.
        update_option( self::KEY_PREFIX . $uid, $item, false );

        $delay = self::BACKOFF[ $attempt ] ?? self::BACKOFF[ self::MAX_ATTEMPTS ];
        wp_schedule_single_event( time() + $delay, 'servertrack_process_retry', [ $uid ] );

        ServerTrack_Logger::log(
            'queued', $platform,
            'Retry #' . $attempt . ' in ' . ( $delay / 60 ) . ' min (HTTP ' . $http_code . ')',
            '', $event_args['event_id'] ?? '',
            (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
            $event_args['event_name'] ?? '', $http_code
        );
    }

    /**
     * Cron handler — called by servertrack_process_retry hook.
     */
    public static function process( string $uid ): void {
        $item = get_option( self::KEY_PREFIX . $uid, null );
        if ( ! is_array( $item ) ) return;

        if ( isset( $item['expires_at'] ) && time() > $item['expires_at'] ) {
            delete_option( self::KEY_PREFIX . $uid );
            return;
        }

        $platform   = $item['platform']   ?? '';
        $event_args = $item['event_args'] ?? [];
        $attempt    = (int) ( $item['attempt'] ?? 1 );

        // Remove before send — prevents duplicate runs on cron overlap.
        delete_option( self::KEY_PREFIX . $uid );

        if ( empty( $platform ) || empty( $event_args ) ) return;

        $event = new ServerTrack_Event(
            $event_args['event_name'] ?? 'Purchase',
            $event_args['event_id']   ?? ''
        );
        $event->set_user_data(   $event_args['user_data']   ?? [] );
        $event->set_custom_data( $event_args['custom_data'] ?? [] );
        if ( ! empty( $event_args['event_source_url'] ) ) {
            $event->set_source_url( $event_args['event_source_url'] );
        }

        $result    = self::send_to_platform( $platform, $event );
        $http_code = (int) ( $result['http_code'] ?? 0 );
        $success   = ( $result['status'] ?? '' ) === 'success';

        if ( $success ) {
            $order_id = (int) ( $event_args['custom_data']['order_id'] ?? 0 );
            if ( $order_id > 0 ) {
                ServerTrack_Dedup::mark_as_sent( $order_id, $platform );
            }
            ServerTrack_Logger::log(
                'success', $platform,
                'Retry #' . $attempt . ' succeeded (HTTP ' . $http_code . ')',
                $result['response'] ?? '', $event_args['event_id'] ?? '',
                (int) ( $event_args['custom_data']['order_id'] ?? 0 ),
                $event_args['event_name'] ?? '', $http_code
            );
            return;
        }

        self::maybe_queue( $platform, $result, $event_args, $attempt + 1 );
    }

    private static function send_to_platform( string $platform, ServerTrack_Event $event ): array {
        switch ( $platform ) {
            case 'meta':   return ServerTrack_Meta::send( $event );
            case 'google': return ServerTrack_Google::send( $event );
            case 'tiktok': return ServerTrack_TikTok::send( $event );
            default:       return [ 'status' => 'error', 'http_code' => 0, 'response' => 'Unknown platform: ' . $platform ];
        }
    }

    /**
     * Serialise a ServerTrack_Event to the flat array format the retry
     * queue stores and maybe_queue() accepts.
     */
    public static function event_to_args( ServerTrack_Event $event ): array {
        return [
            'event_name'       => $event->event_name,
            'event_id'         => $event->event_id,
            'user_data'        => $event->user_data,
            'custom_data'      => $event->custom_data,
            'event_source_url' => $event->event_source_url,
        ];
    }

    /** Purge all queued retry rows from wp_options. */
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
