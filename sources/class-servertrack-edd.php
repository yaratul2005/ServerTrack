<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_EDD {

    public static function init() {
        if ( ! get_option( 'servertrack_source_edd_enabled', 0 ) ) return;
        if ( ! function_exists( 'EDD' ) ) return;

        add_action( 'edd_complete_purchase',  [ self::class, 'on_purchase' ],      10, 1 );
        add_action( 'edd_user_registration',  [ self::class, 'on_registration' ],  10, 3 );
        add_action( 'servertrack_send_edd_purchase', [ self::class, 'send_purchase_async' ], 10, 1 );
    }

    public static function on_purchase( int $payment_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( 'edd_purchase_' . $payment_id );
        ServerTrack_Dedup::store_event_id( $payment_id, $event_id );

        wp_schedule_single_event( time(), 'servertrack_send_edd_purchase', [ $payment_id ] );
    }

    public static function send_purchase_async( int $payment_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;



        $event_id = ServerTrack_Dedup::get_event_id( $payment_id );

        $payment = edd_get_payment( $payment_id );
        if ( ! $payment ) return;

        $meta      = edd_get_payment_meta( $payment_id );
        $user_info = $meta['user_info'] ?? [];

        $user_data = [];

        $email = $payment->email;
        if ( ! empty( $email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $email );
        }
        if ( ! empty( $user_info['first_name'] ) ) {
            $user_data['first_name'] = ServerTrack_Hasher::hash( $user_info['first_name'] );
        }
        if ( ! empty( $user_info['last_name'] ) ) {
            $user_data['last_name'] = ServerTrack_Hasher::hash( $user_info['last_name'] );
        }

        $ip = $payment->ip;
        if ( ! empty( $ip ) ) $user_data['ip'] = $ip;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $user_data['gclid'] = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );

        $downloads = $meta['downloads'] ?? [];
        $contents  = [];
        foreach ( $downloads as $download ) {
            $contents[] = [
                'id'         => $download['id'],
                'quantity'   => $download['quantity'] ?? 1,
                'item_price' => (float) ( $download['price'] ?? 0 ),
            ];
        }

        $custom_data = [
            'currency'     => $payment->currency,
            'value'        => (float) $payment->total,
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => $payment_id,
        ];

        // Meta
        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'meta' ) ) {
                if ( ServerTrack_Consent::is_granted( 'meta' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_Meta::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'meta' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }

        // Google
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'google' ) ) {
                if ( ServerTrack_Consent::is_granted( 'google' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_Google::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'google' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }

        // TikTok
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $payment_id, 'tiktok' ) ) {
                if ( ServerTrack_Consent::is_granted( 'tiktok' ) ) {
                    $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )
                        ->set_user_data( $user_data )
                        ->set_custom_data( $custom_data );
                    ServerTrack_TikTok::send( $e );
                    ServerTrack_Dedup::mark_as_sent( $payment_id, 'tiktok' );
                } else {
                    ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $payment_id, 'Purchase' );
                }
            } else {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent', '', $event_id, $payment_id, 'Purchase' );
            }
        }
    }

    public static function on_registration( string $user_login, string $user_email, int $user_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;



        $event_id  = ServerTrack_Dedup::generate_event_id( 'edd_lead_' . $user_id . '_' . wp_generate_uuid4() );
        $user_data = [];

        if ( ! empty( $user_email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $user_email );
        }

        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 0.0, 'contents' => [] ] );

        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }
}
