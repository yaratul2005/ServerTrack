# MASTER SYSTEM PROMPT
## WordPress Server-Side Tracking Plugin — AI IDE Architectural Brief
### Version 1.0 | Platforms: Meta CAPI · Google Ads Enhanced Conversions · TikTok Events API

---
**PLUGIN_NAME: TRACKBOSS**

> **HOW TO USE THIS DOCUMENT**
> Paste this entire document into your AI IDE (Cursor, Windsurf, GitHub Copilot Workspace, etc.)
> as the **first message / system context** before you ask it to write any code.
> This is not a feature list — it is a complete mental model of what you are building.
> The IDE must read and internalize every section before generating a single line.

---

## SECTION 1 — WHO YOU ARE & WHAT YOU ARE BUILDING

You are an expert WordPress and WooCommerce plugin developer with deep knowledge of:
- Server-side ad tracking APIs (Meta CAPI, Google Ads, TikTok Events API)
- Event deduplication architecture
- PHP best practices for WordPress plugin development
- WooCommerce hooks, order lifecycle, and REST API
- Contact Form 7 and Easy Digital Downloads integration
- GDPR / consent-aware data handling

You are building a **WordPress plugin** called **"ServerTrack — Native Server-Side Events"**.

This plugin is a **zero-dependency, self-hosted replacement** for third-party server-side
tagging services like Stape.io or Google Tag Manager Server-Side containers.

It runs entirely on the merchant's own WordPress/WooCommerce hosting environment.
It costs the merchant nothing beyond what they already pay for hosting.
It requires no external proxy, no Docker container, no separate server.

---

## SECTION 2 — THE CORE PROBLEM YOU ARE SOLVING

### The Pain
Browser-based tracking (Meta Pixel, Google Tag, TikTok Pixel) is increasingly
unreliable due to:
- Ad blockers stripping pixel requests
- iOS / Safari ITP blocking third-party cookies
- Browser-level privacy restrictions

The industry solution is **server-side event sending** — the server sends purchase
and lead data directly to ad platforms via their APIs, bypassing the browser entirely.

### The Existing Solutions & Why They Fail Small Businesses
- **Stape.io**: $20–$99/month. Unaffordable for new businesses
- **GTM Server-Side**: Requires Cloud Run / GCP knowledge, $30–$100/month infra
- **Native API integrations**: Require developer expertise most store owners lack

### Your Solution
A WordPress plugin that:
1. Hooks into WooCommerce and other WordPress systems natively
2. Sends events **server-to-server** directly from PHP to ad platform APIs
3. Deduplicates events properly between browser and server signals
4. Requires zero external infrastructure
5. Is configurable through a clean WordPress admin UI
6. Is free at its core, with a premium tier for advanced features

---

## SECTION 3 — PLUGIN IDENTITY & STRUCTURE

```
Plugin Name:     ServerTrack — Native Server-Side Events
Plugin Slug:     servertrack-native
Text Domain:     servertrack
Version:         1.0.0
Requires WP:     6.0+
Requires PHP:    7.4+
Requires WC:     7.0+
License:         GPLv2 or later
```

