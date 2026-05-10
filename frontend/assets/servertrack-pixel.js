/**
 * servertrack-pixel.js  v2.0
 * PixelMysite-parity browser-side event coordinator.
 *
 * BUGS FIXED vs v1:
 *   - initTikTokPixel: was calling ttq.page() manually AFTER the snippet which
 *     already calls ttq.page() internally = double TikTok PageView per load.
 *     Fixed: removed manual ttq.page() call.
 *   - initMetaPixel: was passing a SHA256-pre-hashed email to fbq('init') 'em'.
 *     Meta Pixel fbq('init') expects either raw email (Meta hashes it) or nothing.
 *     A pre-hashed string fails Meta's normalisation and lowers match quality.
 *     Fixed: pass raw email (received from PHP config). PHP ensures it's lowercase+trimmed.
 *   - bindAddToCart: jQuery path was trying to parse price from DOM text which
 *     is locale-formatted and unreliable. Now uses data-price attribute where
 *     available, falls back to 0 gracefully.
 *   - initGoogleGtag: gtag('config') was called before gtag('js', new Date())
 *     which causes Google to log a warning and may skip the config call.
 *     Fixed: correct ordering.
 */
(function () {
    'use strict';

    var cfg = window.servertrack_config;
    if (!cfg) return;

    var hasPurchaseEvent = !!(cfg.event_id && cfg.event_name);
    var metaReady = false;
    var ttqReady  = false;

    // ── UTILITIES ──────────────────────────────────────────────────────────────────────
    function genId() {
        return 'br_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    }

    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }

    var TT_EVENT_MAP = {
        Purchase:             'CompletePayment',
        ViewContent:          'ViewContent',
        AddToCart:            'AddToCart',
        InitiateCheckout:     'InitiateCheckout',
        AddPaymentInfo:       'AddPaymentInfo',
        Search:               'Search',
        CompleteRegistration: 'CompleteRegistration',
        Lead:                 'SubmitForm',
        ViewCategory:         'ViewContent',
    };

    // ── PLATFORM SEND HELPERS ───────────────────────────────────────────────────
    function sendMeta(eventName, params, eventID) {
        if (!metaReady) return;
        try { fbq('track', eventName, params || {}, { eventID: eventID || genId() }); } catch(e) {}
    }

    function sendMetaCustom(eventName, params, eventID) {
        if (!metaReady) return;
        try { fbq('trackCustom', eventName, params || {}, { eventID: eventID || genId() }); } catch(e) {}
    }

    function sendTT(eventName, params, eventID) {
        if (!ttqReady) return;
        var ttName = TT_EVENT_MAP[eventName] || eventName;
        try { ttq.track(ttName, params || {}, { event_id: eventID || genId() }); } catch(e) {}
    }

    function sendTTCustom(eventName, params, eventID) {
        if (!ttqReady) return;
        try { ttq.track(eventName, params || {}, { event_id: eventID || genId() }); } catch(e) {}
    }

    // ── PLATFORM INIT ────────────────────────────────────────────────────────────────
    function initMetaPixel() {
        if (!cfg.meta_enabled || !cfg.meta_pixel) return;
        /* eslint-disable */
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */

        // FIX: fbq('init') with advanced matching — pass raw email for Meta to hash
        // cfg.user_email is lowercased+trimmed raw email from PHP (logged-in users only)
        if (cfg.user_email) {
            fbq('init', cfg.meta_pixel, { em: cfg.user_email, external_id: cfg.user_external_id || '' });
        } else {
            fbq('init', cfg.meta_pixel);
        }

        metaReady = true;

        if (hasPurchaseEvent && cfg.event_name === 'Purchase') {
            // Conversion page: named event only, no PageView
            sendMeta('Purchase', {
                value:        cfg.value    || 0,
                currency:     cfg.currency || 'USD',
                content_ids:  cfg.content_ids || [],
                contents:     cfg.contents   || [],
                content_type: 'product',
                order_id:     cfg.order_id || '',
            }, cfg.event_id);
        } else if (hasPurchaseEvent) {
            sendMeta(cfg.event_name, {}, cfg.event_id);
        } else {
            fbq('track', 'PageView');
        }
    }

    function initTikTokPixel() {
        if (!cfg.tt_enabled || !cfg.tiktok_pixel) return;
        /* eslint-disable */
        !function(w,d,t){
            w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.metho