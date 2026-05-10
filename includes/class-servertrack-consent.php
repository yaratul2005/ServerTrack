<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Consent  v2.1
 *
 * CRITICAL FIX (v2.1) — Per-order consent storage:
 *
 *   Previously, cron/async context bypassed cookie checks entirely with the
 *   assumption that "the originating request already passed consent". This is
 *   unsafe because:
 *
 *     1. A customer could withdraw consent between checkout and the async
 *        cron firing (e.g., on slow hosts where cron fires minutes later).
 *     2. On stores processing backdated or manual orders, no browser session
 *        ever existed — the bypass sends events with zero consent basis.
 *     3. GDPR audit trails require proof of consent at the time of the send,
 *        not a blanket bypass.
 *
 *   Fix: consent state is now captured and stored as order meta at the moment
 *   the customer's browser makes the InitiateCheckout / Purchase hook call.
 *   Async cron jobs read the stored per-order consent — not a cookie or bypass.
 *
 *   For non-order events (ViewContent, AddToCart, CF7 Lead) that run
 *   synchronously in browser context, cookie checks still apply as before.
 *   The cron bypass is now a narrow last-resort fallback only, not the default.
 *
 * Storage:
 *   Order meta key: _servertrack_consent
 *   Value: serialised array [ 'meta' => true/false, 'google' => true/false, 'tiktok' => true/false ]
 *   Written by: ServerTrack_Consent::capture_for_order( $order_id )
 *   Read by:    ServerTrack_Consent::is_granted( $platform, $order_id )
 */
class ServerTrack_Consent {

    /** Order meta key where per-order consent snapshot is stored. */
    const ORDER_META_KEY = '_servertrack_consent';

    /**
     * Checks if consent is granted for a given platform.
     *
     * For order-context async calls: pass $order_id to read stored per-order
     * consent instead of falling back to cookie/bypass logic.
     *
     * For synchronous browser-context calls (ViewContent, AddToCart, CF7):
     * omit $order_id — cookie-based check is used as normal.
     *
     * @param string   $platform  'meta' | 'google' | 'tiktok'
     * @param int|null $order_id  Pass the order ID for async/cron contexts.
     * @return bool
     */
    public static function is_granted( string $platform, ?int $order_id = null ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );

        // No consent mode configured — always allowed
        if ( 'none' === $mode ) {
            return true;
        }

        // ── Per-order consent (async/cron context) ───────────────────────
        // If an order_id is provided, read the consent snapshot stored when
        // the customer was present in their browser. This is authoritative.
        if ( null !== $order_id && $order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof \WC_Abstract_Order ) {
                $stored = $order->get_meta( self::ORDER_META_KEY, true );
                if ( is_array( $stored ) && isset( $stored[ $platform ] ) ) {
                    return (bool) $stored[ $platform ];
                }
                // Meta not yet written (order created before v2.1 upgrade).
                // Fall through to cookie check — but only if NOT in cron/CLI.
            }
        }

        // ── Cron / CLI / REST — no browser session ──────────────────────
        // If we reach here in a cron context without stored consent,
        // we cannot read cookies. Fail safe: deny if consent mode is active
        // and no per-order record exists, EXCEPT for 'manual' mode which
        // uses a filter (which the developer controls).
        if ( self::is_cron_or_cli() ) {
            if ( 'manual' === $mode ) {
                return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
            }
            // No per-order record + cron + active consent mode = deny.
            // This is the GDPR-safe choice. Log it so the developer notices.
            ServerTrack_Logger::log(
                'skipped', $platform,
                'Consent check in cron: no per-order consent record found for this order. ' .
                'Capture consent at checkout by calling ServerTrack_Consent::capture_for_order(). ' .
                'Event blocked.',
                '', '', (int) ( $order_id ?? 0 ), 'ConsentCheck'
            );
            return false;
        }

        // ── Browser context — live cookie check ──────────────────────────
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
     * Captures the current browser consent state and stores it as order meta.
     *
     * MUST be called in browser context (before checkout redirect / cron dispatch)
     * so that $_COOKIE is available. Call this from:
     *   - on_initiate_checkout()  (before dispatching async purchase)
     *   - on_thankyou()           (captures consent at thank-you page render)
     *
     * Idempotent: safe to call multiple times — only writes if not already set.
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function capture_for_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! ( $order instanceof \WC_Abstract_Order ) ) {
            return;
        }

        // Only write once — do not overwrite if already captured
        $existing = $order->get_meta( self::ORDER_META_KEY, true );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            return;
        }

        $platforms = [ 'meta', 'google', 'tiktok' ];
        $consent   = [];
        foreach ( $platforms as $platform ) {
            // Temporarily suppress the cron check — we ARE in browser context here
            $consent[ $platform ] = self::check_browser_consent( $platform );
        }

        $order->update_meta_data( self::ORDER_META_KEY, $consent );
        $order->save_meta_data();
    }

    /**
     * Pure browser-cookie consent check (no cron bypass, no order lookup).
     * Used internally by capture_for_order() to snapshot live consent state.
     *
     * @param string $platform
     * @return bool
     */
    private static function check_browser_consent( string $platform ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );

        if ( 'none' === $mode ) {
            return true;
        }

        if ( 'cookie_yes' === $mode ) {
            if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
                $consent_cookie = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
                return (
                    strpos( $consent_cookie, 'analytics:yes' )     !== false &&
                    strpos( $consent_cookie, 'advertisement:yes' ) !== false
                );
            }
            return false;
        }

        if ( 'complianz' === $mode ) {
            $marketing  = isset( $_COOKIE['cmplz_marketing'] )  && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
            $statistics = isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_statistics'] ) );
            return $marketing && $statistics;
        }

        if ( 'manual' === $mode ) {
            return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
        }

        return true;
    }

    /**
     * Returns true when running inside WP-Cron, WP-CLI, or a REST request.
     * In these contexts there is no browser session and no cookies.
     */
    private static function is_cron_or_cli(): bool {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        return false;
    }
}