### File Structure (Must Follow Exactly)
```
servertrack-native/
│
├── servertrack-native.php          ← Main plugin file, bootstrap only
│
├── includes/
│   ├── class-servertrack-core.php         ← Plugin init, hooks registration
│   ├── class-servertrack-event.php        ← Base event model (all platforms share this)
│   ├── class-servertrack-dedup.php        ← Deduplication engine
│   ├── class-servertrack-hasher.php       ← PII hashing utility (SHA-256)
│   ├── class-servertrack-logger.php       ← Debug log handler
│   └── class-servertrack-consent.php      ← Consent/GDPR gate
│
├── platforms/
│   ├── class-servertrack-meta.php         ← Meta CAPI sender
│   ├── class-servertrack-google.php       ← Google Ads Enhanced Conversions sender
│   └── class-servertrack-tiktok.php       ← TikTok Events API sender
│
├── sources/
│   ├── class-servertrack-woocommerce.php  ← WooCommerce event source
│   ├── class-servertrack-cf7.php          ← Contact Form 7 event source
│   └── class-servertrack-edd.php          ← Easy Digital Downloads event source
│
├── admin/
│   ├── class-servertrack-admin.php        ← Admin menu + settings page
│   ├── views/
│   │   ├── settings-general.php
│   │   ├── settings-meta.php
│   │   ├── settings-google.php
│   │   ├── settings-tiktok.php
│   │   └── settings-debug.php
│   └── assets/
│       ├── admin.css
│       └── admin.js
│
├── frontend/
│   ├── class-servertrack-frontend.php     ← Browser pixel injector + event_id generator
│   └── assets/
│       └── servertrack-pixel.js           ← Lightweight browser JS for dedup event_id
│
└── languages/
    └── servertrack.pot
```

---

## SECTION 4 — DEDUPLICATION ARCHITECTURE (MOST CRITICAL SECTION)

This is the most important technical concept in the entire plugin.
**Ad platforms receive events from TWO sources: the browser pixel AND the server.**
Without deduplication, every purchase is counted twice, ruining campaign data.

### How Deduplication Works

**Step 1 — Event ID Generation (Server, on page load)**
When WooCommerce loads the order-received (thank you) page, the PHP backend:
- Generates a unique `event_id` using: `md5( 'purchase_' . $order_id . '_' . SECURE_AUTH_KEY )`
- Stores this in WooCommerce order meta: `_servertrack_event_id`
- Also stores a `sent_to_server` flag: `_servertrack_server_sent` (initially `false`)

**Step 2 — Browser Pixel Fires (Client-side)**
The browser-side pixel (Meta Pixel, TikTok Pixel) fires a `Purchase` event
with the `event_id` embedded — passed from PHP into the page as a JS variable.

**Step 3 — Server Event Fires (PHP, async)**
WordPress fires the server-side event via a background WP-Cron job or
immediate `wp_remote_post()` call, using the SAME `event_id`.

**Step 4 — Platform Deduplication**
Meta, Google, and TikTok all accept `event_id`. When they receive two events
with the same `event_id` within a time window, they keep only one.

### Deduplication Rules to Enforce in Code
- `event_id` must be generated ONCE and stored BEFORE either signal fires
- `event_id` must be IDENTICAL in both browser and server payloads
- After server event fires successfully, store `_servertrack_server_sent = true`
- Never resend a server event that already has `_servertrack_server_sent = true`
- Log every send attempt with timestamp, platform, event_id, and HTTP response code

### Dedup Class Responsibilities (`class-servertrack-dedup.php`)
```
generate_event_id( $context_string )   → returns deterministic unique ID
store_event_id( $order_id, $event_id ) → saves to order meta
get_event_id( $order_id )              → retrieves stored event_id
mark_as_sent( $order_id, $platform )   → prevents double server send
was_sent( $order_id, $platform )       → boolean check before sending
```

---

## SECTION 5 — EVENT SOURCES & WHAT THEY TRACK

### 5A — WooCommerce Events
Hook into WooCommerce natively using WordPress action hooks.
Never use polling, cron-only, or REST API for primary event capture.

| Event Name     | WooCommerce Hook                          | Platforms      | Notes                            |
|----------------|-------------------------------------------|----------------|----------------------------------|
| Purchase       | `woocommerce_thankyou`                    | Meta, TikTok   | Primary purchase signal          |
| Purchase       | `woocommerce_order_status_completed`      | Google         | Google prefers completed status  |
| ViewContent    | `woocommerce_after_single_product`        | Meta, TikTok   | Product page view                |
| AddToCart      | `woocommerce_add_to_cart`                 | Meta, TikTok   | Cart event (server enrichment)   |
| InitiateCheckout| `woocommerce_before_checkout_form`       | Meta, TikTok   | Checkout start                   |
| Lead           | `woocommerce_created_customer`            | Meta, TikTok   | New account registration         |

