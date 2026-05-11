<?php
/**
 * ServerTrack — Webhook Outbound (Feature #9)
 *
 * Fires an outbound webhook to any configured URL every time ServerTrack
 * logs a CAPI event. This enables:
 *   - CRM integrations (sync purchase events to HubSpot, Klaviyo, etc.)
 *   - Custom dashboards (stream events to your own analytics)
 *   - Zapier / Make.com automations
 *   - Third-party audit logging
 *
 * Configuration (WP options):
 *   servertrack_webhook_url      — HTTPS endpoint to receive events
 *   servertrack_webhook_secret   — HMAC-SHA256 signing secret
 *   servertrack_webhook_events   — comma-separated event names to fire for
 *                                  (empty = all events)
 *   servertrack_webhook_enabled  — 0|1 toggle
 *
 * Payload format (JSON POST body):
 * {
 *   "event":     "Purchase",
 *   "platform":  "meta",
 *   "order_id":  123,
 *   "status":    "success",
 *   "emq":       { "score": 8.5, "grade": "excellent" },
 *   "timestamp": 1715000000,
 *   "site_url":  "https://mystore.com"
 * }
 *
 * Security: X-ServerTrack-Signature header contains
 *   HMAC-SHA256(secret, raw_body) in hex.
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Webhook {

    /**
     * Register hooks.
     */
    public static function init(): void {
        // Hook into the logger action added in Logger v2.1
        add_action( 'servertrack_event_logged', [ __CLASS__, 'maybe_fire_webhook' ], 10, 5 );

        // Async delivery handler
        add_action( 'servertrack_deliver_webhook', [ __CLASS__, 'deliver_webhook' ], 10, 6 );
    }

    /**
     * Decide whether to fire the webhook and dispatch async.
     *
     * Signature matches do_action('servertrack_event_logged', ...) in Logger v2.1:
     *   ( $platform, $event_type, $order_id, $status, $emq )
     *
     * @param string $platform   e.g. 'meta', 'tiktok', 'google'
     * @param string $event_name e.g. 'Purchase', 'ViewContent'
     * @param int    $order_id
     * @param string $status     'success'|'error'|...
     * @param array  $emq        EMQ score data
     */
    public static function maybe_fire_webhook(
        string $platform,
        string $event_name,
        int    $order_id,
        string $status,
        array  $emq
    ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $url = trim( (string) get_option( 'servertrack_webhook_url', '' ) );
        if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return;
        }

        // Filter by event name if configured
        $allowed_events = self::get_allowed_events();
        if ( ! empty( $allowed_events ) && ! in_array( $event_name, $allowed_events, true ) ) {
            return;
        }

        // Schedule async delivery (don't block the current request)
        wp_schedule_single_event( time() + 2, 'servertrack_deliver_webhook', [
            $platform, $event_name, $order_id, $status, $emq, $url,
        ] );
    }

    /**
     * Async cron handler: build payload and POST to webhook URL.
     *
     * @param string $platform
     * @param string $event_name
     * @param int    $order_id
     * @param string $status
     * @param array  $emq
     * @param string $url
     */
    public static function deliver_webhook(
        string $platform,
        string $event_name,
        int    $order_id,
        string $status,
        array  $emq,
        string $url
    ): void {
        $payload = [
            'event'     => $event_name,
            'platform'  => $platform,
            'order_id'  => $order_id,
            'status'    => $status,
            'emq'       => $emq,
            'timestamp' => time(),
            'site_url'  => get_site_url(),
            'plugin'    => 'ServerTrack/' . SERVERTRACK_VERSION,
        ];

        $payload  = apply_filters( 'servertrack_webhook_payload', $payload );
        $raw_body = wp_json_encode( $payload );
        $secret   = (string) get_option( 'servertrack_webhook_secret', '' );

        $headers = [
            'Content-Type'           => 'application/json',
            'X-ServerTrack-Version'  => SERVERTRACK_VERSION,
            'X-ServerTrack-Event'    => $event_name,
            'X-ServerTrack-Platform' => $platform,
        ];

        if ( $secret ) {
            $headers['X-ServerTrack-Signature'] = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
        }

        $response = wp_remote_post( $url, [
            'headers'   => $headers,
            'body'      => $raw_body,
            'timeout'   => 10,
            'blocking'  => true,
            'sslverify' => true,
        ] );

        // FIX #4: Logger::log() correct arg order:
        //   ( status, platform, message, response, event_id, order_id, event_type )
        if ( get_option( 'servertrack_debug_mode' ) ) {
            $http_code = is_wp_error( $response )
                ? 0
                : wp_remote_retrieve_response_code( $response );

            $log_status = ( $http_code >= 200 && $http_code < 300 ) ? 'success' : 'error';

            ServerTrack_Logger::log(
                $log_status,                     // status
                'webhook',                       // platform
                'Webhook delivery → ' . $url,   // message
                (string) $http_code,             // response
                '',                              // event_id
                $order_id,                       // order_id
                $event_name                      // event_type
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public static function is_enabled(): bool {
        return (bool) get_option( 'servertrack_webhook_enabled', 0 );
    }

    /**
     * Get array of allowed event names (empty array = all events).
     *
     * @return string[]
     */
    public static function get_allowed_events(): array {
        $raw = (string) get_option( 'servertrack_webhook_events', '' );
        if ( ! $raw ) {
            return [];
        }
        return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
    }

    /**
     * Send a test webhook to verify the endpoint is reachable.
     *
     * @param string $url
     * @param string $secret
     * @return array { success: bool, message: string, http_code: int }
     */
    public static function send_test( string $url, string $secret = '' ): array {
        $payload  = [
            'event'     => 'Test',
            'platform'  => 'servertrack',
            'order_id'  => 0,
            'status'    => 'success',
            'emq'       => [],
            'timestamp' => time(),
            'site_url'  => get_site_url(),
            'plugin'    => 'ServerTrack/' . SERVERTRACK_VERSION,
            'message'   => 'This is a test webhook from ServerTrack.',
        ];
        $raw_body = wp_json_encode( $payload );

        $headers = [
            'Content-Type'          => 'application/json',
            'X-ServerTrack-Version' => SERVERTRACK_VERSION,
            'X-ServerTrack-Event'   => 'Test',
        ];
        if ( $secret ) {
            $headers['X-ServerTrack-Signature'] = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
        }

        $response = wp_remote_post( $url, [
            'headers'  => $headers,
            'body'     => $raw_body,
            'timeout'  => 15,
            'blocking' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message(), 'http_code' => 0 ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        return [
            'success'   => $code >= 200 && $code < 300,
            'message'   => wp_remote_retrieve_response_message( $response ),
            'http_code' => $code,
        ];
    }
}
