<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_CF7 {

    public static function init() {
        if ( ! get_option( 'servertrack_source_cf7_enabled', 0 ) ) return;
        if ( ! function_exists( 'wpcf7' ) ) return;

        add_action( 'wpcf7_mail_sent', [ self::class, 'on_form_sent' ] );
    }

    public static function on_form_sent( WPCF7_ContactForm $contact_form ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
        require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';

        $form_id  = $contact_form->id();
        $event_id = ServerTrack_Dedup::generate_event_id( 'lead_cf7_' . $form_id . '_' . wp_generate_uuid4() );

        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) return;

        $posted_data = $submission->get_posted_data();

        // Retrieve per-form mapping — admin configures which CF7 field name maps to which tracking field
        $mappings = get_option( 'servertrack_cf7_mappings', [] );
        if ( ! is_array( $mappings ) ) $mappings = [];
        $form_map = isset( $mappings[ $form_id ] ) && is_array( $mappings[ $form_id ] ) ? $mappings[ $form_id ] : [];

        // Fall back to common CF7 default field names if no mapping is configured
        $email_field = ! empty( $form_map['email'] ) ? $form_map['email'] : 'your-email';
        $phone_field = ! empty( $form_map['phone'] ) ? $form_map['phone'] : 'your-phone';
        $name_field  = ! empty( $form_map['name'] )  ? $form_map['name']  : 'your-name';

        $user_data = [];

        $email = $posted_data[ $email_field ] ?? '';
        if ( ! empty( $email ) ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( sanitize_email( $email ) );
        }

        $phone = $posted_data[ $phone_field ] ?? '';
        if ( ! empty( $phone ) ) {
            $user_data['phone'] = ServerTrack_Hasher::hash_phone( sanitize_text_field( $phone ) );
        }

        $name = $posted_data[ $name_field ] ?? '';
        if ( ! empty( $name ) ) {
            $parts = explode( ' ', trim( sanitize_text_field( $name ) ), 2 );
            $user_data['first_name'] = ServerTrack_Hasher::hash( $parts[0] );
            if ( ! empty( $parts[1] ) ) {
                $user_data['last_name'] = ServerTrack_Hasher::hash( $parts[1] );
            }
        }

        // Raw fields from submission context
        $remote_ip = $submission->get_meta( 'remote_ip' );
        if ( ! empty( $remote_ip ) ) $user_data['ip'] = $remote_ip;

        $user_agent = $submission->get_meta( 'user_agent' );
        if ( ! empty( $user_agent ) ) $user_data['user_agent'] = $user_agent;

        // Cookies — omit if absent (constraint #5)
        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 0.0, 'contents' => [] ] );

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }
}
