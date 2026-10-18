/**
 * Checkout Block scenarios (docs/SPEC.md §9, §11).
 */
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

let fx;

// The block page is not set as the WooCommerce checkout page: on this site the
// Woodmart theme's checkout layout replaces the Checkout Block on that page.
test.beforeAll( () => {
	fx = h.fixtures();
} );

test.afterAll( () => {
	h.setRules( [] );
} );

async function openBlockCheckout( page ) {
	await page.goto( '/ccfo-block-checkout/', {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForSelector( '.wc-block-checkout__form', {
		timeout: 30000,
	} );
	await page.waitForFunction(
		() =>
			! document.querySelector(
				'.wc-block-components-loading-mask, .is-loading'
			),
		null,
		{ timeout: 30000 }
	);
}

const paymentOptions = ( page ) =>
	page.locator( '.wc-block-checkout__payment-method' );
const shippingOptions = ( page ) =>
	page.locator( '.wc-block-checkout__shipping-option' );

async function fillBlockAddress( page ) {
	await page.fill( '#email', 'block-buyer@example.test' );
	const values = {
		first_name: 'Block',
		last_name: 'Buyer',
		address_1: 'Peace Avenue 2',
		city: 'Ulaanbaatar',
		postcode: '14200',
		phone: '99112233',
		state: 'UB',
	};
	for ( const [ key, value ] of Object.entries( values ) ) {
		for ( const prefix of [ 'shipping', 'billing' ] ) {
			const input = page.locator( `#${ prefix }-${ key }` );
			if ( ( await input.count() ) && ( await input.isVisible() ) ) {
				await input.fill( value );
			}
		}
	}
}

async function placeBlockOrder( page ) {
	await page
		.locator( '.wc-block-components-checkout-place-order-button' )
		.click();
	await page.waitForFunction(
		() =>
			document.querySelector(
				'.wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error'
			) || /order-received/.test( window.location.href ),
		null,
		{ timeout: 45000 }
	);
}

test( 'B1: payment rules apply to the Checkout Block', async ( { page } ) => {
	h.setRules( [
		h.rule( 'b1', 'payment', 'cod', 'disable', [
			[ { source: 'cart.qty', operator: 'gte', value: 2 } ],
		] ),
	] );

	await h.fillCart( page, fx, { light: 1 } );
	await openBlockCheckout( page );
	await expect( paymentOptions( page ) ).toContainText( 'Cash on delivery' );

	await h.fillCart( page, fx, { light: 2 } );
	await openBlockCheckout( page );
	await expect( paymentOptions( page ) ).not.toContainText(
		'Cash on delivery'
	);
	await expect( paymentOptions( page ) ).toContainText(
		'Direct bank transfer'
	);
} );

test( 'B2: shipping rules apply to the Checkout Block', async ( { page } ) => {
	h.setRules( [
		h.rule( 'b2', 'shipping', 'free_shipping', 'disable', [
			[
				{
					source: 'cart.categories',
					operator: 'any_in',
					value: [ String( fx.terms[ 0 ].id ) ],
				},
			],
		] ),
	] );

	await h.fillCart( page, fx, { light: 1, heavy: 1 } );
	await openBlockCheckout( page );
	await fillBlockAddress( page );
	await expect( shippingOptions( page ) ).toContainText( 'CCFO Flat' );
	await expect( shippingOptions( page ) ).not.toContainText(
		'Free shipping'
	);
} );

test( 'B3: require rules are enforced when the block places the order', async ( {
	page,
} ) => {
	h.setRules( [
		h.rule( 'b3', 'field', 'shipping_address_2', 'require', [
			[ { source: 'shipping.country', operator: 'equals', value: 'MN' } ],
		] ),
	] );

	await h.fillCart( page, fx, { light: 1 } );
	await openBlockCheckout( page );
	await fillBlockAddress( page );
	await page.getByLabel( 'Cash on delivery' ).check();

	await placeBlockOrder( page );
	await expect(
		page.locator( '.wc-block-components-notice-banner.is-error' ).first()
	).toContainText( 'is a required field' );

	const toggle = page.locator(
		'.wc-block-components-address-form__address_2-toggle'
	);
	if ( await toggle.count() ) {
		await toggle.first().click();
	}
	await page.locator( '#shipping-address_2' ).fill( 'Apt 5' );
	await placeBlockOrder( page );
	await expect( page ).toHaveURL( /order-received/ );

	const meta = JSON.parse(
		h.orderMeta( h.orderIdFromUrl( page.url() ), '_ccfo_applied_rules' )
	);
	expect( meta ).toBeTruthy();
} );
