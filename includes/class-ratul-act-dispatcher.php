<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ratul_ACT_Dispatcher  v1.0
 *
 * Async dispatch pipeline for Ratul_ACT.
 * Uses wp_remote_post loopback mechanism to avoid blocking checkout.
 */
class Ratul_ACT_Dispatcher {

    const ACTION_NAME = 'ratul_act_async_dispatch';

    public static function init(): void {
        add_action( 'admin_post_nopriv_' . self::ACTION_NAME, [ self::class, 'process_dispatch' ] );
        add_action( 'admin_post_' . self::ACTION_NAME, [ self::class, 'process_dispatch' ] );
    }

    /**
     * Dispatch an event to the requested platforms asynchronously.
     *
     * @param Ratul_ACT_Event $event
     * @param array $platforms
     * @param string|int|null $dedup_key
     */
    public static function dispatch( Ratul_ACT_Event $event, array $platforms, $dedup_key = null ): void {
        $key = (string) ( $dedup_key ?? $event->event_id );
        $secret = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'ratul_act_salt';
        $token  = hash_hmac( 'sha256', self::ACTION_NAME, $secret );
        $payload = [
            'action'    => self::ACTION_NAME,
            'event'     => wp_json_encode( Ratul_ACT_Retry::event_to_args( $event ) ),
            'platforms' => wp_json_encode( $platforms ),
            'dedup_key' => $key,
            'token'     => $token,
        ];

        $response = wp_remote_post( admin_url( 'admin-post.php' ), [
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'body'      => $payload,
        ] );

        if ( is_wp_error( $response ) ) {
            Ratul_ACT_Logger::warning(
                'Async dispatch loopback failed. Events will retry via WP-Cron if configured.',
                [ 'error' => $response->get_error_message(), 'payload_action' => $payload['action'] ]
            );
        }
    }

    /**
     * Handle the async dispatch loopback.
     */
    public static function process_dispatch(): void {
        if ( empty( $_POST['action'] ) || $_POST['action'] !== self::ACTION_NAME ) {
            return;
        }

        $secret = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'ratul_act_salt';
        $expected_token = hash_hmac( 'sha256', self::ACTION_NAME, $secret );
        if ( empty( $_POST['token'] ) || ! hash_equals( $expected_token, $_POST['token'] ) ) {
            return;
        }

        $event_data = isset( $_POST['event'] ) ? json_decode( wp_unslash( $_POST['event'] ), true ) : [];
        $platforms  = isset( $_POST['platforms'] ) ? json_decode( wp_unslash( $_POST['platforms'] ), true ) : [];
        $dedup_key  = isset( $_POST['dedup_key'] ) ? sanitize_text_field( wp_unslash( $_POST['dedup_key'] ) ) : '';

        if ( empty( $event_data ) || empty( $platforms ) ) {
            return;
        }

        $event = new Ratul_ACT_Event( $event_data['event_name'] ?? '', $event_data['event_id'] ?? '' );
        $event->set_user_data( $event_data['user_data'] ?? [] )
              ->set_custom_data( $event_data['custom_data'] ?? [] );

        if ( ! empty( $event_data['event_source_url'] ) ) {
            $event->set_source_url( $event_data['event_source_url'] );
        }

        foreach ( $platforms as $platform ) {
            if ( Ratul_ACT_Dedup::already_sent( $dedup_key, $platform ) ) {
                continue;
            }

            $result = [ 'status' => 'skipped' ];

            switch ( $platform ) {
                case 'meta':
                    if ( get_option( 'ratul_act_meta_enabled', 0 ) ) {
                        $result = Ratul_ACT_Meta::send( $event );
                    }
                    break;
                case 'tiktok':
                    if ( get_option( 'ratul_act_tiktok_enabled', 0 ) ) {
                        $result = Ratul_ACT_TikTok::send( $event );
                    }
                    break;
                case 'google':
                    if ( get_option( 'ratul_act_google_enabled', 0 ) ) {
                        $result = Ratul_ACT_Google::send( $event );
                    }
                    break;
                case 'snapchat':
                    if ( get_option( 'ratul_act_snapchat_enabled', 0 ) ) {
                        $result = Ratul_ACT_Snapchat::send( $event );
                    }
                    break;
                case 'pinterest':
                    if ( get_option( 'ratul_act_pinterest_enabled', 0 ) ) {
                        $result = Ratul_ACT_Pinterest::send( $event );
                    }
                    break;
                case 'linkedin':
                    if ( get_option( 'ratul_act_linkedin_enabled', 0 ) ) {
                        $result = Ratul_ACT_LinkedIn::send( $event );
                    }
                    break;
            }

            if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
                Ratul_ACT_Dedup::mark_sent( $dedup_key, $platform );
            } elseif ( isset( $result['status'] ) && $result['status'] === 'error' ) {
                Ratul_ACT_Retry::maybe_queue( $platform, $result, $event_data );
            }
        }
    }
}

