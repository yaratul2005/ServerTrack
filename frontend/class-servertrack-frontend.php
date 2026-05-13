<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend  v2.3
 *
 * Changes in v2.3 (deep-review fixes):
 *
 *   FIX-DR-5 — Rate-limit transient key prefix changed from 'st_rl_'
 *               to 'servertrack_rl_' and salted with wp_salt('nonce')
 *               via hash_hmac to prevent cross-plugin transient key
 *               collisions and unsalted MD5 bucket issues.
 *
 *   FIX-DR-6 — REST params sanitization now preserves numeric types
 *               (int/float/bool). Only string values go through
 *               sanitize_text_field(). Prevents CAPI rejection of
 *               numeric fields like value, quantity, content_ids.
 *
 * Changes in v2.2:
 *   BUG-H1 + BUG-H2: get_request_ip() replaced with 4-tier trust chain.
 *   BUG-M4: PII fields stripped from params before logging.
 *
 * Changes in v2.1 (FIX-12):
 *   rest_custom_event() event_name allowlist added.
 */
class ServerTrack_Frontend {

    private const ALLOWED_EVENT_NAMES = [
        'Purchase', 'InitiateCheckout', 'AddPaymentInfo', 'AddToCart',
        'AddToWishlist', 'ViewContent', 'Lead', 'CompleteRegistration',
        'Contact', 'Subscribe', 'Search', 'FindLocation', 'Schedule',
        'PageView', 'CustomizeProduct', 'Donate', 'StartTrial',
        'SubmitApplication',
    ];

    private const PII_PARAM_BLOCKLIST = [
        'email', 'phone', 'credit_card', 'card_number', 'cvv',
        'ssn', 'password', 'token', 'api_key', 'secret',
        'access_token', 'refresh_token', 'authorization',
    ];

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'persist_click_ids_to_order' ], 10, 1 );
        add_action( 'rest_api_init',      [ self::class, 'register_rest_routes' ] );
    }

    // -----------------------------------------------------------------
    // REST: browser -> CAPI bridge
    // -----------------------------------------------------------------

    public static function register_rest_routes() {
        register_rest_route( 'servertrack/v1', '/custom-event', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_custom_event' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_name' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'event_id'   => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'params'     => [ 'required' => false, 'type' => 'object'  ],
                'is_custom'  => [ 'required' => false, 'type' => 'boolean' ],
                'url'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw' ],
                'fbc'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'fbp'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'ttclid'     => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public static function rest_custom_event( WP_REST_Request $request ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return new WP_Error( 'disabled', 'ServerTrack disabled', [ 'status' => 403 ] );
        }

        $event_name = $request->get_param( 'event_name' );
        if ( ! in_array( $event_name, self::ALLOWED_EVENT_NAMES, true ) ) {
            return new WP_Error(
                'invalid_event_name',
                sprintf(
                    'Unknown event type \'%s\'. Allowed values: %s.',
                    esc_html( $event_name ),
                    implode( ', ', self::ALLOWED_EVENT_NAMES )
                ),
                [ 'status' => 400 ]
            );
        }

        // FIX-DR-5: Salted, namespaced rate-limit key.
        $ip       = self::get_request_ip();
        $rate_key = 'servertrack_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );

        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 10 ) {
            return new WP_Error( 'rate_limit', 'Rate limit exceeded', [ 'status' => 429 ] );
        }
        set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

        $event_id = $request->get_param( 'event_id' ) ?: ServerTrack_Dedup::generate_event_id( $event_name . '_rest_' . time() );
        $params   = (array) ( $request->get_param( 'params' ) ?: [] );
        $url      = $request->get_param( 'url' )    ?: '';
        $fbc      = $request->get_param( 'fbc' )    ?: '';
        $fbp      = $request->get_param( 'fbp' )    ?: '';
        $ttclid   = $request->get_param( 'ttclid' ) ?: '';

        // FIX-DR-6: Type-preserving sanitization (replaces array_walk_recursive cast-to-string).
        $params = self::sanitize_params( $params );

        // BUG-M4: Strip PII fields.
        foreach ( self::PII_PARAM_BLOCKLIST as $pii_field ) {
            unset( $params[ $pii_field ] );
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $user_data = [ 'ip' => $ip, 'user_agent' => $ua ];
        if ( $fbc )    $user_data['fbc']    = $fbc;
        if ( $fbp )    $user_data['fbp']    = $fbp;
        if ( $ttclid ) $user_data['ttclid'] = $ttclid;

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }

        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( array_merge( $params, [ 'event_source_url' => $url ] ) );

        $results = [];
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $results['meta'] = ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $results['tiktok'] = ServerTrack_TikTok::send( $event );
        }

        return rest_ensure_response( [ 'sent' => true, 'results' => $results ] );
    }

    /**
     * FIX-DR-6: Type-preserving recursive param sanitizer.
     *
     * int/float/bool values are kept as their native types.
     * string values are passed through sanitize_text_field().
     * Arrays are recursed. All other types are cast to string and sanitized.
     */
    private static function sanitize_params( array $params ): array {
        $out = [];
        foreach ( $params as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( is_array( $value ) ) {
                $out[ $key ] = self::sanitize_params( $value );
            } elseif ( is_int( $value ) ) {
                $out[ $key ] = (int) $value;
            } elseif ( is_float( $value ) ) {
                $out[ $key ] = (float) $value;
            } elseif ( is_bool( $value ) ) {
                $out[ $key ] = (bool) $value;
            } else {
                $out[ $key ] = sanitize_text_field( (string) $value );
            }
        }
        return $out;
    }

    /**
     * Resolve the real client IP using a trusted priority chain.
     *
     * BUG-H1: old code used XFF[0] (leftmost = client-controlled).
     * BUG-H2: behind Cloudflare, REMOTE_ADDR is a shared CDN egress IP.
     *
     * Priority:
     *   1. CF-Connecting-IP  — Cloudflare sets this; clients cannot spoof it.
     *   2. X-Real-IP         — nginx upstream; not client-controlled.
     *   3. XFF last token    — rightmost entry appended by last trusted proxy.
     *   4. REMOTE_ADDR       — TCP peer; absolute fallback.
     */
    private static function get_request_ip(): string {
        $ip = '';

        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }

        if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }

        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $xff_raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $tokens  = array_map( 'trim', explode( ',', $xff_raw ) );
            foreach ( array_reverse( $tokens ) as $token ) {
                if ( filter_var( $token, FILTER_VALIDATE_IP ) ) return $token;
            }
        }

        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }

        return $ip;
    }
}
