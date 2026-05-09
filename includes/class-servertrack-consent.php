<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Consent {

    /**
     * Checks if consent is granted for a given platform.
     * 
     * @note Cookie-based modes (cookie_yes, complianz) apply globally to all platforms.
     *       The $platform parameter is currently only used by the 'manual' mode filter,
     *       but the architecture supports per-platform routing if desired later.
     * 
     * @param string $platform The platform name (e.g., 'meta', 'google', 'tiktok').
     * @return bool True if consent is granted, false otherwise.
     */
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
            $marketing_allowed  = isset( $_COOKIE['cmplz_marketing'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
            $statistics_allowed = isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_statistics'] ) );
            
            if ( $marketing_allowed && $statistics_allowed ) {
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
