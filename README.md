# Conditional Checkout Fields and Options for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-21759b)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-9.9.3%2B-96588a)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![HPOS](https://img.shields.io/badge/HPOS-compatible-2ea44f)](https://developer.woocommerce.com/docs/hpos-extension-recipe-book/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue)](LICENSE)

Conditional rules for the checkout you already have. Show, hide or require existing checkout fields, offer or remove payment methods and shipping rates, hide the "includes tax" note, make orders VAT exempt or switch to another existing tax class — all based on what the customer enters and what is in the cart.

The plugin **does not add fields** and **does not change labels, placeholders or defaults**. It decides *when* the existing fields, payment methods and shipping rates apply, and the server checks every rule again when the order is placed.

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Rule reference](#rule-reference)
- [Conflict policy](#conflict-policy)
- [Classic checkout and Checkout Block](#classic-checkout-and-checkout-block)
- [Taxes](#taxes)
- [How it works](#how-it-works)
- [Data, privacy and uninstall](#data-privacy-and-uninstall)
- [REST API and hooks](#rest-api-and-hooks)
- [Development](#development)
- [Testing](#testing)
- [Project structure](#project-structure)
- [Release process](#release-process)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Features

- **Checkout fields** — show, hide or require any field registered by WooCommerce, the theme or another plugin (billing, shipping, account, order). Changes appear while the customer types; hidden fields are never required and their values are not stored.
- **Payment methods** — offer a gateway only when conditions match, or remove it.
- **Shipping methods** — the same for a whole method (`flat_rate`) or a single zone rate (`flat_rate:3`). Cached rates can never bypass a rule.
- **Taxes** — hide the "(includes … tax)" note, make the order VAT exempt, or use another *existing* tax class. Totals are always calculated by WooCommerce.
- **Order rules** — minimum and maximum order amounts and more: hold back "Proceed to checkout" (cart, mini cart, Cart Block) or "Place order" (classic checkout, Checkout Block) with your own message, e.g. "Add {remaining} more to order". The server refuses the order whatever the browser sends.
- **31 condition sources** — the value of any other checkout field; cart subtotal (excl. or incl. tax), total after discounts, discount, shipping cost, tax, order total, the subtotal of any product category, the most expensive and cheapest product price, coupons, quantity and weight; products, categories (sub-categories count for their parents), tags, shipping classes; chosen payment and shipping method; guest or role; billing/shipping country/state/postcode; tax state.
- **23 operators**, AND inside a group, OR between groups, priorities and a documented conflict policy.
- **Admin screen** (WooCommerce → Checkout Rules) with live checks: conflicting rules, rules that can remove every payment or shipping method, targets that no longer exist, and what the Checkout Block supports.
- **Clear outcome when everything is removed** — customers see your message, no order is created, and the admin gets a notice and a log entry.
- **Server-side enforcement** in the classic checkout and the Store API (Checkout Block).
- **Order record** — the rules that applied are stored on the order (`_ccfo_applied_rules`) and shown on the order screen.
- HPOS and Cart & Checkout Blocks compatibility declared. Translation ready.

## Requirements

| | Minimum | Tested with |
|---|---|---|
| WordPress | 6.6 | 7.1.3 |
| WooCommerce | 9.9.3 | 11.2.1 |
| PHP | 8.1 | 8.4 |

## Installation

**From a release zip**

1. Download `conditional-checkout-fields-and-options-for-woocommerce.zip`.
2. In WordPress: *Plugins → Add New → Upload Plugin*, choose the zip, install and activate.
3. Open *WooCommerce → Checkout Rules*.

**From source**

```bash
git clone https://github.com/ankhaa0103/conditional-checkout-fields-and-options-for-woocommerce.git
cd conditional-checkout-fields-and-options-for-woocommerce
npm ci
npm run build
npm run plugin-zip   # creates the installable zip
```

> **Never symlink or junction this repository into `wp-content/plugins`.** When a plugin is deleted or replaced from the WordPress admin, WordPress follows the link and deletes the repository's files. Copy the plugin instead (see [Development](#development)).

## Quick start

*Offer bank transfer only to customers who enter a company name:*

1. *WooCommerce → Checkout Rules → Add rule*.
2. **Applies to:** Payment method → *Direct bank transfer*. **Action:** *Offer only when…*
3. **Condition:** *Field value: Billing: Company name* → *is not empty*. Save.

A guest who types a company name sees bank transfer as soon as they leave the field (or after the *Live update delay* while still typing — default 5 seconds, *Settings* tab).

The same rule as stored JSON (also the import/export format):

```json
{
  "id": "r_bacs_company",
  "title": "Bank transfer for companies",
  "enabled": true,
  "priority": 10,
  "target": { "type": "payment", "key": "bacs" },
  "action": "enable",
  "groups": [
    [ { "source": "field:billing_company", "operator": "not_empty" } ]
  ]
}
```

## Rule reference

### Targets and actions

| Target | Key | Action | When conditions match | When they do not |
|---|---|---|---|---|
| `field` | field key, e.g. `billing_company` | `show` | shown | **hidden** |
| | | `hide` | hidden | unchanged |
| | | `require` | shown and required | original required state |
| `payment` | gateway id, e.g. `cod` | `enable` | offered | **removed** |
| | | `disable` | removed | unchanged |
| `shipping` | method `flat_rate` or rate `flat_rate:3` | `enable` / `disable` | as payment | as payment |
| `tax_display` | `checkout` | `hide_tax_note` | "(includes … tax)" hidden | — |
| `tax_calc` | `cart` | `set_vat_exempt` | order is VAT exempt | — |
| | | `set_tax_class` | cart items use the chosen existing tax class | — |
| `order` | `order` | `block_checkout` | "Proceed to checkout" and "Place order" held back, message shown, order refused | normal |
| | | `block_order` | "Place order" held back (checkout only), message shown, order refused | normal |

A rule without conditions always applies.

### Condition sources

Some sources cannot be used for some targets, so a rule can never depend on its own result.

| Source | Value | field | payment | shipping | tax | order |
|---|---|:-:|:-:|:-:|:-:|:-:|
| `field:<key>` | value of another checkout field | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.subtotal` | items subtotal before discounts, excl. tax | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.subtotal_incl_tax` | items subtotal before discounts, incl. tax | ✓ | ✓ | ✓ | — | ✓ |
| `cart.contents_total` | items total after discounts, excl. tax | ✓ | ✓ | ✓ | — | ✓ |
| `cart.discount_total` | discount amount, excl. tax | ✓ | ✓ | ✓ | — | ✓ |
| `cart.shipping_total` | shipping cost, excl. tax | ✓ | ✓ | — | — | ✓ |
| `cart.tax_total` | tax amount | ✓ | ✓ | — | — | ✓ |
| `cart.total` | order total incl. shipping and tax | ✓ | ✓ | — | — | ✓ |
| `cart.category_subtotal:<term id>` | subtotal of the items in a category (and its sub-categories), excl. tax, before discounts | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.max_item_price`, `cart.min_item_price` | most expensive / cheapest unit price, excl. tax | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.coupons` | coupon codes applied | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.item_count`, `cart.qty`, `cart.weight` | lines, quantity, weight | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.products`, `cart.categories`, `cart.tags`, `cart.shipping_classes` | ids in the cart | ✓ | ✓ | ✓ | ✓ | ✓ |
| `payment.method` | chosen gateway | ✓ | — | — | ✓ | ✓ |
| `shipping.method` | chosen rate and its method | ✓ | ✓ | — | ✓ | ✓ |
| `customer.is_guest`, `customer.role` | customer | ✓ | ✓ | ✓ | ✓ | ✓ |
| `billing.country/state/postcode`, `shipping.country/state/postcode` | address | ✓ | ✓ | ✓ | ✓ | ✓ |
| `tax.vat_exempt`, `tax.has_tax` | tax state | ✓ | ✓ | — | — | ✓ |

Tax calculation rules run before WooCommerce calculates totals, so they cannot read amounts that come out of that calculation. The category subtotal and the product prices are computed from product prices, so every rule can use them.

### Order rule messages

| Placeholder | Value |
|---|---|
| `{min}` / `{remaining}` | limit of the first "less than" condition of the matching group / how much is missing |
| `{max}` / `{excess}` | limit of the first "greater than" condition / how much is over |
| `{current}` | current value of the first number condition |

Money is shown in the store currency, the cart weight with its unit. A rule without a message uses the default message from the *Settings* tab.

### Operators

| Value kind | Operators |
|---|---|
| Text | `equals`, `not_equals`, `contains`, `not_contains`, `in`, `not_in`, `empty`, `not_empty` |
| Number | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `between` |
| List (products, terms, roles, chosen rate) | `any_in`, `all_in`, `none_in`, `only_in`, `empty`, `not_empty` |
| Yes/no | `is_true`, `is_false` |
| Checkout field | text operators, `checked`, `unchecked`, `gt`, `gte`, `lt`, `lte` |

Text comparison ignores case and surrounding spaces. A value that is not a number fails every number test.

## Conflict policy

1. When several rules for the same target match, the **lowest priority number wins**.
2. On a tie, the **restrictive action wins**: `hide` over `show`/`require`, `disable` over `enable`.
3. If `show`/`enable` rules exist for a target and none matches, the target is hidden/removed.
4. Order rules have no conflicts: every matching rule blocks; the priority only orders the messages.
5. If rules remove **every** payment method or **every** shipping rate, checkout stops with your message (*Settings* tab), no order is created, and the admin sees a notice; the event is logged with source `ccfo` in *WooCommerce → Status → Logs*.

The admin screen warns about conflicting rules and rules that can remove every method before you save.

## Classic checkout and Checkout Block

| Rule | Classic checkout | Checkout Block |
|---|---|---|
| Field `show` / `hide` | ✅ live + server | — not possible with the official APIs (marked on each rule) |
| Field `require` | ✅ live `*` + server | ⚠️ checked when the order is placed |
| Payment `enable` / `disable` | ✅ | ✅ |
| Shipping `enable` / `disable` | ✅ | ✅ |
| `hide_tax_note` | ✅ | ✅ |
| `set_vat_exempt`, `set_tax_class` | ✅ | ✅ (the chosen payment method is sent to the server) |
| Order `block_checkout` | ✅ cart, mini cart and checkout buttons + message | ✅ Cart Block and Checkout Block buttons + notice |
| Order `block_order` | ✅ "Place order" + message | ✅ "Place order" + notice |

Fields added by other plugins with `woocommerce_register_additional_checkout_field()` are listed in the admin as managed by their own plugin. Details: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Taxes

- Tax **display** rules only change what the checkout page shows. Cart and order taxes, Store API tax lines, emails and invoices are never changed.
- Hiding separate tax rows when prices are shown excluding tax is intentionally not offered: subtotal plus shipping would not add up to the total. If a customer should not pay tax, use `set_vat_exempt`.
- `set_vat_exempt` only removes exemptions it set itself; exemptions set by other code are left alone.
- `set_tax_class` uses existing tax classes only and also stores the class on order line items, so recalculating an order in the admin gives the same taxes.
- VAT number validation (VIES) and creating tax rates are out of scope.

## How it works

```
Admin (React) ──REST ccfo/v1──▶ option ccfo_rules (JSON, autoload off)
                                        │
             ┌──────────────────────────▼──────────────────────────┐
             │ RuleSchema → RuleIndex → Context → Evaluator → Resolver │  ← the server decides
             └──────┬──────────────────────┬──────────────────────┬─┘
        Classic checkout hooks      Store API (Checkout Block)     JS evaluator (live UI only)
```

- One PHP rule engine serves the classic checkout and the Store API; payment and shipping rules use `woocommerce_available_payment_gateways` and `woocommerce_package_rates`, which both flows share.
- The browser runs a mirrored JS evaluator only to update the form immediately. PHP and JS are tested against the same exhaustive matrix.
- When payment, shipping or tax rules read form values, the classic checkout is refreshed only when a condition result changes.

Full design: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Requirements: [docs/SPEC.md](docs/SPEC.md).

## Data, privacy and uninstall

| Stored | Where |
|---|---|
| Rules | option `ccfo_rules` |
| Settings (messages, live update delay) | option `ccfo_settings` |
| Recent "everything removed" events | option `ccfo_blocked_log` |
| Field catalog snapshot | option `ccfo_field_snapshot` |
| Rules applied to an order | order meta `_ccfo_applied_rules` (HPOS-safe CRUD) |

The plugin sends no data to external services. Uninstalling deletes the options above; order meta is kept as a record of what happened at checkout.

## REST API and hooks

All routes are under `/wp-json/ccfo/v1`, require the `manage_woocommerce` capability and the REST nonce.

| Method | Route | Purpose |
|---|---|---|
| `GET` / `PUT` | `/rules` | read / save the full rule set (`revision` prevents overwriting a newer save — `409`) |
| `POST` | `/lint` | validate unsaved rules and return findings |
| `GET` | `/catalog` | fields, gateways, shipping methods and rates, roles, countries, terms, tax classes |
| `GET` | `/products?search=` / `?include[]=` | product search and labels |
| `GET` / `POST` | `/settings` | customer messages and live update delay |
| `GET` / `DELETE` | `/blocked` | read / clear "everything removed" events |

Action: `ccfo_rules_saved( int $revision )` fires after the rule set is saved.

## Development

### Requirements

PHP 8.1+, Composer, Node.js and npm, a local WordPress site with WooCommerce (the project uses WAMP on Windows; any local stack works).

### Setup

```bash
composer install
npm ci
npm run build
```

Deploy to a local site **as a copy** (refuses to write through a link):

```powershell
powershell -File tools/deploy.ps1                 # default site: brand.mn
powershell -File tools/deploy.ps1 -Site example.test
```

### Commands

| Command | What it does |
|---|---|
| `composer lint` | PHPCS (WordPress Coding Standards) |
| `composer analyse` | PHPStan level 6 with WordPress and WooCommerce stubs |
| `composer test` | PHP unit tests (no WordPress needed) |
| `composer test:int` | PHP integration tests against a local site |
| `npm run lint:js` | ESLint |
| `npm run test:js` | Jest |
| `npm run test:e2e:smoke` | representative Playwright set (21 tests) |
| `npm run test:e2e` | full Playwright suite |
| `npm run build` / `npm run start` | build / watch the admin and checkout scripts |
| `npm run plugin-zip` | installable zip without development files |

Integration and E2E tests need test data on the local site: `php tools/wp-cli.phar --path=<site> eval-file tools/fixtures.php setup` (and `teardown` to remove it). Test fixtures are tracked and removed completely.

## Testing

| Layer | What it covers |
|---|---|
| **Parity matrix** (`tests/unit/MatrixTest.php`, `tests/js/matrix.test.js`) | 4,290 operator cases, 504 action combinations (priorities, ties, disabled rules, original required state) and 2,955 AND/OR shapes; PHP = JS = an oracle written from the spec |
| **Schema matrix** (`tests/unit/SchemaMatrixTest.php`) | all 4,278 target × source × operator combinations, every action on every target, 400+ invalid values — expected tables copied from the spec |
| **Order rules** (`tests/unit/OrderRulesTest.php`, `MessagesTest.php`) | message validation, placeholders, linter; "never matches" checked against brute force for 1,140 condition groups |
| **Integration** (`tests/integration/`) | every source in every request mode (amounts against `WC_Cart`), every target × action through the real WooCommerce hooks, order rules on the cart, mini cart, checkout and a real Store API checkout, classic and Store API refusals of removed methods, shipping rate cache, taxes classic = block, REST API, settings, uninstall |
| **JS runtime** (`tests/js/runtime.test.js`, `order-guard.test.js`) | the classic checkout script in a DOM: visibility, required markup, values of every client source; the Cart/Checkout Block order guard |
| **E2E** (`tests/e2e/`) | real browser: classic cart and checkout, Cart Block, Checkout Block, admin screen; `@smoke` is a representative subset |

## Project structure

```
conditional-checkout-fields-and-options-for-woocommerce.php   plugin header, bootstrap, compatibility declarations
src/
  Rules/      Definitions, Rule, RuleSchema, RuleIndex, RuleRepository, Linter
  Engine/     Context, ContextFactory, Evaluator, Operators, Resolver, Decision, RequestLog
  Classic/    Fields, Payments, Shipping, Validation, Assets
  Blocks/     StoreApi, Integration
  Tax/        TaxRules
  Order/      Restrictions, Messages
  Admin/      Menu, RestController, StoreInfo, Notices
assets/src/   admin (React), classic, blocks, shared/evaluator.js
assets/css/   checkout styles
build/        compiled scripts (generated)
languages/    translation template
tests/        unit, integration, js, e2e, fixtures
tools/        deploy.ps1, fixtures.php, wporg-screenshots.js, wporg-assets.js
docs/         SPEC, ARCHITECTURE, COMPATIBILITY, MANUAL-TEST
.wordpress-org/  WordPress.org icon, banners and screenshots (SVN /assets, not in the plugin zip)
.github/      issue and pull request templates, WordPress.org workflows
```

## Release process

Every code change goes through the same steps:

1. Run the checks: `composer lint`, `composer analyse`, `composer test`, `composer test:int`, `npm run lint:js`, `npm run test:js`, `npm run test:e2e:smoke` (full E2E for broad changes).
2. `npm run build` and `npm run plugin-zip`.
3. Deploy the copy to the test site with `tools/deploy.ps1`.
4. Update [CHANGELOG.md](CHANGELOG.md) and, for a release, the version in the plugin header, `CCFO_VERSION`, `package.json` and `readme.txt`.
5. Commit and push to `main`.

### WordPress.org

The plugin zip (`npm run plugin-zip`) contains only the files listed in `package.json` → `files`, including the human-readable sources in `assets/src` that WordPress.org requires for built scripts.

First submission (once):

1. Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) on the zip contents and fix every error and warning.
2. Upload the zip at <https://wordpress.org/plugins/developers/add/> while logged in as the plugin owner, and answer the review team's emails.
3. After approval, set the SVN password in your WordPress.org profile (*Account & Security*), then add the repository secrets `SVN_USERNAME` and `SVN_PASSWORD` and the repository variable `WPORG_ENABLED` = `true` on GitHub.

Each release:

1. Set the same version in the plugin header, `CCFO_VERSION`, `package.json` and `readme.txt` (`Stable tag`), and add the changelog entry to `readme.txt` and [CHANGELOG.md](CHANGELOG.md).
2. Push a tag with the bare version, e.g. `git tag 1.0.1 && git push origin 1.0.1`. The [deploy workflow](.github/workflows/wporg-deploy.yml) checks the versions, builds, commits to SVN `trunk` and `tags/<version>`, updates `/assets` from `.wordpress-org/` and attaches the zip to a GitHub release.

Readme-only or asset-only changes (for example a new `Tested up to`) are published by the [assets workflow](.github/workflows/wporg-assets.yml) when they reach `main`.

Screenshots and the icon and banners are generated: `node tools/wporg-screenshots.js` (needs the test site and E2E fixtures) and `node tools/wporg-assets.js`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
