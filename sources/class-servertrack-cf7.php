<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_CF7
 *
 * Fires a server-side Lead event to Meta and TikTok on every successful
 * Contact Form 7 submission.
 *
 * Bug fixes (Day 5):
 *   1. init() guard changed from function_exists('wpcf7') to class_exists('WPCF7').
 *      The former only returns true after the wpcf7() function is defined, which
 *      is too late in some load orders. WPCF7 class is registered on plugins_loaded.
 *   2. send() results are now checked and routed to ServerTrack_Retry::maybe_queue()
 *      so transient API/network failures are automatically re-attempted.
 */
class ServerTrack_CF7 {

    public static function init() {
        if ( ! get_option( 'servertrack_source_cf7_enabled', 0 ) ) return;

        // Fix #1: use class_exists() not function_exists() — class is reliable at plugins_loaded
        if ( ! class_exists( 'WPCF7' ) ) return;

        add_action( 'wpcf7_mail_sent', [ self::class, 'on_form_sent' ] );
    }

    public static function on_form_sent( WPCF7_ContactForm $contact_form ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $form_id  = $contact_form->id();
        $event_id = ServerTrack_Dedup::generate_event_id( 'lead_cf7_' . $form_id . '_' . wp_generate_uuid4() );

        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) return;

        $posted_data = $submission->get_posted_data();

        // Per-form field mappings — admin configures which CF7 field name maps to which tracking field
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

        // Raw signal fields from submission context
        $remote_ip = $submission->get_meta( 'remote_ip' );
        if ( ! empty( $remote_ip ) ) $user_data['ip'] = $remote_ip;

        $user_agent = $submission->get_meta( 'user_agent' );
        if ( ! empty( $user_agent ) ) $user_data['user_agent'] = $user_agent;

        // Browser cookies — omit if absent (constraint #5)
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )   $user_data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )   $user_data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        // phpcs:enable

        $event = new ServerTrack_Event( 'Lead', $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( [ 'currency' => 'USD', 'value' => 0.0, 'contents' => [] ] );

        // Fix #2: check send() result and route failures to retry queue
        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $event ) );
        }
    }
}