**Data to Collect Per WooCommerce Order:**
- `order_id` — WooCommerce order ID
- `currency` — order currency code
- `value` — order total (float)
- `contents` — array of `{ id, quantity, item_price }` for each line item
- `email` — billing email (must be SHA-256 hashed before sending)
- `phone` — billing phone (must be SHA-256 hashed before sending)
- `first_name` — billing first name (must be SHA-256 hashed before sending)
- `last_name` — billing last name (must be SHA-256 hashed before sending)
- `city` — billing city (must be SHA-256 hashed before sending)
- `state` — billing state (must be SHA-256 hashed before sending)
- `zip` — billing postcode (must be SHA-256 hashed before sending)
- `country` — billing country (must be SHA-256 hashed before sending)
- `ip_address` — customer IP (never hashed, sent raw for matching)
- `user_agent` — browser user agent (never hashed, sent raw for matching)
- `fbp` — Meta browser cookie `_fbp` (read from cookie, never hash)
- `fbc` — Meta click ID cookie `_fbc` (read from cookie, never hash)
- `ttclid` — TikTok click ID from URL param or cookie

### 5B — Contact Form 7 Events
Triggered on: `wpcf7_mail_sent` action hook.

| Event Name | Hook               | Platforms     | Notes                           |
|------------|--------------------|---------------|---------------------------------|
| Lead       | `wpcf7_mail_sent`  | Meta, TikTok  | Map form fields to user data    |

Configuration: Admin can map CF7 form fields (email, name, phone) to tracking fields
per form ID. Store mapping in: `servertrack_cf7_mappings` option.

### 5C — Easy Digital Downloads Events

| Event Name | Hook                            | Platforms          |
|------------|---------------------------------|--------------------|
| Purchase   | `edd_complete_purchase`         | Meta, Google, TikTok |
| Lead       | `edd_user_registration`         | Meta, TikTok       |

EDD orders use `edd_get_payment_meta()` to retrieve customer and product data.

---

## SECTION 6 — PLATFORM API SPECIFICATIONS

### 6A — Meta Conversions API (CAPI)

**Endpoint:** `https://graph.facebook.com/v18.0/{pixel_id}/events`
**Method:** POST
**Auth:** `access_token` (System User Token from Meta Events Manager)

**Required Settings:**
- `meta_pixel_id` — e.g. `123456789012345`
- `meta_access_token` — System User Token
- `meta_test_event_code` — optional, for Meta Test Events tool

**Payload Structure:**
```
{
  "data": [
    {
      "event_name": "Purchase",
      "event_time": {unix_timestamp},
      "event_id": "{dedup_event_id}",
      "event_source_url": "{checkout_url}",
      "action_source": "website",
      "user_data": {
        "em": ["{sha256_hashed_email}"],
        "ph": ["{sha256_hashed_phone}"],
        "fn": ["{sha256_hashed_first_name}"],
        "ln": ["{sha256_hashed_last_name}"],
        "ct": ["{sha256_hashed_city}"],
        "st": ["{sha256_hashed_state}"],
        "zp": ["{sha256_hashed_zip}"],
        "country": ["{sha256_hashed_country}"],
        "client_ip_address": "{raw_ip}",
        "client_user_agent": "{raw_ua}",
        "fbp": "{_fbp_cookie_value}",
        "fbc": "{_fbc_cookie_value}"
      },
      "custom_data": {
        "currency": "USD",
        "value": 99.00,
        "contents": [{"id": "sku123", "quantity": 1, "item_price": 99.00}],
        "content_type": "product"
      }
    }
  ],
  "test_event_code": "{optional}"
}
```

