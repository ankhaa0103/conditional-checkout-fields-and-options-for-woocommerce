=== Conditional Checkout Fields and Options for WooCommerce ===
Contributors: ankhbayar
Tags: woocommerce, checkout, conditional fields, payment methods, shipping methods
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show, hide or require existing checkout fields, and limit payment methods, shipping rates and tax handling with conditions.

== Description ==

Conditional rules for the checkout you already have. The plugin does not add fields or change their labels; it decides when existing fields, payment methods and shipping rates apply.

* Show, hide or require any checkout field registered by WooCommerce, your theme or other plugins.
* Offer or remove payment methods and shipping methods (or single zone rates).
* Hide the "includes tax" note, make orders VAT exempt, or use another existing tax class.
* Conditions: other field values, cart subtotal/total/quantity/weight, products, categories, tags, shipping classes, chosen payment/shipping method, guest or role, billing/shipping country/state/postcode, tax state.
* AND inside a group, OR between groups. Priorities and a clear conflict policy.
* The server checks every rule again when the order is placed.
* Explains conflicts and rules that can remove every payment or shipping method.
* Classic checkout and Checkout Block. See "Checkout Block" below for what each supports.
* HPOS compatible.

= Checkout Block =

Payment, shipping and tax rules work the same in the Checkout Block. Field rules are limited by WooCommerce's APIs: "required" rules are checked when the order is placed; show/hide rules work in the classic checkout only. The rules screen marks this on each rule.

== Installation ==

1. Upload the plugin and activate it. WooCommerce 9.9.3 or newer is required.
2. Go to WooCommerce → Checkout Rules.

== Frequently Asked Questions ==

= Which rule wins when two rules disagree? =

The rule with the lower priority number. On a tie, hiding/removing wins over showing/offering.

= What happens if my rules remove every payment method? =

The customer sees your message (WooCommerce → Checkout Rules → Customer messages), no order is created, and you see a notice in the admin.

= Does hiding the tax note change the total? =

No. Tax display rules only change what is shown on the checkout page. Orders, emails and invoices keep the full tax details.

== Changelog ==

= 0.1.1 =
* Fix: rules for payment, shipping or tax that read a checkout field now update while the customer fills in the checkout, also for guests.
* New "Live update delay" setting (default 5 seconds).
* Fix: product conditions in the rule editor show and save products correctly.

= 0.1.0 =
* First release.
