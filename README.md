<div align="center">

<img src="assets/logo/logo_st.png" alt="ServerTrack Logo" width="320" style="margin-bottom: 24px;">

# ServerTrack Native

### The professional server-side tracking plugin for WordPress — built lean, direct, and free.

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0+-96588a.svg)](https://woocommerce.com/)
[![Graph API](https://img.shields.io/badge/Meta%20Graph%20API-v22.0-1877f2.svg)](https://developers.facebook.com/docs/marketing-api/conversions-api)
[![Zero Dependencies](https://img.shields.io/badge/Dependencies-Zero-brightgreen.svg)]()

**ServerTrack moves your conversion tracking entirely to the server.**  
No third-party cloud. No monthly fees. No middlemen. Just clean, direct API calls from your own infrastructure.

[Why Server-Side?](#-why-server-side-tracking) • [Features](#-features) • [Architecture](#-architecture) • [Platforms](#-supported-platforms) • [Events](#-tracked-events) • [Installation](#-installation) • [Configuration](#-configuration) • [Deduplication](#-event-deduplication) • [Debug Log](#-debug-log) • [FAQ](#-faq)

</div>

---

## 📸 Gallery

<p align="center">
  <img src="assets/img_1.png" alt="ServerTrack Admin Dashboard" width="48%">
  <img src="assets/img_2.png" alt="Meta CAPI Settings" width="48%">
</p>
<p align="center">
  <img src="assets/img_3.png" alt="Google Ads Enhanced Conversions" width="48%">
  <img src="assets/img_4.png" alt="TikTok Events API Settings" width="48%">
</p>
<p align="center">
  <img src="assets/img_5.png" alt="Event Sources & CF7 Field Mapper" width="48%">
  <img src="assets/img_6.png" alt="Real-Time Debug Log" width="48%">
</p>

---

## 🤔 Why Server-Side Tracking?

Modern browsers and privacy tools are systematically destroying browser-only tracking:

| Blocker | Impact on browser pixels |
|---|---|
| **iOS ITP (Safari)** | Caps cookie lifetime to 7 days (1 day in some cases) |
| **Ad blockers (uBlock, etc.)** | Silently drops 30–40% of pixel fires |
| **Firefox Enhanced Tracking Protection** | Blocks third-party scripts by default |
| **Chrome Privacy Sandbox** | Removing third-party cookies entirely |

**ServerTrack solves this at the root.** By sending conversion data directly from your WordPress server to each platform's official API, events are completely invisible to browser-level blockers, cookie restrictions, and ITP. Your conversion data is complete, accurate, and reliable — regardless of what the visitor's browser does.

---

## ✨ Features

### Core Engine
- **⚡ Zero Dependencies** — Built purely with core PHP and native WordPress APIs. No Composer autoloader, no NPM build step, no vendor folders, no third-party SDK bloat.
- **🛡️ 100% Server-Side Delivery** — Events are sent directly from your server to Meta, Google, and TikTok's official APIs. Your WordPress server IS the tracking server.
- **⏱️ Non-Blocking Async Architecture** — Purchase events are dispatched via `wp_schedule_single_event` + `spawn_cron()` and executed in a separate PHP process. Your thank-you page renders instantly.
- **🔗 Intelligent Deduplication** — A shared `event_id` system ensures the browser pixel and the server API always reference the same event. Meta, TikTok, and Google automatically deduplicate — users are counted once.
- **🔁 Automatic Retry Queue** — Transient network failures are caught and re-queued automatically. Events are never silently dropped.
- **🔒 PII Hashing** — Every piece of Personally Identifiable Information (email, phone, name, address) is SHA-256 hashed before leaving your server. Raw data never reaches the ad platforms.

### Tracking Quality
- **📊 Advanced Matching** — Sends hashed email, phone number, first name, last name, city, state, zip, and country alongside every purchase event for maximum Match Quality Score (MQS).
- **🍪 Click ID Capture** — Automatically captures and persists `fbc` (Facebook click), `fbp` (Facebook browser), `gclid` (Google click), and `ttclid` (TikTok click) cookies across the full session for accurate attribution.
- **🌍 Real IP Detection** — Strips IPv4-mapped IPv6 prefixes (`::ffff:`) and correctly reads real visitor IPs behind proxies via `HTTP_X_FORWARDED_FOR` and `HTTP_CF_CONNECTING_IP` (Cloudflare-aware).
- **🛒 Full WooCommerce Funnel** — Tracks every stage from product view to refund. Handles HPOS (High-Performance Order Storage), subscription renewals, and external payment gateways that skip the thank-you page.

### Admin & Developer Experience
- **🎨 Native WordPress UI** — The admin panel inherits your WordPress theme colors perfectly. Zero external CSS frameworks, instant load.
- **🪲 Real-Time Debug Log** — Every CAPI call is logged with its HTTP status code, raw API response, event ID, and timestamp. The last 50 events are retained and displayed live in the Debug Log tab.
- **📋 CF7 Visual Field Mapper** — A drag-and-drop-style mapper lets you link Contact Form 7 field tags directly to tracking parameters without touching code.
- **🔐 GDPR/CCPA Consent Mode** — Built-in `granted`/`denied` consent state management. Events are blocked per-platform until consent is given.

---

## 🏗️ Architecture

ServerTrack is designed around a clean separation of concerns. Every layer has a single responsibility.

```
┌─────────────────────────────────────────────────────────────────────┐
│                        BROWSER LAYER                                │
│   fbq() / ttq() / gtag()   ──→   Platform Pixel (browser JS)       │
│   sendToServer(event_id)   ──→   WP REST API bridge                 │
└────────────────────────────┬────────────────────────────────────────┘
                             │ Shared event_id (dedup key)
┌────────────────────────────▼────────────────────────────────────────┐
│                       SERVER LAYER (PHP)                            │
│                                                                     │
│  Source Hooks              Event DTO          Platform Senders      │
│  ─────────────             ─────────          ────────────────      │
│  WooCommerce     ──→       ServerTrack  ──→   Meta CAPI v22.0      │
│  Contact Form 7  ──→       _Event{}     ──→   Google Enhanced Conv  │
│  EDD             ──→                   ──→   TikTok Events API      │
│                                                                     │
│  Cross-cutting: Dedup | Retry | Logger | Hasher | Consent           │
└─────────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
servertrack/
├── servertrack-native.php          # Plugin bootstrap & header
├── uninstall.php                   # Clean uninstall (removes all options/transients)
│
├── admin/                          # Admin UI (tabs, settings pages, AJAX handlers)
├── frontend/                       # Browser pixel injection & REST bridge
│
├── includes/                       # Core classes
│   ├── class-servertrack-event.php         # Event Data Transfer Object (DTO)
│   ├── class-servertrack-dedup.php         # Event ID generation & sent-state locking
│   ├── class-servertrack-retry.php         # Retry queue for failed API calls
│   ├── class-servertrack-logger.php        # Debug log (WP transient store)
│   ├── class-servertrack-hasher.php        # SHA-256 PII hashing
│   └── class-servertrack-consent.php       # Consent state management
│
├── platforms/                      # Platform API senders
│   ├── class-servertrack-meta.php          # Meta Conversions API v22.0
│   ├── class-servertrack-google.php        # Google Enhanced Conversions
│   └── class-servertrack-tiktok.php        # TikTok Events API
│
├── sources/                        # Event source integrations
│   ├── class-servertrack-woocommerce.php   # WooCommerce (full funnel)
│   ├── class-servertrack-cf7.php           # Contact Form 7
│   └── class-servertrack-edd.php           # Easy Digital Downloads
│
├── assets/                         # CSS, JS, images
└── languages/                      # i18n (.pot / .po / .mo)
```

### Key Design Decisions

| Decision | Rationale |
|---|---|
| `wp_remote_post()` exclusively | Respects WordPress HTTP API filters, proxy settings, and SSL configuration. No raw cURL. |
| Async via `wp_schedule_single_event` + `spawn_cron()` | Decouples CAPI latency (~200–800ms) from page render. Thank-you pages are instant. |
| Shared `event_id` DTO | Browser and server events for the same action share a single ID, enabling platform-level deduplication. |
| SHA-256 hashing in PHP | PII is hashed before the HTTP call. Raw data never leaves your server. |
| Session transient dedup for `InitiateCheckout` | Prevents dozens of duplicate CAPI fires per checkout session (common issue with AJAX-heavy checkouts). |
| HPOS-compatible order meta | Uses `WC_Order::update_meta_data()` + `save()` instead of `update_post_meta()` for full WooCommerce HPOS compatibility. |
| Graph API v22.0 | v21.0 was deprecated and caused silent drops in the Meta Test Events tool. Locked to v22.0. |

---

## 🔌 Supported Platforms

### Ad Platforms

| Platform | API | API Version | Events Supported |
|---|---|---|---|
| **Meta (Facebook/Instagram)** | Conversions API (CAPI) | Graph API v22.0 | Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Lead, PageView |
| **Google Ads** | Enhanced Conversions API | Google Ads API | Purchase, Lead |
| **TikTok** | Events API (server-side) | v1.3 | Purchase, ViewContent, AddToCart, InitiateCheckout, CompleteRegistration |

### Event Sources

| Source | Plugin Required | Events Tracked |
|---|---|---|
| **WooCommerce** | WooCommerce 7.0+ | Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund |
| **Contact Form 7** | CF7 5.0+ | Lead (with visual field mapper) |
| **Easy Digital Downloads** | EDD 3.0+ | Purchase, CompleteRegistration |

---

## 📡 Tracked Events

### WooCommerce Full Funnel

| Event | Hook | Trigger | Async? | Dedup Guard |
|---|---|---|---|---|
| `ViewContent` | `woocommerce_after_single_product` | Product page load | ✅ Yes (cron) | Unique UUID per view |
| `AddToCart` | `woocommerce_add_to_cart` | Add to cart action | ❌ Sync | Unique UUID per action |
| `InitiateCheckout` | `woocommerce_before_checkout_form` | Checkout page load | ❌ Sync | Session transient (30 min TTL) |
| `AddPaymentInfo` | `woocommerce_checkout_order_created` | Order created | ❌ Sync | Order meta `_servertrack_api_sent` |
| `Purchase` | `woocommerce_thankyou` | Thank-you page | ✅ Yes (cron) | Order meta + `mark_as_sent()` |
| `Purchase` (fallback) | `woocommerce_order_status_completed` | Gateway status change | ✅ Yes (cron) | `was_sent()` check before dispatch |
| `CompleteRegistration` | `woocommerce_created_customer` | New account creation | ❌ Sync | Unique per `customer_id` |
| `Refund` | `woocommerce_order_status_refunded` | Refund issued | ❌ Sync | Blocks future sends via `_servertrack_refunded` |

### Advanced Matching Data Sent with Purchase

```
email (SHA-256)    phone (SHA-256)    first_name (SHA-256)    last_name (SHA-256)
city (SHA-256)     state (SHA-256)    zip (SHA-256)           country (SHA-256)
client_ip_address  client_user_agent  fbc                     fbp
```

---

## 🔁 Event Deduplication

This is the most critical piece of any dual-tracking setup. ServerTrack solves it completely.

### How It Works

Every event that can fire from both browser and server shares a single `event_id`. This ID is generated once, stored server-side, and passed to both the PHP CAPI sender and the browser pixel via `wp_localize_script`.

```
Browser (fbq):  InitiateCheckout  →  event_id: "br_177abc..."
Server (CAPI):  InitiateCheckout  →  event_id: "br_177abc..."
                                               ↑
                                  Same ID → Meta deduplicates → 1 event counted
```

### Deduplication Methods by Event Type

| Event Type | Dedup Method |
|---|---|
| `Purchase` | Stable ID stored in order meta (`_servertrack_event_id`). Marked sent per-platform. Idempotent. |
| `InitiateCheckout` | Session transient keyed to WC customer session ID. TTL: 30 minutes. |
| `AddPaymentInfo` | Order meta flag `_servertrack_api_sent`. Checked before every send. |
| `ViewContent` | Unique UUID per page load (intentional — every view is a distinct signal). |
| `AddToCart` | Unique UUID per action (intentional — every add is distinct). |

### What You See in Meta Test Events

When deduplication is working correctly, you will see:

```
InitiateCheckout  [Processed]        ← Server CAPI (event_id: br_177...)
└── InitiateCheckout  [Processed]    ← Browser pixel (same event_id)
    └── InitiateCheckout  [Deduplicated]  ← Correctly removed duplicate
```

The **Deduplicated** badge confirms Meta received both signals and correctly counted only one.

---

## 🚀 Installation

### Requirements

- WordPress 6.0+
- PHP 7.4+ (PHP 8.x fully supported)
- WooCommerce 7.0+ *(for WooCommerce source)*
- WP-Cron enabled *(or a real cron job configured)*
- HTTPS on your domain *(required by Meta, Google, and TikTok APIs)*

### Steps

1. **Download** the repository as a ZIP (`Code → Download ZIP`) or clone it:
   ```bash
   git clone https://github.com/yaratul2005/ServerTrack.git
   ```

2. **Upload** the `ServerTrack` folder to:
   ```
   /wp-content/plugins/ServerTrack/
   ```

3. **Activate** via **WordPress Admin → Plugins → Installed Plugins → ServerTrack Native → Activate**

4. **Navigate** to **Settings → ServerTrack** and follow the Configuration Guide below.

---

## ⚙️ Configuration

### Tab 1 — General

| Setting | Description |
|---|---|
| **Enable Plugin** | Master on/off switch. Disabling stops all CAPI calls instantly. |

### Tab 2 — Meta CAPI

| Setting | Where to Get It |
|---|---|
| **Enable Meta CAPI** | Toggle to activate Meta Conversions API sending |
| **Meta Pixel ID** | Meta Events Manager → Data Sources → your Pixel → Settings |
| **System User Access Token** | Meta Business Suite → Settings → System Users → Generate Token |
| **Test Event Code** | Meta Events Manager → Test Events tab (only for testing — leave blank in production) |

> **Important:** Use a **System User Access Token**, not a personal user token. System tokens never expire.

### Tab 3 — Google Ads

| Setting | Where to Get It |
|---|---|
| **Customer ID** | Google Ads → Account header (format: `123-456-7890`) |
| **Conversion Action ID** | Google Ads → Tools → Conversions → your conversion → ID in URL |
| **Developer Token** | Google Ads API Center → apply for access |
| **OAuth Client ID / Secret** | Google Cloud Console → APIs & Services → Credentials |

> ServerTrack handles OAuth token refresh automatically. You only need to authorize once.

### Tab 4 — TikTok Events

| Setting | Where to Get It |
|---|---|
| **Pixel ID** | TikTok Events Manager → your pixel → Settings |
| **Access Token** | TikTok Events Manager → your pixel → Settings → Generate Access Token |

### Tab 5 — Sources

Enable or disable individual event sources. Each source can be toggled independently without affecting other platforms.

### Tab 6 — Debug Log

Real-time log of all CAPI calls. Each entry shows:
- Event name and type
- Platform targeted (Meta / Google / TikTok)
- HTTP response code (`200` = success)
- Raw API response body
- `event_id` used
- Timestamp

**This is your first diagnostic tool.** Check it immediately after configuring credentials.

---

## 🪲 Debug Log

The Debug Log tab is a live audit trail of every server-side event ServerTrack fires.

### Reading Log Entries

| Status | Meaning |
|---|---|
| `success` + `200` | Event received and accepted by the platform |
| `dedup_blocked` | Event was already sent for this order/session — correctly skipped |
| `skipped` | Consent not granted, or subscription renewal excluded |
| `error` | API rejected the event — check the raw response for details |

### Common Error Codes

| Code | Platform | Likely Cause |
|---|---|---|
| `400` | Meta | Malformed payload — often missing `user_data` fields |
| `401` | Meta | Invalid or expired Access Token |
| `403` | Google | OAuth credentials invalid or token needs refresh |
| `0` | Any | WordPress could not reach the API — check server firewall or `wp_remote_post` |

---

## 🆚 ServerTrack vs. Commercial Alternatives

| | **ServerTrack** | PixelYourSite Pro | Stape.io | WP Pixel Manager |
|---|---|---|---|---|
| **Price** | 🆓 Free | ~$100/yr | ~$29–99/mo | ~$99/yr |
| **Meta CAPI** | ✅ | ✅ | ✅ | ✅ |
| **TikTok Events API** | ✅ | ✅ | ✅ | ❌ |
| **Google Enhanced Conv.** | ✅ | ✅ | ✅ | ✅ |
| **Deduplication** | ✅ | ✅ | ✅ | ✅ |
| **Advanced Matching** | ✅ | ✅ | ✅ | ✅ |
| **Async Purchase Events** | ✅ | ❌ | ✅ | ❌ |
| **Zero Dependencies** | ✅ | ❌ | N/A | ❌ |
| **Data on your server** | ✅ | ✅ | ❌ (cloud) | ✅ |
| **Server-Side GTM needed** | ❌ Not needed | ❌ Not needed | ✅ Required | ❌ Not needed |
| **Open Source** | ✅ GPLv2 | ❌ | ❌ | ❌ |
| **HPOS Compatible** | ✅ | ✅ | N/A | ✅ |
| **Subscription Renewals** | ✅ | ✅ | ✅ | ❌ |
| **CF7 Field Mapper** | ✅ | ✅ | ❌ | ❌ |
| **Debug Log** | ✅ Built-in | ✅ | ✅ Dashboard | ✅ |
| **Graph API Version** | v22.0 (latest) | v21.0 | v22.0 | v20.0 |

---

## ❓ FAQ

**Q: Do I need a server-side GTM container?**  
No. ServerTrack sends events directly from your WordPress server to each platform's API. A GTM server container is a middleman — ServerTrack replaces it entirely for Meta, Google, and TikTok.

**Q: Will this work with WooCommerce HPOS (High-Performance Order Storage)?**  
Yes. All order meta operations use `WC_Order::update_meta_data()` and `WC_Order::save()` — the HPOS-compatible methods. `update_post_meta()` is never used for order data.

**Q: What happens if a CAPI call fails?**  
The Retry module catches failures and re-queues them as WordPress cron events. Events are re-attempted automatically — they are never silently dropped.

**Q: I see a "Deduplicated" badge in Meta Test Events. Is that bad?**  
No — that is the correct behavior. It means Meta received the event from both the browser pixel and the server CAPI with the same `event_id`, and correctly counted it once. A "Deduplicated" badge is proof deduplication is working.

**Q: Why do I see two "Processed" server events for InitiateCheckout?**  
This happens when the PHP hook (`woocommerce_before_checkout_form`) fires independently from the REST bridge call from the browser. Both send to CAPI with different `event_id`s. To fully resolve this, disable the PHP hook for InitiateCheckout and rely solely on the browser-triggered REST bridge for that event — the REST bridge already carries the shared browser `event_id`.

**Q: Should I leave the Test Event Code blank in production?**  
Yes. The Test Event Code routes events to the Meta Test Events dashboard instead of the live pipeline. In production, remove it so events count toward real conversions, ad delivery, and custom audiences.

**Q: Does ServerTrack work behind Cloudflare?**  
Yes. The real IP detection reads `HTTP_CF_CONNECTING_IP` when available, correctly passing the visitor's real IP to CAPI even when Cloudflare proxies the request.

**Q: Is WP-Cron required?**  
WP-Cron is used for async Purchase and ViewContent events. If your host disables WP-Cron, configure a real server cron job to call `wp-cron.php` every minute. Without it, async events may be delayed until the next visitor triggers a page load.

---

## 🔐 Security & Privacy

- All PII (email, phone, name, address) is **SHA-256 hashed in PHP** before transmission — raw data never leaves your server
- API credentials are stored in WordPress options and never exposed to the frontend
- All user inputs are sanitized with `sanitize_text_field()`, `sanitize_email()`, and `absint()` before use
- Nonces protect all AJAX and admin form submissions
- The plugin is fully self-contained — no outbound connections except to official ad platform APIs

---

## 🧑‍💻 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

```bash
# Clone the repository
git clone https://github.com/yaratul2005/ServerTrack.git

# The plugin is pure PHP — no build step required
# Just activate in WordPress and start developing
```

**Code standards:** WordPress Coding Standards (WPCS). All PHP must be compatible with PHP 7.4+.

---

## 📜 License

Distributed under the **GNU General Public License v2.0 or later**.  
See [LICENSE](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) for full text.

---

<div align="center">

**Developed by [Yaser Ahmmed Ratul](https://yaratul.com)**  
Comilla, Bangladesh

*Built lean. Built direct. Built to last.*

</div>
