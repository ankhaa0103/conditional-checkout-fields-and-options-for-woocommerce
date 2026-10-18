/**
 * Option lists for target keys and condition values, built from the catalog.
 */
import { __, sprintf } from '@wordpress/i18n';

const FIELDSET_LABELS = {
	billing: __(
		'Billing',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	shipping: __(
		'Shipping',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	account: __(
		'Account',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
	order: __(
		'Order',
		'conditional-checkout-fields-and-options-for-woocommerce'
	),
};

export function fieldOptions( catalog ) {
	return catalog.fields.map( ( f ) => ( {
		value: f.key,
		label: sprintf(
			/* translators: 1: fieldset, 2: field label, 3: field key */
			__(
				'%1$s: %2$s (%3$s)',
				'conditional-checkout-fields-and-options-for-woocommerce'
			),
			FIELDSET_LABELS[ f.fieldset ] ||
				f.fieldset ||
				__(
					'Other',
					'conditional-checkout-fields-and-options-for-woocommerce'
				),
			f.label || f.key,
			f.key
		),
	} ) );
}

export function gatewayOptions( catalog ) {
	return catalog.gateways.map( ( g ) => ( {
		value: g.id,
		label: g.enabled
			? g.title
			: sprintf(
					/* translators: %s: gateway title */ __(
						'%s (disabled)',
						'conditional-checkout-fields-and-options-for-woocommerce'
					),
					g.title
			  ),
	} ) );
}

export function shippingOptions( catalog ) {
	return catalog.shipping.map( ( m ) => ( {
		value: m.id,
		label: m.zone
			? sprintf(
					/* translators: 1: zone, 2: method title */ __(
						'%1$s › %2$s',
						'conditional-checkout-fields-and-options-for-woocommerce'
					),
					m.zone,
					m.title
			  )
			: sprintf(
					/* translators: %s: method title */ __(
						'%s (all zones)',
						'conditional-checkout-fields-and-options-for-woocommerce'
					),
					m.title
			  ),
	} ) );
}

export function targetKeyOptions( type, catalog ) {
	switch ( type ) {
		case 'field':
			return fieldOptions( catalog );
		case 'payment':
			return gatewayOptions( catalog );
		case 'shipping':
			return shippingOptions( catalog );
	}
	return [];
}

export function objectOptions( object ) {
	return Object.entries( object || {} ).map( ( [ value, label ] ) => ( {
		value,
		label: String( label ).replace( /&[a-z]+;/g, '' ),
	} ) );
}

/**
 * Value suggestions for a condition source, or null for free input.
 *
 * @param {string} source  Source id.
 * @param {Object} catalog Catalog.
 * @return {Array<{value: string, label: string}>|null} Options.
 */
export function valueOptions( source, catalog ) {
	switch ( source ) {
		case 'billing.country':
		case 'shipping.country':
			return objectOptions( catalog.countries );
		case 'billing.state':
		case 'shipping.state': {
			const out = [];
			Object.entries( catalog.states || {} ).forEach(
				( [ country, states ] ) => {
					Object.entries( states || {} ).forEach(
						( [ code, name ] ) => {
							out.push( {
								value: code,
								label: `${
									catalog.countries[ country ] || country
								} › ${ name } (${ code })`,
							} );
						}
					);
				}
			);
			return out;
		}
		case 'payment.method':
			return gatewayOptions( catalog );
		case 'shipping.method':
			return shippingOptions( catalog );
		case 'customer.role':
			return catalog.roles.map( ( r ) => ( {
				value: r.id,
				label: r.label,
			} ) );
		case 'cart.categories':
			return catalog.categories.map( ( t ) => ( {
				value: t.id,
				label: t.label,
			} ) );
		case 'cart.tags':
			return catalog.tags.map( ( t ) => ( {
				value: t.id,
				label: t.label,
			} ) );
		case 'cart.shipping_classes':
			return catalog.shippingClasses.map( ( t ) => ( {
				value: t.id,
				label: t.label,
			} ) );
	}
	return null;
}
