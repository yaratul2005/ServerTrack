# ServerTrack Changelog

## v3.3.1 — 2026-05-11

### Bug Fixes (WooCommerce Source)

- **BUG-09** `handle_order_status_change()` — dedup loop used `return` instead of `continue`; a single already-sent platform silently dropped the event for ALL platforms. Fixed: count per-platform, skip only when all 3 are done.
- **BUG-10** `fire_add_to_wishlist_event()` — dedup loop result was discarded; `dispatch_to_platforms()` was called unconditionally, guaranteeing duplicate wishlist events. Fixed: build `$pending_platforms` array, bail if empty, dispatch only to unsent platforms.
- **BUG-11** `handle_add_to_cart()` — signature declared 3 params but `woocommerce_add_to_cart` passes 6 args, causing PHP warnings on debug/strict sites. Fixed: added `$variation_id`, `$variation`, `$cart_item_data` params.
- **BUG-12** `handle_full_refund()` — dedup check only covered `meta`; TikTok and Google full-refund events were silently dropped if Meta was already sent. Fixed: check all 3 platforms before firing.

---

## v3.3.0 — 2026-05-11

### New Features

#### Admin Dashboard v2.0
- Auto-refresh live event log (AJAX, every 30 s, no page reload)
- Per-platform doughnut chart (Meta / TikTok / Google, 7-day window)
- Top-5 event types horizontal bar chart
- EMQ Scorecard with colour-coded grade pills (Excellent / Good / Fair / Poor)
- Retry queue panel with "Drain all now" AJAX button
- Clear log action (nonce-guarded, confirmation required)
- Live counter badge in header

#### WooCommerce Source — Order Status Events
- `on-hold` → fires `Lead` to all platforms
- `failed` → fires `Contact` to all platforms
- `cancelled` → fires `SubmitForm` to all platforms
- Dedup key: `order_status_{order_id}_{status}`
- Toggle: `servertrack_source_order_status_enabled` (default: on)

#### WooCommerce Source — AddToWishlist Events
- Supports YITH WooCommerce Wishlist and TI WooCommerce Wishlist
- Fires `AddToWishlist` to Meta + TikTok only (Google GA4 has no native wishlist event)
- Includes `content_ids`, `content_name`, `value`, `currency`
- Toggle: `servertrack_source_wishlist_enabled` (default: off, opt-in)

#### WooCommerce Source — Partial Refund Events
- Hook: `woocommerce_order_refunded`
- Differentiates partial vs full via ±0.01 float tolerance
- Sends `Purchase` with negative value = exact refund amount
- Dedup key: `partial_refund_{refund_id}` — exactly-once per refund object
- Toggle: `servertrack_source_partial_refund_enabled` (default: on)

#### Retry v2.2
- `process_queue()` public alias → delegates to `process()`; used by dashboard drain-all AJAX
- `event_name` stored as top-level key in queue items for dashboard display
- `last_attempt` timestamp stamped on every attempt

---

## v3.2.0 (prior)
- Subscription Renewal events (Refund, Renewal)
- Cart Abandonment integration

## v3.0 – v3.1 (prior)
- Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund
