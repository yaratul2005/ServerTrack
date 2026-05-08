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
    }

    public static function on_purchase( int $payment_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        $event_id = ServerTrack_Dedup::generate_event_id( 'edd_purchase_' . $payment_id );

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

        // Cookies — only if present (constraint #5)
        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
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

        $event = new ServerTrack_Event( 'Purchase', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( $custom_data );

        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_google_enabled', 0 ) && ServerTrack_Consent::is_granted( 'google' ) ) {
            ServerTrack_Google::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }

    public static function on_registration( string $user_login, string $user_email, int $user_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

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
