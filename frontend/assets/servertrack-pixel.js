/**
 * servertrack-pixel.js
 * Browser-side pixel coordinator.
 * Fires Meta Pixel and TikTok Pixel using the server-generated event_id
 * injected via wp_localize_script() as servertrack_config.
 *
 * RULES:
 * - Never generate event_id here. Always use the PHP-provided one.
 * - Never fire pixels independently — only when config.event_id is present.
 * - Designed for the thank-you page exclusively.
 */
(function () {
    'use strict';

    var cfg = window.servertrack_config;
    if ( ! cfg ) return;

    // ── Meta Pixel ────────────────────────────────────────────────────────
    function initMetaPixel() {
        if ( ! cfg.meta_enabled || ! cfg.meta_pixel ) return;

        /* eslint-disable */
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */

        fbq( 'init', cfg.meta_pixel );

        // Only fire a tracked event if we have a server-generated event_id
        if ( cfg.event_id && cfg.event_name ) {
            var eventParams = { eventID: cfg.event_id };

            if ( 'Purchase' === cfg.event_name ) {
                fbq( 'track', 'Purchase', {
                    value:    cfg.value    || 0,
                    currency: cfg.currency || 'USD',
                }, eventParams );
            } else {
                fbq( 'track', cfg.event_name, {}, eventParams );
            }
        } else {
            fbq( 'track', 'PageView' );
        }
    }

    // ── TikTok Pixel ─────────────────────────────────────────────────────
    function initTikTokPixel() {
        if ( ! cfg.tt_enabled || ! cfg.tiktok_pixel ) return;

        /* eslint-disable */
        !function(w, d, t) {
            w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track",
            "identify","instances","debug","on","off","once","ready","alias","group",
            "enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){
            t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;
            i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=
            function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)
            ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i=
            "https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},
            ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,
            ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");
            o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=
            document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
            ttq.load(w[t+'Pixel']||"");ttq.page();
        }(window, document, 'ttq');
        /* eslint-enable */

        ttq.load( cfg.tiktok_pixel );
        ttq.page();

        if ( cfg.event_id && cfg.event_name ) {
            var ttEventMap = {
                'Purchase': 'CompletePayment',
                'Lead':     'SubmitForm',
            };
            var ttEvent = ttEventMap[ cfg.event_name ] || cfg.event_name;

            ttq.track( ttEvent, {
                value:        cfg.value    || 0,
                currency:     cfg.currency || 'USD',
                content_type: 'product',
            }, { event_id: cfg.event_id } );
        }
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () {
            initMetaPixel();
            initTikTokPixel();
        } );
    } else {
        initMetaPixel();
        initTikTokPixel();
    }

}());
