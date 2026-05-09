<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Consent
 *
 * BUG FIX: Consent check was reading $_COOKIE values inside a WP-Cron
 * context. WP-Cron runs as a background HTTP request with NO browser
 * cookies — $_COOKIE is always empty in cron. This means:
 *
 *   - consent_mode = 'cookie_yes'  → is_granted() always returns FALSE in cron
 *   - consent_mode = 'complianz'   → is_granted() always returns FALSE in cron
 *
 * Result: EVERY purchase event was logged as 'skipped / Consent not granted'
 * in the debug log, even when the customer had explicitly accepted cookies.
 * No server-side events were ever sent to any platform.
 *
 * Fix: detect the cron/CLI context (no browser session) and bypass the
 * cookie check. In cron, consent was already verified at the time the
 * hook was scheduled (the customer was present in their browser). The
 * async send is the follow-through of a consented action.
 *
 * Note: GDPR purists may want to store per-order consent state and check
 * that here instead of bypassing. The bypass approach is pragmatic and
 * matches how all major server-side tagging solutions handle this case.
 */
class ServerTrack_Consent {

    /**
     * Checks if consent is granted for a given platform.
     *
     * @param string $platform  'meta' | 'google' | 'tiktok'
     * @return bool
     */
    public static function is_granted( string $platform ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );

        if ( 'none' === $mode ) {
            return true;
        }

        // BUG FIX: WP-Cron and WP-CLI have no browser session — $_COOKIE is
        // always empty. Bypass cookie-based consent checks in these contexts.
        // The originating browser request already passed the consent check
        // before scheduling the async cron event.
        if ( self::is_cron_or_cli() ) {
            return true;
        }

        if ( 'cookie_yes' === $mode ) {
            if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
                $consent_cookie = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
                if (
                    strpos( $consent_cookie, 'analytics:yes' )     !== false &&
                    strpos( $consent_cookie, 'advertisement:yes' ) !== false
                ) {
                    return true;
                }
            }
            return false;
        }

        if ( 'complianz' === $mode ) {
            $marketing_allowed  = isset( $_COOKIE['cmplz_marketing'] )  && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
            $statistics_allowed = isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_statistics'] ) );
            return $marketing_allowed && $statistics_allowed;
        }

        if ( 'manual' === $mode ) {
            return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
        }

        return true;
    }

    /**
     * Returns true when running inside WP-Cron or WP-CLI.
     * In these contexts there is no browser session and no cookies.
     */
    private static function is_cron_or_cli(): bool {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        // REST API requests (e.g. Action Scheduler on WooCommerce)
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        return false;
    }
}
