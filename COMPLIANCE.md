# Privacy Compliance & Security Guide (Ratul-ACT)

This document outlines the privacy design, GDPR/CCPA consent integrations, and data protection features implemented in **Ratul Ads Conversion Tracker**.

---

## 🛡️ GDPR & CCPA Consent Integrations

Ratul-ACT respects visitor privacy and features automated integrations with top WordPress Cookie Consent plugins. It holds event fires (both client-side and server-side) until the user grants analytical and marketing consent.

### Supported Consent Managers

1. **CookieYes**
   * Detects the `cookieyes_consent_update` custom event.
   * If a user accepts `analytics` and `advertisement` categories, tracking activates instantly.
2. **Complianz**
   * Detects the `cmplz_status_change` status hook.
   * Activates tracking once `statistics` and `marketing` consent are granted.
3. **Custom Consent Dispatcher**
   * If you use a custom banner, dispatch a JavaScript custom event:
     ```javascript
     window.dispatchEvent(new CustomEvent('st:consent:granted', {
         detail: { platforms: ['meta', 'tiktok', 'google'] }
     }));
     ```

---

## 🔒 PII Redaction & Data Security

To prevent accidental transmission of sensitive data (like plain text passwords, credit card numbers, or SSNs) to ad network servers, Ratul-ACT includes a robust sanitization blocklist.

### 1. The PII Parameter Blocklist
Any parameter sent to the `/custom-event` REST endpoint is parsed through a strict blocklist before log insertion or CAPI transmission:
* **Stripped Fields:** `email`, `phone`, `credit_card`, `card_number`, `cvv`, `ssn`, `password`, `token`, `api_key`, `secret`, `access_token`, `authorization`, `customer_id`, `user_id`.

### 2. Regular Expression Sanitization
The values inside custom parameters are dynamically evaluated using regular expressions:
* **Credit Card Numbers:** Strings matching `/\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}/` are replaced with `REDACTED_CREDIT_CARD`.
* **Social Security Numbers:** Strings matching `/^\d{3}-\d{2}-\d{4}/` are replaced with `REDACTED_SSN`.

---

## 🔗 Local Proxy Routing

Traditional pixel tracking sends requests directly from the user's browser to Meta's or TikTok's servers. Ad-blockers detect and block these domains (`facebook.net`, `tiktok.com`).

Ratul-ACT solves this using **local proxy routing**:
1. Client-side triggers send tracking payloads to your local WordPress REST API:
   `/wp-json/ratul-ads-conversion-tracker/v1/custom-event`
2. Since the request is made to your own domain, browser-level trackers do not block it.
3. The WordPress server receives the request, strips unhashed or blocklisted parameters, and dispatches the conversion event to Meta/TikTok/Google securely over a server-to-server connection.