**Hashing Rule:** All PII fields (em, ph, fn, ln, ct, st, zp, country) must be:
1. Lowercased
2. Trimmed of whitespace
3. SHA-256 hashed
4. Hex-encoded

### 6B — Google Ads Enhanced Conversions

**Endpoint:** `https://googleads.googleapis.com/v14/customers/{customer_id}/conversionActions/{conversion_action_id}:uploadClickConversions`
**Method:** POST
**Auth:** OAuth 2.0 Bearer Token

**Required Settings:**
- `google_customer_id` — Google Ads Customer ID (no dashes)
- `google_conversion_action_id` — Conversion action resource name
- `google_developer_token` — From Google Ads API Center
- `google_oauth_client_id` — OAuth App Client ID
- `google_oauth_client_secret` — OAuth App Client Secret
- `google_oauth_refresh_token` — Offline refresh token (user grants once)

**Payload Structure:**
```
{
  "conversions": [
    {
      "gclid": "{gclid_from_url_or_cookie}",
      "conversion_action": "customers/{customer_id}/conversionActions/{id}",
      "conversion_date_time": "2024-01-01 12:00:00+00:00",
      "conversion_value": 99.00,
      "currency_code": "USD",
      "order_id": "{woocommerce_order_id}",
      "user_identifiers": [
        {
          "hashed_email": "{sha256_email}"
        },
        {
          "address_info": {
            "hashed_first_name": "{sha256_fn}",
            "hashed_last_name": "{sha256_ln}",
            "hashed_street_address": "{sha256_address}",
            "city": "{city_raw}",
            "state": "{state_raw}",
            "postal_code": "{zip_raw}",
            "country_code": "{country_raw}"
          }
        }
      ]
    }
  ],
  "partial_failure": true
}
```

**Token Refresh Logic:**
- On each API call, check if stored `access_token` has expired
- If expired, POST to `https://oauth2.googleapis.com/token` with `refresh_token`
- Store new `access_token` and `expires_in` to WordPress options

**GCLID Capture:**
- On page load, if `gclid` param exists in URL, store in cookie `_gcl_aw` for 90 days
- Read `_gcl_aw` cookie value when building Google payload

### 6C — TikTok Events API

**Endpoint:** `https://business-api.tiktok.com/open_api/v1.3/event/track/`
**Method:** POST
**Auth:** `Access-Token` header (TikTok Events API Access Token)

**Required Settings:**
- `tiktok_pixel_id` — TikTok Pixel ID
- `tiktok_access_token` — Events API Access Token from TikTok Events Manager

**Payload Structure:**
```
{
  "pixel_code": "{tiktok_pixel_id}",
  "event_source": "web",
  "partner_name": "ServerTrack",
  "data": [
    {
      "event": "CompletePayment",
      "event_time": {unix_timestamp},
      "event_id": "{dedup_event_id}",
      "user": {
        "email": "{sha256_hashed_email}",
        "phone_number": "{sha256_hashed_phone}",
        "first_name": "{sha256_hashed_first_name}",
        "last_name": "{sha256_hashed_last_name}",
        "ip": "{raw_ip}",
        "user_agent": "{raw_ua}",
        "ttclid": "{ttclid_value}"
      },
      "properties": {
        "currency": "USD",
        "value": 99.00,
        "contents": [{"content_id": "sku123", "quantity": 1, "price": 99.00}],
        "content_type": "product"
      },
      "page": {
        "url": "{current_page_url}"
      }
    }
  ]
}
```

**TikTok Event Name Mapping:**
| Standard Name  | TikTok API Name     |
|----------------|---------------------|
| Purchase       | CompletePayment      |
| Lead           | SubmitForm           |
| ViewContent    | ViewContent          |
| AddToCart      | AddToCart            |
| InitiateCheckout | InitiateCheckout   |

**TTCLID Capture:**
- On page load, if `ttclid` param in URL, store in cookie for 7 days
- Read cookie when building TikTok payload

---

