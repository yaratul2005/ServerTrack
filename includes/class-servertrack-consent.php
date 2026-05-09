<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Consent {

    public static function is_granted( string $platform ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );
        
        if ( 'none' === $mode ) {
            return true;
        }

        if ( 'cookie_yes' === $mode ) {
            // Check cookieyes-analytics and cookieyes-advertisement
            if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
                $consent_cookie = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
                if ( strpos( $consent_cookie, 'analytics:yes' ) !== false && strpos( $consent_cookie, 'advertisement:yes' ) !== false ) {
                    return true;
                }
            }
            return false;
        }

        if ( 'complianz' === $mode ) {
            if ( isset( $_COOKIE['cmplz_marketing'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) ) ) {
                return true;
            }
            return false;
        }

        if ( 'manual' === $mode ) {
            return apply_filters( 'servertrack_consent_granted', false, $platform );
        }

        return true; // Fallback
    }
}
