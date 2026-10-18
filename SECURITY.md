# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |
| < 0.1 | ❌ |

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Include:

- the affected version and WordPress / WooCommerce versions,
- steps to reproduce or a proof of concept,
- the impact you expect (for example: bypassing a payment or shipping rule, privilege escalation in the REST API, stored XSS in the admin screen).

The maintainer aims to acknowledge reports within 7 days. Fixes are prepared privately, released, and then the advisory is published with credit to the reporter unless you prefer otherwise.

## Scope

In scope:

- Bypassing a rule on the server (an order placed with a removed payment method or shipping rate, a required field skipped, a hidden field stored).
- The REST API under `/wp-json/ccfo/v1` (capability checks, nonce, validation).
- Output escaping in the admin screen and on the checkout page.
- Import of rule files.

Out of scope:

- Changes that require the `manage_woocommerce` capability to make (store managers can configure rules by design).
- Vulnerabilities in WordPress, WooCommerce, themes or other plugins — report those to their maintainers.
- Client-side behaviour alone: the browser only updates the form early; the server checks every rule again.

## Design notes

- The server is the only authority; every rule is enforced in PHP on order placement, in the classic checkout and in the Store API.
- All REST routes require `manage_woocommerce`; rules are validated against a strict schema before they are stored.
- The plugin makes no outgoing network requests and stores no personal data beyond the rule ids applied to an order.