## SECTION 7 — HTTP SENDING IMPLEMENTATION

All API calls must use WordPress's native `wp_remote_post()` — never `curl` directly.
This ensures compatibility across all hosting environments.

### Standard Send Pattern
```
$response = wp_remote_post( $endpoint_url, [
    'method'    => 'POST',
    'timeout'   => 15,
    'headers'   => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $access_token,  // platform-specific
    ],
    'body'      => wp_json_encode( $payload ),
] );

if ( is_wp_error( $response ) ) {
    ServerTrack_Logger::log( 'error', $platform, $response->get_error_message() );
    return false;
}

$code = wp_remote_retrieve_response_code( $response );
$body = wp_remote_retrieve_body( $response );
ServerTrack_Logger::log( 'success', $platform, $code, $body );
return ( $code >= 200 && $code < 300 );
```

### Async Sending (Preferred for Purchase Events)
Use `wp_schedule_single_event()` with a custom action to send events
in a background WP-Cron job. This prevents delays on the checkout thank-you page.

Pattern:
1. WooCommerce hook fires
2. Store event data in order meta immediately
3. Schedule a WP-Cron event: `wp_schedule_single_event( time(), 'servertrack_send_event', [ $args ] )`
4. Cron handler retrieves data from order meta and fires API calls

---

## SECTION 8 — ADMIN SETTINGS UI

The admin interface lives at: **WordPress Admin → Settings → ServerTrack**

Use WordPress Settings API (`register_setting`, `add_settings_section`, `add_settings_field`).
Do NOT use custom post types or REST API for settings storage.
Store all settings in `wp_options` using `servertrack_` prefix.

### Settings Tabs Structure
1. **General** — Enable/disable plugin, test mode toggle, consent mode selector
2. **Meta CAPI** — Pixel ID, Access Token, Test Event Code, event toggles
3. **Google Ads** — Customer ID, Conversion Action ID, Developer Token, OAuth flow
4. **TikTok Events** — Pixel ID, Access Token, event toggles
5. **Sources** — Enable/disable WooCommerce, CF7, EDD independently
6. **Debug Log** — Last 50 log entries, clear log button, "Send Test Event" per platform

### Settings Options Reference
```
servertrack_enabled                → bool
servertrack_test_mode              → bool
servertrack_consent_mode           → enum: none | cookie_yes | complianz | manual

servertrack_meta_enabled           → bool
servertrack_meta_pixel_id          → string
servertrack_meta_access_token      → string
servertrack_meta_test_event_code   → string

servertrack_google_enabled         → bool
servertrack_google_customer_id     → string
servertrack_google_conversion_id   → string
servertrack_google_developer_token → string
servertrack_google_client_id       → string
servertrack_google_client_secret   → string
servertrack_google_refresh_token   → string
servertrack_google_access_token    → string
servertrack_google_token_expires   → int (unix timestamp)

servertrack_tiktok_enabled         → bool
servertrack_tiktok_pixel_id        → string
servertrack_tiktok_access_token    → string

servertrack_source_woo_enabled     → bool
servertrack_source_cf7_enabled     → bool
servertrack_source_edd_enabled     → bool

servertrack_cf7_mappings           → json (per form field mapping)
servertrack_debug_log              → json (rolling array of last 50 entries)
```

---

## SECTION 9 — CONSENT & GDPR HANDLING

The plugin must respect user consent before sending PII to ad platforms.

### Consent Modes
1. **None** — Send all events to all platforms (for non-EU stores)
2. **CookieYes** — Check `cookieyes-analytics` and `cookieyes-advertisement` cookie values
3. **Complianz** — Use `cmplz_statistics` and `cmplz_marketing` cookies
4. **Manual** — Developer defines their own consent check via `servertrack_consent_granted` filter

### Consent Check Pattern
Before every API send:
```
if ( ! ServerTrack_Consent::is_granted( $platform ) ) {
    ServerTrack_Logger::log( 'skipped', $platform, 'Consent not granted' );
    return;
}
```

