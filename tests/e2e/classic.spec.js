/**
 * Classic checkout scenarios (docs/SPEC.md §11).
 */
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

let fx;

test.beforeAll( () => {
	fx = h.fixtures();
	h.wp(
		'option',
		'update',
		'woocommerce_checkout_page_id',
		String( fx.pages.classic_checkout )
	);
} );

test.afterAll( () => {
	h.setRules( [] );
} );

test(
	'A1: show rule toggles a field live with the billing country',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'a1', 'field', 'billing_company', 'show', [
				[
					{
						source: 'billing.country',
						operator: 'equals',
						value: 'DE',
					},
				],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );

		const row = page.locator( '#billing_company_field' );
		await expect( row ).toBeHidden();

		await h.selectCountry( page, 'billing', 'DE' );
		await expect( row ).toBeVisible();

		await h.selectCountry( page, 'billing', 'MN' );
		await expect( row ).toBeHidden();
		await expect( page.locator( '#billing_company' ) ).toBeDisabled();
	}
);

test(
	'A2/A3: conditional required on a third-party field, enforced on submit',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'a2', 'field', 'billing_vat', 'require', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		await h.fillClassicBilling( page );

		const vatRow = page.locator( '#billing_vat_field' );
		await expect( vatRow ).not.toHaveClass( /validate-required/ );

		await page.fill( '#billing_company', 'Acme LLC' );
		await expect( vatRow ).toHaveClass( /validate-required/ );
		await expect( vatRow.locator( 'label .required' ) ).toHaveCount( 1 );

		await page.locator( '#payment_method_cod' ).check();
		await h.placeClassicOrder( page );
		await expect( page.locator( '.woocommerce-error' ) ).toContainText(
			'VAT number'
		);

		await page.fill( '#billing_vat', 'MN1234567' );
		await h.placeClassicOrder( page );
		await expect( page ).toHaveURL( /order-received/ );

		const meta = JSON.parse(
			h.orderMeta( h.orderIdFromUrl( page.url() ), '_ccfo_applied_rules' )
		);
		expect( meta[ 'field:billing_vat' ] ).toEqual( [ 'a2' ] );
	}
);

test(
	'A3: a hidden required field does not block the order and is not stored',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'hide-city', 'field', 'billing_address_2', 'hide', [
				[ { source: 'customer.is_guest', operator: 'is_true' } ],
			] ),
			h.rule( 'req-a2', 'field', 'billing_address_2', 'require', [], {
				priority: 20,
			} ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		await h.fillClassicBilling( page );
		await expect( page.locator( '#billing_address_2_field' ) ).toBeHidden();

		await page.locator( '#payment_method_cod' ).check();
		await h.placeClassicOrder( page );
		await expect( page ).toHaveURL( /order-received/ );

		const id = h.orderIdFromUrl( page.url() );
		expect(
			h.wpEval( `echo wc_get_order( ${ id } )->get_billing_address_2();` )
		).toBe( '' );
	}
);

test(
	'A4: COD is not offered above the order total limit',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'a4', 'payment', 'cod', 'disable', [
				[ { source: 'cart.total', operator: 'gt', value: 500000 } ],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		await expect( page.locator( '#payment_method_cod' ) ).toHaveCount( 1 );

		await h.fillCart( page, fx, { light: 2 } );
		await h.openClassicCheckout( page );
		await expect( page.locator( '#payment_method_cod' ) ).toHaveCount( 0 );
		await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 1 );
	}
);

test(
	'A5: free shipping disappears when a heavy product is in the cart',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'a5', 'shipping', 'free_shipping', 'disable', [
				[
					{
						source: 'cart.categories',
						operator: 'any_in',
						value: [ String( fx.terms[ 0 ].id ) ],
					},
				],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		await expect(
			page.locator( `input[value="${ fx.rates.free }"]` )
		).toHaveCount( 1 );

		await h.fillCart( page, fx, { light: 1, heavy: 1 } );
		await h.openClassicCheckout( page );
		await expect(
			page.locator( `input[value="${ fx.rates.free }"]` )
		).toHaveCount( 0 );
		await expect(
			page.locator( `input[value="${ fx.rates.flat }"]` )
		).toHaveCount( 1 );
	}
);

test( 'A7: customer sees the configured message when every payment method is removed', async ( {
	page,
} ) => {
	h.setRules( [
		h.rule( 'p1', 'payment', 'cod', 'disable' ),
		h.rule( 'p2', 'payment', 'bacs', 'disable' ),
	] );
	await h.fillCart( page, fx, { light: 1 } );
	await h.openClassicCheckout( page );
	await expect( page.locator( '#payment' ) ).toContainText(
		'No payment method is available'
	);

	await h.fillClassicBilling( page );
	await h.placeClassicOrder( page );
	await expect( page.locator( '.woocommerce-error' ) ).toContainText(
		'No payment method is available'
	);
	h.deleteOption( 'ccfo_blocked_log' );
} );

test(
	'A8: order notes appear only for local pickup',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'a8', 'field', 'order_comments', 'show', [
				[
					{
						source: 'shipping.method',
						operator: 'any_in',
						value: [ 'local_pickup' ],
					},
				],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );

		await page.locator( `input[value="${ fx.rates.flat }"]` ).check();
		await h.waitForCheckoutIdle( page );
		await expect( page.locator( '#order_comments_field' ) ).toBeHidden();

		await page.locator( `input[value="${ fx.rates.pickup }"]` ).check();
		await h.waitForCheckoutIdle( page );
		await expect( page.locator( '#order_comments_field' ) ).toBeVisible();
	}
);

test( 'A9: BACS is offered only for Mongolian billing addresses', async ( {
	page,
} ) => {
	h.setRules( [
		h.rule( 'a9', 'payment', 'bacs', 'enable', [
			[ { source: 'billing.country', operator: 'in', value: [ 'MN' ] } ],
		] ),
	] );
	await h.fillCart( page, fx, { light: 1 } );
	await h.openClassicCheckout( page );
	await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 1 );

	await h.selectCountry( page, 'billing', 'DE' );
	await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 0 );
} );
