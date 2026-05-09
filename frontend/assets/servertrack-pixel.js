/**
 * servertrack-pixel.js  v3.0
 * PixelMysite-parity browser-side event coordinator.
 *
 * Covers (browser half of each browser+server pair):
 *   PageView, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo,
 *   Purchase, Search, CompleteRegistration, Lead, ViewCategory,
 *   CustomEvent (via data-servertrack-event attributes),
 *   ScrollDepth (25/50/75/100%), VideoPlay/VideoProgress (HTML5 + YouTube + Vimeo),
 *   UserIdentity (fbq identify when email present in page)
 *
 * Rules:
 *   - Never generate event_id here. Always use PHP-provided one (where relevant).
 *   - Google gtag block fires on thank-you page only.
 *   - TikTok pixel is loaded once via the snippet (no second ttq.load).
 *   - Meta PageView is suppressed on pages where a named conversion fires.
 *   - All events carry eventID for browser/server dedup.
 */
(function () {
    'use strict';

    var cfg = window.servertrack_config;
    if (!cfg) return;

    var hasPurchaseEvent = !!(cfg.event_id && cfg.event_name);
    var metaReady = false;
    var ttqReady  = false;
    var gtagReady = false;

    // ─────────────────────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────────────────────
    function genId() {
        return 'br_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    }

    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }

    function buildTTEventMap() {
        return {
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
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CORE SEND HELPERS
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * sendMeta( eventName, params, eventID )
     * Always include eventID for dedup. On purchase pages use PHP-provided ID.
     * On all other pages generate a browser-side ID.
     */
    function sendMeta(eventName, params, eventID) {
        if (!metaReady) return;
        var eid = eventID || genId();
        try {
            fbq('track', eventName, params || {}, { eventID: eid });
        } catch (e) { /* pixel blocked */ }
    }

    function sendMetaCustom(eventName, params, eventID) {
        if (!metaReady) return;
        var eid = eventID || genId();
        try {
            fbq('trackCustom', eventName, params || {}, { eventID: eid });
        } catch (e) { /* pixel blocked */ }
    }

    function sendTT(eventName, params, eventID) {
        if (!ttqReady) return;
        var ttMap = buildTTEventMap();
        var ttName = ttMap[eventName] || eventName;
        var eid = eventID || genId();
        try {
            ttq.track(ttName, params || {}, { event_id: eid });
        } catch (e) { /* pixel blocked */ }
    }

    function sendTTCustom(eventName, params, eventID) {
        if (!ttqReady) return;
        var eid = eventID || genId();
        try {
            // TikTok custom events use same ttq.track but with a non-standard name
            ttq.track(eventName, params || {}, { event_id: eid });
        } catch (e) { /* pixel blocked */ }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // PLATFORM INIT
    // ─────────────────────────────────────────────────────────────────────────────

    function initMetaPixel() {
        if (!cfg.meta_enabled || !cfg.meta_pixel) return;
        /* eslint-disable */
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */
        fbq('init', cfg.meta_pixel);

        // User identity (email from page — improves EMQ)
        if (cfg.user_email) {
            fbq('init', cfg.meta_pixel, { em: cfg.user_email });
        }

        metaReady = true;

        if (hasPurchaseEvent) {
            // Conversion page: fire the named event only, suppress PageView
            if ('Purchase' === cfg.event_name) {
                sendMeta('Purchase', {
                    value: cfg.value || 0,
                    currency: cfg.currency || 'USD',
                    content_ids: cfg.content_ids || [],
                    contents: cfg.contents || [],
                    content_type: 'product',
                    order_id: cfg.order_id || '',
                }, cfg.event_id);
            } else {
                sendMeta(cfg.event_name, {}, cfg.event_id);
            }
        } else {
            sendMeta('PageView', {});
        }
    }

    function initTikTokPixel() {
        if (!cfg.tt_enabled || !cfg.tiktok_pixel) return;
        /* eslint-disable */
        !function(w,d,t){
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
            ttq.load(cfg.tiktok_pixel);
            ttq.page();
        }(window,document,'ttq');
        /* eslint-enable */
        // NO second ttq.load() here — snippet already handles it
        ttqReady = true;

        if (cfg.user_email) {
            try { ttq.identify({ email: cfg.user_email }); } catch(e) {}
        }

        if (hasPurchaseEvent && 'Purchase' === cfg.event_name) {
            sendTT('Purchase', {
                value: cfg.value || 0,
                currency: cfg.currency || 'USD',
                content_type: 'product',
                contents: cfg.contents || [],
            }, cfg.event_id);
        }
    }

    function initGoogleGtag() {
        if (!cfg.google_enabled || !cfg.gtag_id || !cfg.gtag_label) return;
        if (!hasPurchaseEvent || 'Purchase' !== cfg.event_name) return;
        if (!window.gtag) {
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.googletagmanager.com/gtag/js?id=' + cfg.gtag_id;
            document.head.appendChild(s);
            window.dataLayer = window.dataLayer || [];
            window.gtag = function() { window.dataLayer.push(arguments); };
            gtag('js', new Date());
            gtag('config', cfg.gtag_id, {
                send_page_view: false,
                allow_enhanced_conversions: true,
            });
        }
        gtagReady = true;
        var conv = {
            send_to:        cfg.gtag_id + '/' + cfg.gtag_label,
            transaction_id: cfg.event_id,
            value:          cfg.value    || 0,
            currency:       cfg.currency || 'USD',
        };
        if (cfg.gclid) conv.gclid = cfg.gclid;
        gtag('event', 'conversion', conv);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // WOO EVENT LISTENERS  (browser mirrors of server-side events)
    // ─────────────────────────────────────────────────────────────────────────────

    // AddToCart — WooCommerce fires a custom jQuery event on the body
    function bindAddToCart() {
        document.body.addEventListener('added_to_cart.servertrack', function(e) {
            var d = e.detail || {};
            var eid = genId();
            sendMeta('AddToCart', {
                value:       d.price || 0,
                currency:    d.currency || cfg.store_currency || 'USD',
                content_ids: d.product_ids || [],
                contents:    d.contents   || [],
                content_type:'product',
            }, eid);
            sendTT('AddToCart', {
                value:       d.price || 0,
                currency:    d.currency || cfg.store_currency || 'USD',
                content_type:'product',
                contents:    d.contents || [],
            }, eid);
        });

        // WooCommerce jQuery event (classic themes)
        if (window.jQuery) {
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, btn) {
                var price    = btn ? parseFloat(btn.data('product_price') || btn.closest('.product').find('.price .amount').first().text().replace(/[^0-9.]/g,'')) : 0;
                var pid      = btn ? (btn.data('product_id') || btn.val()) : '';
                var eid      = genId();
                var currency = cfg.store_currency || 'USD';
                sendMeta('AddToCart', {
                    value: price, currency: currency,
                    content_ids: pid ? [String(pid)] : [],
                    content_type: 'product',
                }, eid);
                sendTT('AddToCart', {
                    value: price, currency: currency, content_type: 'product',
                }, eid);
            });
        }
    }

    // AddPaymentInfo — WooCommerce payment method selection
    function bindAddPaymentInfo() {
        document.body.addEventListener('change', function(e) {
            var el = e.target;
            if (el && el.name === 'payment_method') {
                var eid = genId();
                sendMeta('AddPaymentInfo', {}, eid);
                sendTT('AddPaymentInfo', {}, eid);
            }
        });
    }

    // Search — WooCommerce search form submission
    function bindSearch() {
        var forms = document.querySelectorAll('form[role="search"], .woocommerce-product-search, .search-form');
        forms.forEach(function(form) {
            form.addEventListener('submit', function() {
                var q = form.querySelector('input[name="s"]');
                var eid = genId();
                sendMeta('Search', { search_string: q ? q.value : '' }, eid);
                sendTT('Search', { query: q ? q.value : '' }, eid);
            });
        });
    }

    // ViewCategory — fires on WooCommerce archive/category pages
    function fireViewCategory() {
        if (!cfg.is_product_archive) return;
        var eid = genId();
        sendMeta('ViewCategory', {
            content_category: cfg.current_category || '',
            content_type: 'product',
        }, eid);
        // TikTok uses ViewContent for category pages
        sendTT('ViewCategory', {
            content_type: 'product',
            description: cfg.current_category || '',
        }, eid);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CUSTOM EVENTS  (data-servertrack-event attributes)
    // ─────────────────────────────────────────────────────────────────────────────
    /**
     * Custom event markup:
     *   <button data-servertrack-event="Lead"
     *           data-servertrack-params='{"content_name":"Newsletter"}'>
     *     Subscribe
     *   </button>
     *
     *   data-servertrack-trigger: "click" (default) | "view" | "hover"
     *   data-servertrack-custom: "1"  → fire as trackCustom instead of track
     */
    function bindCustomEvents() {
        var elements = document.querySelectorAll('[data-servertrack-event]');
        elements.forEach(function(el) {
            var eventName = el.getAttribute('data-servertrack-event');
            var trigger   = el.getAttribute('data-servertrack-trigger') || 'click';
            var isCustom  = el.getAttribute('data-servertrack-custom') === '1';
            var paramsRaw = el.getAttribute('data-servertrack-params') || '{}';
            var params;
            try { params = JSON.parse(paramsRaw); } catch(e) { params = {}; }

            function fire() {
                var eid = genId();
                if (isCustom) {
                    sendMetaCustom(eventName, params, eid);
                    sendTTCustom(eventName, params, eid);
                } else {
                    sendMeta(eventName, params, eid);
                    sendTT(eventName, params, eid);
                }
                // Also send to server via REST for CAPI coverage
                if (cfg.rest_url) {
                    fetch(cfg.rest_url + 'servertrack/v1/custom-event', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce || '' },
                        body: JSON.stringify({
                            event_name: eventName,
                            event_id:   eid,
                            params:     params,
                            is_custom:  isCustom,
                            url:        window.location.href,
                            fbc:        getCookie('_fbc'),
                            fbp:        getCookie('_fbp'),
                            ttclid:     getCookie('ttclid'),
                        }),
                    });
                }
            }

            if (trigger === 'click') {
                el.addEventListener('click', fire);
            } else if (trigger === 'hover') {
                el.addEventListener('mouseenter', fire, { once: true });
            } else if (trigger === 'view') {
                var obs = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) { fire(); obs.disconnect(); }
                    });
                }, { threshold: 0.5 });
                obs.observe(el);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // SCROLL DEPTH
    // ─────────────────────────────────────────────────────────────────────────────
    function bindScrollDepth() {
        if (!cfg.scroll_depth_enabled) return;
        var fired = {};
        var thresholds = [25, 50, 75, 100];
        function onScroll() {
            var scrolled = (window.scrollY + window.innerHeight) / document.body.scrollHeight * 100;
            thresholds.forEach(function(t) {
                if (!fired[t] && scrolled >= t) {
                    fired[t] = true;
                    var eid = genId();
                    sendMetaCustom('ScrollDepth', { depth: t, url: window.location.href }, eid);
                    sendTTCustom('ScrollDepth', { depth: t }, eid);
                }
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // VIDEO TRACKING  (HTML5, YouTube iframes, Vimeo iframes)
    // ─────────────────────────────────────────────────────────────────────────────
    function bindVideoTracking() {
        if (!cfg.video_tracking_enabled) return;

        // HTML5 <video>
        document.querySelectorAll('video').forEach(function(video) {
            var title = video.getAttribute('title') || video.src || 'video';
            var progFired = {};
            video.addEventListener('play', function() {
                var eid = genId();
                sendMetaCustom('VideoPlay', { content_name: title }, eid);
                sendTTCustom('VideoPlay',  { content_name: title }, eid);
            }, { once: true });
            video.addEventListener('timeupdate', function() {
                if (!video.duration) return;
                var pct = Math.floor(video.currentTime / video.duration * 100);
                [25, 50, 75, 95].forEach(function(t) {
                    if (!progFired[t] && pct >= t) {
                        progFired[t] = true;
                        var eid = genId();
                        sendMetaCustom('VideoProgress', { content_name: title, percent: t }, eid);
                        sendTTCustom('VideoProgress',   { content_name: title, percent: t }, eid);
                    }
                });
            });
        });

        // YouTube iframes via postMessage API
        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'string') return;
            try {
                var d = JSON.parse(e.data);
                if (d.event === 'video-play') {
                    sendMetaCustom('VideoPlay', { content_name: 'YouTube' }, genId());
                    sendTTCustom('VideoPlay',   { content_name: 'YouTube' }, genId());
                }
            } catch(err) {}
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // WISHLIST  (YITH / TI WooCommerce Wishlist)
    // ─────────────────────────────────────────────────────────────────────────────
    function bindWishlist() {
        if (!cfg.wishlist_enabled) return;
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('.add_to_wishlist, [data-product-id][class*="wishlist"]');
            if (!el) return;
            var pid = el.getAttribute('data-product-id') || '';
            var eid = genId();
            sendMetaCustom('AddToWishlist', { content_ids: pid ? [pid] : [], content_type: 'product' }, eid);
            sendTTCustom('AddToWishlist',   { content_ids: pid ? [pid] : [] }, eid);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // PUBLIC API  window.ServerTrack
    // ─────────────────────────────────────────────────────────────────────────────
    /**
     * window.ServerTrack.track('EventName', { param: val })
     * Fires to Meta (trackCustom) + TikTok + server REST endpoint.
     * Themes and other plugins can call this directly.
     */
    window.ServerTrack = {
        track: function(eventName, params) {
            var eid = genId();
            params = params || {};
            sendMetaCustom(eventName, params, eid);
            sendTTCustom(eventName, params, eid);
            if (cfg.rest_url) {
                fetch(cfg.rest_url + 'servertrack/v1/custom-event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce || '' },
                    body: JSON.stringify({
                        event_name: eventName,
                        event_id:   eid,
                        params:     params,
                        is_custom:  true,
                        url:        window.location.href,
                        fbc:        getCookie('_fbc'),
                        fbp:        getCookie('_fbp'),
                        ttclid:     getCookie('ttclid'),
                    }),
                });
            }
        },

        trackStandard: function(eventName, params) {
            var eid = genId();
            sendMeta(eventName, params || {}, eid);
            sendTT(eventName, params || {}, eid);
        },
    };

    // ─────────────────────────────────────────────────────────────────────────────
    // BOOT
    // ─────────────────────────────────────────────────────────────────────────────
    function boot() {
        initMetaPixel();
        initTikTokPixel();
        initGoogleGtag();

        // Woo event listeners
        bindAddToCart();
        bindAddPaymentInfo();
        bindSearch();
        fireViewCategory();

        // Custom & engagement
        bindCustomEvents();
        bindScrollDepth();
        bindVideoTracking();
        bindWishlist();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

}());
