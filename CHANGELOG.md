# Changelog

## 0.1.1 — 2026-10-18

- Fix: payment, shipping and tax rules that read a checkout field (for example "offer BACS when the company name is filled in") now work for guests and for values typed during checkout. The classic checkout script was only loaded for field rules, so WooCommerce never refreshed the payment list when such a field changed; it only worked when the value was already saved in the customer profile.
- The classic checkout refreshes only when the result of such a condition changes: right away when the customer leaves the field, and at the latest after the "Live update delay" (default 5 seconds) while they are still typing.
- New setting: WooCommerce → Checkout Rules → Settings → Live update delay (0–30 seconds). The "Customer messages" tab is now "Settings".
- Fix: product conditions in the admin showed ids instead of names, and choosing a product from the search saved an invalid value. The server now also rejects product/term conditions that are not ids.
- Fix: product labels no longer repeat the id.

## 0.1.0 — 2026-10-17

First release.

- Rule engine with groups (AND/OR), 23 operators, priorities and conflict policy; PHP and JS evaluators share one test fixture set.
- Classic checkout: show/hide/require for any registered field, live in the browser and checked again on submit; hidden values are not stored.
- Payment and shipping method rules for the classic checkout and the Checkout Block (Store API), including a shipping rate cache key so cached rates never bypass rules.
- Tax rules: hide the "includes tax" note, VAT exemption, switch to an existing tax class.
- Checkout Block: `require` rules enforced on order placement, payment method sync for rules that read it.
- Admin screen (WooCommerce → Checkout Rules): rule editor, live checks for conflicts and rules that remove every method, import/export, customer messages, notice when rules blocked a checkout.
- Applied rules stored on the order (`_ccfo_applied_rules`, HPOS compatible).
