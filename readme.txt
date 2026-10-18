=== Conditional Checkout Fields and Options for WooCommerce ===
Contributors: ankhaa0103
Tags: woocommerce, checkout, conditional fields, payment methods, shipping methods
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show, hide or require existing checkout fields, and limit payment methods, shipping rates and tax handling with conditions.

== Description ==

Conditional rules for the checkout you already have. The plugin does not add fields and does not change labels, placeholders or defaults; it decides **when** the existing fields, payment methods and shipping rates apply. The server checks every rule again when the order is placed.

= What you can do =

* **Checkout fields** – show, hide or require any field registered by WooCommerce, your theme or another plugin (billing, shipping, account and order fields). Changes appear while the customer types. Hidden fields are never required and their values are not stored.
* **Payment methods** – offer a gateway only when conditions match, or remove it.
* **Shipping methods** – the same for a whole shipping method or a single zone rate. Cached shipping rates can never bypass a rule.
* **Taxes** – hide the "(includes … tax)" note, make the order VAT exempt, or use another existing tax class. Totals are always calculated by WooCommerce.

= Conditions =

* The value of another checkout field (text, number, checkbox, select)
* Cart subtotal, order total, number of lines, quantity and weight
* Products, product categories (sub-categories count for their parents), tags and shipping classes in the cart – any, all, none or only
* Chosen payment method and chosen shipping method
* Guest or logged-in customer, customer role
* Billing and shipping country, state and postcode
* VAT exemption and whether the order has tax

Conditions inside a group must all match (AND); a rule applies when any group matches (OR). 23 operators: is / is not, contains, is one of, is empty, is checked, greater / less than, between, contains any / all / none / only, and more.

= Clear rules, clear outcomes =

* **Priorities and a documented conflict policy** – the lowest priority number wins; on a tie, hiding or removing wins.
* **Live checks in the admin** – conflicting rules, rules that can remove every payment or shipping method, and targets that no longer exist are explained before you save.
* **No dead ends for customers** – if your rules remove every payment or shipping method, customers see your own message, no order is created, and you get an admin notice and a log entry.
* **Order record** – the rules that applied are shown on the order screen.
* Import and export rules as JSON.

= Classic checkout and Checkout Block =

Payment, shipping and tax rules work the same in the classic checkout and the Checkout Block. Field rules are limited by the APIs WooCommerce offers for the Checkout Block: "required" rules are checked when the order is placed, and show/hide rules work in the classic checkout only. The rules screen marks this on each rule.

= Compatibility =

* High-Performance Order Storage (HPOS) and the Cart and Checkout Blocks are declared compatible.
* Works with checkout fields added by themes and other plugins through the standard WooCommerce filters.
* Translation ready.

= Privacy =

The plugin does not connect to any external service, does not track visitors and stores no personal data. It stores your rules and settings as options, and the ids of the rules that applied to an order as order meta.

= Source code =

The admin screen and checkout scripts are built from the human-readable sources included in the plugin (`assets/src`) with `@wordpress/scripts`. The development repository, including tests, is on GitHub: https://github.com/ankhaa0103/conditional-checkout-fields-and-options-for-woocommerce

To build: `npm ci && npm run build`.

== Installation ==

1. Make sure WooCommerce 9.9.3 or newer is active.
2. Install the plugin from *Plugins → Add New* (search for "Conditional Checkout Fields and Options"), or upload the zip file, and activate it.
3. Go to *WooCommerce → Checkout Rules* and add your first rule.

Example – offer bank transfer only to customers who enter a company name:

1. *Add rule*. **Applies to:** Payment method → Direct bank transfer. **Action:** Offer only when…
2. **Condition:** Field value: Billing: Company name → is not empty.
3. *Save rules*.

== Frequently Asked Questions ==

= Can it add new checkout fields or rename existing ones? =

No. The plugin works only with fields that already exist. Use WooCommerce, your theme or a field editor plugin to create fields, then control them with rules here.

= Which rule wins when two rules disagree? =

The rule with the lower priority number. On a tie, hiding or removing wins over showing or offering. The rules screen warns you about such conflicts.

= What happens if my rules remove every payment or shipping method? =

The customer sees your message (WooCommerce → Checkout Rules → Settings), no order is created, and you see a notice in the admin. The event is also logged in WooCommerce → Status → Logs (source "ccfo").

= Does a rule work if the customer is not logged in? =

Yes. Rules read what the customer enters during checkout. For payment, shipping and tax rules that depend on a field, the checkout refreshes as soon as the customer leaves the field, or after the "Live update delay" (default 5 seconds) while they are still typing.

= Does hiding the tax note change the total? =

No. Tax display rules only change what is shown on the checkout page. Cart and order taxes, emails and invoices keep the full tax details.

= Can a customer bypass a rule by changing the page? =

No. The browser only updates the form early. The server applies every rule again when the order is placed, in the classic checkout and in the Checkout Block (Store API).

= Does it work with the Checkout Block? =

Yes for payment, shipping and tax rules. For fields, the Checkout Block allows only "required" checks on order placement; show/hide rules need the classic checkout.

= Will my rules be deleted if I uninstall the plugin? =

Yes, uninstalling removes the rules and settings. The record of applied rules on past orders is kept.

== Screenshots ==

1. The rules list with live checks: conflicting rules are explained on both rules.
2. The rule editor: a payment method offered only when the conditions match, with AND and OR groups.
3. Settings: the messages customers see when every payment or shipping method is removed, and the live update delay.
4. Classic checkout: the VAT number becomes required as soon as a company name is entered.
5. Classic checkout: bank transfer is offered once the customer enters a company name.
6. The order screen lists the rules that applied at checkout.

== Changelog ==

= 1.0.0 =
* First public release.
* Show, hide and require existing checkout fields; offer or remove payment methods, shipping methods and single shipping rates; hide the tax note, VAT exemption and tax class rules.
* 22 condition sources and 23 operators with AND/OR groups, priorities and a documented conflict policy.
* Server-side enforcement in the classic checkout and the Checkout Block (Store API).
* Admin screen with live checks, import/export, customer messages and a live update delay setting.
* HPOS and Cart and Checkout Blocks compatible.

== Upgrade Notice ==

= 1.0.0 =
First public release.
