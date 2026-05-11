<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_ConsentV2  v1.0
 *
 * Feature #8 — Google Consent Mode v2 Integration.
 *
 * WHY THIS MATTERS:
 *   As of March 2024, Google requires Consent Mode v2 for all EU/EEA traffic.
 *   Without it, Google Ads conversion tracking and GA4 remarketing audiences
 *   are disabled for EU visitors. This is not optional — non-compliance means
 *   zero Google attribution for a large slice of traffic.
 *
 *   Most plugins implement consent mode via gtag.js (client-side). ServerTrack
 *   implements the server-side complement: the Measurement Protocol v2 requires
 *   consent signals to be forwarded with each hit so that Google can apply the
 *   correct modelling for consented vs non-consented users.
 *
 * WHAT WE SEND:
 *   - `non_personalized_ads`: '1' if analytics_storage is denied
 *   - `_npa`: same, alternate key for MP v2
 *   - Consent signals are captured client-side by a tiny JS snippet injected
 *     by ServerTrack_Frontend and stored server-side per order.
 *
 * ARCHITECTURE:
 *   1. JS snippet reads gtag consent state from dataLayer (if CMP present)
 *      or from a ServerTrack consent cookie set by the CMP callback.
 *   2. Consent state is POSTed to the existing /capture endpoint (extended).
 *   3. ServerTrack_Google::send() calls self::get_consent_params( $order_id )
 *      and merges the result into the Measurement Protocol payload.
 *
 * SUPPORTED CMPs:
 *   CookieYes, Complianz, GDPR Cookie Consent (WebToffee), Borlabs Cookie,
 *   and any CMP that writes to gtag consent state via gtag('consent','update').
 */
class ServerTrack_ConsentV2 {

    const ORDER_META_KEY  = '_servertrack_consent_v2';
    const USER_META_KEY   = 'servertrack_consent_v2';
    const DEFAULT_CONSENT = [
        'analytics_storage'    => 'denied',
        'ad_storage'           => 'denied',
        'ad_user_data'         => 'denied',
        'ad_personalization'   => 'denied',
    ];

    /**
     * Store consent signals captured from client for an order.
     *
     * @param int   $order_id
     * @param array $signals  [ analytics_storage => 'granted'|'denied', ... ]
     */
    public static function store_for_order( int $order_id, array $signals ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $clean = self::sanitize_signals( $signals );
        $order->update_meta_data( self::ORDER_META_KEY, $clean );
        $order->save_meta_data();
    }

    /**
     * Store consent signals for a logged-in user.
     *
     * @param int   $user_id
     * @param array $signals
     */
    public static function store_for_user( int $user_id, array $signals ): void {
        update_user_meta( $user_id, self::USER_META_KEY, self::sanitize_signals( $signals ) );
    }

    /**
     * Get Google Measurement Protocol consent parameters for a given order.
     * Returns an array ready to merge into the MP event payload.
     *
     * @param int $order_id  0 for non-order events
     * @return array  Google MP consent params
     */
    public static function get_mp_params( int $order_id = 0 ): array {
        $signals = self::DEFAULT_CONSENT;

        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $stored = $order->get_meta( self::ORDER_META_KEY );
                if ( is_array( $stored ) && ! empty( $stored ) ) {
                    $signals = array_merge( $signals, $stored );
                }
            }
        } elseif ( is_user_logged_in() ) {
            $stored = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
            if ( is_array( $stored ) && ! empty( $stored ) ) {
                $signals = array_merge( $signals, $stored );
            }
        }

        // Build MP v2 consent params
        $non_personalized = ( $signals['analytics_storage'] === 'denied'
            || $signals['ad_storage'] === 'denied' ) ? '1' : '0';

        return [
            'non_personalized_ads' => $non_personalized,
            '_npa'                 => $non_personalized,
            'uaa'                  => $signals['ad_user_data'] === 'granted' ? '1' : '0',
            'upa'                  => $signals['ad_personalization'] === 'granted' ? '1' : '0',
        ];
    }

    /**
     * Returns JS snippet for reading CMP consent state and sending to
     * the /capture endpoint. Injected by ServerTrack_Frontend.
     *
     * @return string Inline JavaScript (no <script> tags)
     */
    public static function get_consent_js_snippet(): string {
        $endpoint = esc_url( rest_url( 'servertrack/v1/capture' ) );
        $nonce    = wp_create_nonce( 'wp_rest' );
        // phpcs:disable
        return <<<JS
(function(){
    // Read gtag consent state if available
    var consent = {analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'};
    try{
        var dl = window.dataLayer||[];
        for(var i=dl.length-1;i>=0;i--){
            var item = dl[i];
            if(item&&item[0]==='consent'&&item[1]==='update'&&item[2]){
                Object.assign(consent,item[2]); break;
            }
        }
        // Also check window.__st_consent set by CMP callback
        if(window.__st_consent) Object.assign(consent,window.__st_consent);
    }catch(e){}
    // Only POST if at least one signal is granted
    var hasGrant = Object.values(consent).indexOf('granted') >= 0;
    if(!hasGrant) return;
    fetch('{$endpoint}', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':'{$nonce}'},
        body:JSON.stringify({consent_v2:consent})
    }).catch(function(){});
})();
JS;
        // phpcs:enable
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function sanitize_signals( array $signals ): array {
        $allowed = [ 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ];
        $clean   = [];
        foreach ( $allowed as $key ) {
            if ( isset( $signals[$key] ) ) {
                $val         = strtolower( sanitize_text_field( $signals[$key] ) );
                $clean[$key] = in_array( $val, [ 'granted', 'denied' ], true ) ? $val : 'denied';
            }
        }
        return $clean;
    }
}
