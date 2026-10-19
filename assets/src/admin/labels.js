/**
 * Human labels for the rule vocabulary (src/Rules/Definitions.php).
 */
import { __ } from '@wordpress/i18n';

export const TARGET_LABELS = {
	field: __(
		'Checkout field',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	payment: __(
		'Payment method',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	shipping: __(
		'Shipping method',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	tax_display: __(
		'Tax display',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	tax_calc: __(
		'Tax calculation',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	order: __(
		'Order',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
};

export const ACTION_LABELS = {
	show: __(
		'Show only when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	hide: __(
		'Hide when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	require: __(
		'Make required when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	enable: __(
		'Offer only when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	disable: __(
		'Remove when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	hide_tax_note: __(
		'Hide the "includes tax" note when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	set_vat_exempt: __(
		'Make the order VAT exempt when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	set_tax_class: __(
		'Use another tax class for cart items when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	block_checkout: __(
		'Block checkout (cart button and Place order) when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	block_order: __(
		'Block placing the order (checkout only) when…',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
};

export const SOURCE_LABELS = {
	'cart.subtotal': __(
		'Cart subtotal (before discounts, excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.subtotal_incl_tax': __(
		'Cart subtotal (before discounts, incl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.contents_total': __(
		'Cart total after discounts (excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.discount_total': __(
		'Discount amount (excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.shipping_total': __(
		'Shipping cost (excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.tax_total': __(
		'Tax amount',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.total': __(
		'Order total',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.max_item_price': __(
		'Most expensive product price (excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.min_item_price': __(
		'Cheapest product price (excl. tax)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.coupons': __(
		'Coupon codes applied',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.item_count': __(
		'Number of cart lines',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.qty': __(
		'Number of items (quantity)',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.weight': __(
		'Cart weight',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.products': __(
		'Products in cart',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.categories': __(
		'Product categories in cart',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.tags': __(
		'Product tags in cart',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'cart.shipping_classes': __(
		'Shipping classes in cart',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'payment.method': __(
		'Chosen payment method',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'shipping.method': __(
		'Chosen shipping method',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'customer.is_guest': __(
		'Customer is a guest',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'customer.role': __(
		'Customer role',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'billing.country': __(
		'Billing country',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'billing.state': __(
		'Billing state',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'billing.postcode': __(
		'Billing postcode',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'shipping.country': __(
		'Shipping country',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'shipping.state': __(
		'Shipping state',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'shipping.postcode': __(
		'Shipping postcode',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'tax.vat_exempt': __(
		'Order is VAT exempt',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	'tax.has_tax': __(
		'Order has tax',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
};

export const OPERATOR_LABELS = {
	equals: __(
		'is',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	not_equals: __(
		'is not',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	contains: __(
		'contains',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	not_contains: __(
		'does not contain',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	in: __(
		'is one of',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	not_in: __(
		'is none of',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	empty: __(
		'is empty',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	not_empty: __(
		'is not empty',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	checked: __(
		'is checked',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	unchecked: __(
		'is not checked',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	is_true: __(
		'yes',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	is_false: __(
		'no',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	eq: '=',
	neq: '≠',
	gt: '>',
	gte: '≥',
	lt: '<',
	lte: '≤',
	between: __(
		'between',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	any_in: __(
		'contains any of',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	all_in: __(
		'contains all of',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	none_in: __(
		'contains none of',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	only_in: __(
		'contains only',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
};

export const SOURCE_GROUPS = [
	{
		label: __(
			'Cart',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
		prefix: 'cart.',
	},
	{
		label: __(
			'Customer',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
		prefix: 'customer.',
	},
	{
		label: __(
			'Address',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
		prefix: [
			'billing.',
			'shipping.country',
			'shipping.state',
			'shipping.postcode',
		],
	},
	{
		label: __(
			'Checkout choices',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
		prefix: [ 'payment.', 'shipping.method' ],
	},
	{
		label: __(
			'Tax',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
		prefix: 'tax.',
	},
];
