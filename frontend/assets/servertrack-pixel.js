/**
 * servertrack-pixel.js
 * Browser-side pixel coordinator.
 * Fires Meta Pixel, TikTok Pixel, and Google gtag conversion
 * using the server-generated event_id injected via wp_localize_script()
 * as servertrack_config.
 *
 * RULES:
 * - Never generate event_id here. Always use the PHP-provided one.
 * - Google gtag block only fires on thank-you page (event_id present).
 * - Google transaction_id = event_id for browser/server dedup.
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

    // ── Google gtag Conversion ───────────────────────────────────────────
    // Only fires on the thank-you page (event_id + gtag_id both present).
    // transaction_id = event_id — this is the browser half of the
    // server/browser dedup pair for Google Enhanced Conversions.
    function initGoogleGtag() {
        if ( ! cfg.google_enabled || ! cfg.gtag_id || ! cfg.gtag_label ) return;
        if ( ! cfg.event_id || 'Purchase' !== cfg.event_name ) return;

        // Load gtag.js if not already present
        if ( ! window.gtag ) {
            var gtagScript = document.createElement( 'script' );
            gtagScript.async = true;
            gtagScript.src = 'https://www.googletagmanager.com/gtag/js?id=' + cfg.gtag_id;
            document.head.appendChild( gtagScript );

            window.dataLayer = window.dataLayer || [];
            window.gtag = function () {
                // eslint-disable-next-line prefer-rest-params
                window.dataLayer.push( arguments );
            };
            gtag( 'js', new Date() );
            gtag( 'config', cfg.gtag_id, { send_page_view: false } );
        }

        // Fire conversion event
        // transaction_id must match the event_id used in the server-side upload
        // so Google can deduplicate the browser and server hits.
        var conversionData = {
            send_to:        cfg.gtag_id + '/' + cfg.gtag_label,
            transaction_id: cfg.event_id,
            value:          cfg.value    || 0,
            currency:       cfg.currency || 'USD',
        };

        // Append gclid if PHP passed it (cookie-readable on this page)
        if ( cfg.gclid ) {
            conversionData.gclid = cfg.gclid;
        }

        gtag( 'event', 'conversion', conversionData );
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    function boot() {
        initMetaPixel();
        initTikTokPixel();
        initGoogleGtag();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }

}());