### Minimal Mode (No Consent)
If consent is NOT granted but the platform supports it:
- Strip all PII from payload
- Send only non-PII data (event name, timestamp, value, currency, event_id)
- This allows aggregate reporting without individual matching

---

## SECTION 10 — PII HASHING

All personally identifiable fields must be SHA-256 hashed before leaving the server.

### Hashing Rules (Apply Universally to All Platforms)
1. Convert string to lowercase
2. Trim leading and trailing whitespace
3. For phone numbers: strip all non-numeric characters, add country code prefix if missing
4. Apply `hash( 'sha256', $normalized_value )`
5. Return lowercase hex string (PHP's `hash()` returns lowercase hex by default)

### Fields That Must NEVER Be Hashed
- `ip_address` — sent raw
- `user_agent` — sent raw
- `fbp` — sent raw
- `fbc` — sent raw
- `ttclid` — sent raw
- `gclid` — sent raw
- `currency` — not PII
- `value` — not PII
- `order_id` — not PII

### Fields That Must ALWAYS Be Hashed
- email
- phone
- first_name
- last_name
- city
- state / region
- zip / postal code
- country (for Meta only; Google and TikTok accept raw country codes)

---

## SECTION 11 — BROWSER-SIDE PIXEL COORDINATOR

Even though this plugin sends server-side, it also manages browser pixel loading
to ensure the `event_id` is shared between browser and server.

### `servertrack-pixel.js` Responsibilities
1. Read the `servertrack_config` JS object injected by PHP via `wp_localize_script()`
2. This object contains: `event_id`, `event_name`, platform pixel IDs, and `test_mode`
3. Fire browser pixel with the same `event_id` that PHP has stored in order meta
4. Never fire the browser pixel independently — always coordinate with PHP-generated event_id

### PHP-to-JS Data Bridge
```php
wp_localize_script( 'servertrack-pixel', 'servertrack_config', [
    'event_id'    => $event_id,
    'event_name'  => $event_name,
    'value'       => $order_total,
    'currency'    => $currency,
    'meta_pixel'  => get_option( 'servertrack_meta_pixel_id' ),
    'tiktok_pixel'=> get_option( 'servertrack_tiktok_pixel_id' ),
    'test_mode'   => get_option( 'servertrack_test_mode' ),
] );
```

---

## SECTION 12 — DEBUG & LOGGING SYSTEM

### Log Entry Structure
Every event send attempt must produce a log entry:
```
[
    'timestamp'  => '2024-01-01 12:00:00',
    'platform'   => 'meta | google | tiktok',
    'event'      => 'Purchase',
    'event_id'   => 'abc123...',
    'order_id'   => 456,
    'status'     => 'success | error | skipped | dedup_blocked',
    'http_code'  => 200,
    'response'   => '{ "events_received": 1 }',
]
```

Store log as a rolling JSON array in `servertrack_debug_log` option.
Maximum 50 entries. When full, remove the oldest before appending.

### Test Event Button
In the debug tab, a "Send Test Event" button for each platform sends a dummy
`Lead` event with fake hashed data to verify credentials and connectivity.
Display the raw API response in the UI so users can diagnose issues.

---

## SECTION 13 — CODING STANDARDS & CONSTRAINTS

### PHP Standards
- Minimum PHP 7.4 — use typed properties and return types where supported
- All classes prefixed with `ServerTrack_`
- All functions prefixed with `servertrack_`
- All options prefixed with `servertrack_`
- All hooks prefixed with `servertrack_`
- Nonces on all admin forms: `wp_verify_nonce()` before processing
- Capability check on all admin actions: `current_user_can( 'manage_options' )`
- Sanitize all inputs: `sanitize_text_field()`, `absint()`, `sanitize_email()`
- Escape all outputs: `esc_html()`, `esc_attr()`, `esc_url()`
- Never use `$_GET` or `$_POST` directly — always use `wp_unslash()` first

### WordPress Patterns
- Use `wp_remote_post()` — never raw curl
- Use `wp_schedule_single_event()` for async sends
- Use `get_option()` / `update_option()` for all settings
- Use `wp_json_encode()` — never `json_encode()`
- Use `wp_generate_uuid4()` for any UUID generation needs
- Register all scripts and styles with `wp_register_script()` / `wp_enqueue_script()`

### Security
- API credentials stored in `wp_options` — never hardcoded, never in files
- Sensitive options (tokens, secrets) displayed masked in admin UI (`str_repeat('*', 20)`)
- Consider recommending `wp-config.php` constant definitions for production credentials

### No External Dependencies
- Zero Composer packages
- Zero npm packages at runtime
- Zero external HTTP requests except to the three defined platform API endpoints
- The plugin must install and activate on any shared hosting environment

---

## SECTION 14 — WHAT TO BUILD FIRST (DEVELOPMENT ORDER)

Build in this exact order to avoid dependency issues:

1. `servertrack-native.php` — bootstrap, define constants, load classes
2. `class-servertrack-hasher.php` — PII hashing (no dependencies)
3. `class-servertrack-logger.php` — logging system (no dependencies)
4. `class-servertrack-dedup.php` — deduplication engine (depends on logger)
5. `class-servertrack-consent.php` — consent gate (no dependencies)
6. `class-servertrack-event.php` — base event model (depends on hasher, dedup)
7. `class-servertrack-admin.php` + views — settings UI (depends on options)
8. `platforms/class-servertrack-meta.php` — Meta CAPI sender
9. `platforms/class-servertrack-google.php` — Google sender + OAuth
10. `platforms/class-servertrack-tiktok.php` — TikTok sender
11. `sources/class-servertrack-woocommerce.php` — WooCommerce hooks
12. `sources/class-servertrack-cf7.php` — CF7 hooks
13. `sources/class-servertrack-edd.php` — EDD hooks
14. `class-servertrack-frontend.php` — browser pixel coordinator + JS bridge
15. `frontend/assets/servertrack-pixel.js` — browser-side coordinator script
16. End-to-end test: Place WooCommerce test order → verify all three platforms receive event → verify dedup works

---

## SECTION 15 — KNOWN EDGE CASES TO HANDLE

- **Order status changes**: Some payment gateways trigger `woocommerce_thankyou` but
  never reach `completed` status (e.g. PayPal IPN delays). Handle both hooks but
  use `_servertrack_server_sent` flag to prevent duplicate server sends.

- **Guest checkout**: No WordPress user ID. Rely on billing email for user matching.
  Never require login for tracking to work.

- **Page caching**: On cached thank-you pages, browser pixel may fire but PHP hooks
  may not re-run. Use the WooCommerce order status hook as the authoritative server
  trigger, not the page load hook.

- **Subscription renewals**: WooCommerce Subscriptions creates renewal orders
  programmatically (no browser). Only server-side events fire. Do NOT attempt to
  fire browser pixel for renewals.

- **Refunds**: Do not send Purchase events for orders that are subsequently refunded.
  If an order is refunded, check `_servertrack_server_sent` and log a note but
  do not reverse-send to platforms (they don't support negative conversions via CAPI).

- **High volume**: For stores with 100+ orders/day, async WP-Cron sending is mandatory.
  Synchronous sends on checkout would cause timeout issues.

- **Missing cookies**: If `_fbp`, `_fbc`, or `ttclid` cookies are absent (cookie blockers,
  direct traffic), send the payload without those fields. Never send empty string values
  for optional fields — omit the field entirely.

---

*End of Master Prompt — ServerTrack Native Server-Side Events Plugin v1.0*
*This document is the single source of truth for all development decisions.*
*When in doubt, refer back to this document before writing any code.*