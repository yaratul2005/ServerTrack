# Ratul Ads Conversion Tracker (Ratul-ACT)

<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.0+-21759b?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress Compatibility" />
  <img src="https://img.shields.io/badge/PHP-8.0+-777bb4?style=for-the-badge&logo=php&logoColor=white" alt="PHP Compatibility" />
  <img src="https://img.shields.io/badge/WooCommerce-7.0+-96588a?style=for-the-badge&logo=woocommerce&logoColor=white" alt="WooCommerce Compatibility" />
  <img src="https://img.shields.io/badge/Ad_Blocker-Resilient-ef4444?style=for-the-badge&logo=shield&logoColor=white" alt="Ad-Blocker Resiliency" />
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Meta_CAPI-Active_Deduplication-0668e1?style=for-the-badge&logo=meta&logoColor=white" alt="Meta Conversions API" />
  <img src="https://img.shields.io/badge/TikTok_Events-API_v2-000000?style=for-the-badge&logo=tiktok&logoColor=white" alt="TikTok Events API" />
  <img src="https://img.shields.io/badge/Google_Ads-Enhanced_Conversions-4285f4?style=for-the-badge&logo=google&logoColor=white" alt="Google Ads Enhanced Conversions" />
</p>

---

## 🌟 Executive Summary

**Ratul Ads Conversion Tracker (Ratul-ACT)** is a self-hosted, enterprise-grade first-party Conversion API (CAPI) and browser tracking gateway for WordPress and WooCommerce. 

Designed to bypass ad-blockers and privacy regulations (iOS 14+, Safari ITP), Ratul-ACT routes tracking events directly through your own domain. It synchronizes browser event ids and server dispatches to provide Meta, Google, and TikTok with perfect deduplication data. By eliminating dependency on expensive external tag containers like Stape.io or Google Cloud GTM, it acts as a direct, self-hosted CAPI engine on your WordPress server.

---

## 🚀 Key Features & Capabilities

| Feature | Description | Business Benefit |
| :--- | :--- | :--- |
| **First-Party Cookie Engine** | Issues first-party `Set-Cookie` headers directly via PHP, protecting tracking identifiers (`fbclid`, `gclid`) from Safari's 7-day script caps. | Extends attribution lifespans to **2 years** |
| **Ad-Blocker Resilience** | Proxies client-side events through a local WordPress REST endpoint. | Recovers up to **20–30%** of lost checkout signals |
| **Signal Enrichment** | Extracts IP, City, State, ZIP, and User-Agent parameters dynamically on the client, with fallbacks for Cloudflare and Nginx. | Maximizes Meta Event Match Quality (EMQ) |
| **Anti-Fraud Approval** | Prevents automatic purchase tracking, allowing manual confirmation and fraud checks from the admin order screen. | Prevents fake/refunded orders from polluting ad algorithms |
| **Fail-Safe Retry Queue** | Exponential back-off retry loop that automatically resends failed platform payloads. | Guarantees delivery of server-side conversions |

---

## 📊 Live Verification & Deduplication

### 1. Meta Event Manager Deduplication
Both client-side browser triggers and server-side CAPI webhooks report matching Event IDs, allowing Meta to successfully deduplicate actions:
* **View Content**: ![ViewContent Deduplication](pluginss/vc.png)
* **Add to Cart**: ![AddToCart Deduplication](pluginss/add2c.png)
* **Initiate Checkout**: ![InitiateCheckout Deduplication](pluginss/init_ch.png)

---

## 🖥️ UI Dashboard & Configuration

### Real-Time Performance Analytics
Inspect event metrics, health stats, API response logs, and queue retries in real-time.
![Real-Time Dashboard](pluginss/settings/Dashboard.png)

### Multi-Pixel Meta CAPI Setup
Loop dispatches to multiple Meta Pixels with toggle controls for PII parameter hashing.
![Meta Settings](pluginss/settings/Meta.png)

### WooCommerce Advanced Sources
Configure extended WooCommerce triggers (order lifecycle status, wishlist opt-ins) and toggle Manual Purchase Verification.
![WooCommerce Event Sources Settings](pluginss/settings/eventS.png)

---

## 🛠️ Installation & Activation

1. Upload the `ratul-ads-conversion-tracker` directory to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **Ratuls-ACT** → **Settings** to set up your ad platform access tokens.
4. Monitor active events and match metrics in **Ratuls-ACT** → **Dashboard**.

---

## 📚 Technical Documentation

To explore the deeper mechanics of the plugin, refer to the accompanying documentation files:
* 🛠️ **[ARCHITECTURE.md](ARCHITECTURE.md)**: Details the codebase design, data flows, database deduplication engine, and ITP cookie bypass logic.
* 🛡️ **[COMPLIANCE.md](COMPLIANCE.md)**: Explains GDPR/CCPA cookie consent manager integrations (CookieYes, Complianz) and the data security PII blocklist.

---

## 📄 License & Credits
Licensed under GPL-2.0-or-later. Created and maintained by **MD. Yaser Ahmmed Ratul**.
