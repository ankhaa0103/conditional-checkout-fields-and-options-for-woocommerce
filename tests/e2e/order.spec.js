/**
 * Order rules and amount conditions (docs/SPEC.md §13, A13–A16).
 */
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

let fx;

test.beforeAll( () => {
	fx = h.fixtures();
} );

test.afterAll( () => {
	h.setRules( [] );
} );

/** Light ×1 = 300 000: below the minimum. */
const minimumRule = () =>
	h.rule(
		'minimum',
		'order',
		'order',
		'block_checkout',
		[ [ { source: 'cart.subtotal', operator: 'lt', value: 400000 } ] ],
		{
			args: { message: 'Add {remaining} more to order (minimum {min}).' },
		}
	);

/** Cash on delivery only up to 250 000. */
const codLimitRule = () =>
	h.rule(
		'cod_limit',
		'order',
		'order',
		'block_order',
		[
			[
				{ source: 'payment.method', operator: 'equals', value: 'cod' },
				{ source: 'cart.total', operator: 'gt', value: 250000 },
			],
		],
		{ args: { message: 'Cash on delivery only up to {max}.' } }
	);

const cartArea = ( page ) => page.locator( '.wc-proceed-to-checkout' );

/**
 * Change the quantity of the first cart line on the classic cart page. Woodmart
 * hides "Update cart" and updates on change, so the button is triggered directly.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {number}                          qty  Quantity.
 */
async function setClassicCartQty( page, qty ) {
	const updated = page.waitForResponse(
		( r ) => r.request().method() === 'POST' && /cart/.test( r.url() ),
		{ timeout: 30000 }
	);
	await page.locator( 'input.qty' ).first().fill( String( qty ) );
	await page.evaluate( () =>
		window
			.jQuery( 'button[name="update_cart"]' )
			.prop( 'disabled', false )
			.trigger( 'click' )
	);
	await updated;
	await h.waitForCheckoutIdle( page );
}

/**
 * Mini cart HTML as WooCommerce refreshes it (cart fragments).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<string>} HTML.
 */
async function miniCart( page ) {
	const response = await page.request.post(
		'/?wc-ajax=get_refreshed_fragments'
	);
	const data = await response.json();
	return Object.values( data.fragments || {} ).join( '\n' );
}

test(
	'A13: classic cart and mini cart hold back checkout below the minimum',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [ minimumRule() ] );
		await h.fillCart( page, fx, { light: 1 } );
		await page.goto( '/cart/', { waitUntil: 'domcontentloaded' } );

		await expect(
			cartArea( page ).locator( '.ccfo-checkout-disabled' )
		).toBeDisabled();
		await expect(
			cartArea( page ).locator( '.ccfo-order-blocked' )
		).toHaveText( /Add .*100,000 more to order \(minimum .*400,000\)\./ );
		await expect(
			cartArea( page ).locator( 'a.checkout-button' )
		).toHaveCount( 0 );
		expect( await miniCart( page ) ).toMatch(
			/ccfo-checkout-disabled[\s\S]*Add .*100,000 more/
		);

		// 600 000: the minimum is reached; the cart refresh brings the button back.
		await setClassicCartQty( page, 2 );
		await expect(
			cartArea( page ).locator( 'a.checkout-button' )
		).toBeVisible();
		await expect(
			cartArea( page ).locator( '.ccfo-order-blocked' )
		).toHaveCount( 0 );
		const mini = await miniCart( page );
		expect( mini ).not.toContain( 'ccfo-checkout-disabled' );
		expect( mini ).toContain( 'checkout wc-forward' );
	}
);

test(
	'A14/A15: classic checkout holds back Place order for COD over the limit',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [ codLimitRule() ] );
		await h.fillCart( page, fx, { light: 2 } );
		await h.openClassicCheckout( page );
		await h.fillClassicBilling( page );

		await page.locator( '#payment_method_cod' ).check();
		await h.waitForCheckoutIdle( page );
		await expect( page.locator( '#place_order' ) ).toBeDisabled();
		await expect(
			page.locator( '#payment .ccfo-order-blocked' )
		).toHaveText( /Cash on delivery only up to .*250,000\./ );

		// A forced submit (as if the button were enabled again) is refused by the server.
		await page.evaluate( () =>
			window.jQuery( 'form.checkout' ).trigger( 'submit' )
		);
		await expect(
			page.locator( '.woocommerce-NoticeGroup-checkout' )
		).toContainText( 'Cash on delivery only up to' );
		expect( page.url() ).not.toMatch( /order-received/ );

		// Bank transfer is fine.
		await page.locator( '#payment_method_bacs' ).check();
		await h.waitForCheckoutIdle( page );
		await expect( page.locator( '#place_order' ) ).toBeEnabled();
		await expect(
			page.locator( '#payment .ccfo-order-blocked' )
		).toHaveCount( 0 );
		// The refused attempt's notice is still on the page, so wait for the order page.
		await page.locator( '#place_order' ).click();
		await page.waitForURL( /order-received/, { timeout: 45000 } );
		const orderId = h.orderIdFromUrl( page.url() );
		expect( orderId ).toBeGreaterThan( 0 );
		expect(
			h.wpEval(
				`echo wc_get_order( ${ orderId } )->get_payment_method();`
			)
		).toBe( 'bacs' );
	}
);

test(
	'Checkout Block holds back Place order for COD over the limit',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [ codLimitRule() ] );
		await h.fillCart( page, fx, { light: 2 } );
		await h.openBlockCheckout( page );
		await h.fillBlockAddress( page );

		await page.getByLabel( 'Cash on delivery' ).check();
		const notice = page
			.locator( '.wc-block-components-notice-banner' )
			.filter( { hasText: 'Cash on delivery only up to' } );
		await expect( notice ).toBeVisible( { timeout: 30000 } );

		// Clicking does not place the order (draft updates are PUT, sent as POST with
		// an X-HTTP-Method-Override header, and do not count).
		const posts = [];
		page.on( 'request', ( r ) => {
			if (
				r.method() === 'POST' &&
				/wc\/store\/v1\/checkout/.test( r.url() ) &&
				! r.headers()[ 'x-http-method-override' ]
			) {
				posts.push( r.url() );
			}
		} );
		await page
			.locator( '.wc-block-components-checkout-place-order-button' )
			.click( { force: true } );
		await page.waitForTimeout( 2000 );
		expect( posts ).toHaveLength( 0 );

		await page.getByLabel( 'Direct bank transfer' ).check();
		await expect( notice ).toHaveCount( 0, { timeout: 30000 } );
		await h.placeBlockOrder( page );
		await expect( page ).toHaveURL( /order-received/ );
	}
);

test(
	'Cart Block holds back Proceed to checkout below the minimum',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [ minimumRule() ] );
		await h.fillCart( page, fx, { light: 1 } );
		await page.goto( '/ccfo-block-cart/', {
			waitUntil: 'domcontentloaded',
		} );
		const button = page.locator( '.wc-block-cart__submit-button' );
		await expect( button ).toBeVisible( { timeout: 30000 } );

		const notice = page
			.locator( '.wc-block-components-notice-banner' )
			.filter( { hasText: /Add .*100,000 more to order/ } );
		await expect( notice ).toBeVisible( { timeout: 30000 } );
		await expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
		await button.click( { force: true } );
		await page.waitForTimeout( 1000 );
		await expect( page ).toHaveURL( /ccfo-block-cart/ );

		// 600 000: released.
		await page
			.locator( '.wc-block-components-quantity-selector__button--plus' )
			.first()
			.click();
		await expect( notice ).toHaveCount( 0, { timeout: 30000 } );
		await expect( button ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await button.click();
		await expect( page ).toHaveURL( /checkout/ );
	}
);

test( 'A16: a payment method offered by a category subtotal', async ( {
	page,
} ) => {
	const heavy = fx.terms[ 0 ].id;
	h.setRules( [
		h.rule( 'bacs_heavy', 'payment', 'bacs', 'enable', [
			[
				{
					source: `cart.category_subtotal:${ heavy }`,
					operator: 'gte',
					value: 200000,
				},
			],
		] ),
	] );

	// 100 000: only cash on delivery (WooCommerce hides the radio of a single method).
	await h.fillCart( page, fx, { heavy: 1 } );
	await h.openClassicCheckout( page );
	await expect( page.locator( '#payment_method_cod' ) ).toHaveCount( 1 );
	await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 0 );

	// 200 000.
	await h.fillCart( page, fx, { heavy: 2 } );
	await h.openClassicCheckout( page );
	await expect( page.locator( '#payment_method_bacs' ) ).toBeVisible();
	await expect( page.locator( '#payment_method_cod' ) ).toBeVisible();
} );

test( 'Checkout Block: choosing a payment right after typing keeps the address', async ( {
	page,
} ) => {
	// A tax rule that reads the payment method makes the block send the choice to the
	// server; the cart response must not wipe address input WooCommerce has not sent yet.
	const restore = h.withTaxes( { woocommerce_tax_display_cart: 'excl' } );
	try {
		h.setRules( [
			h.rule( 'bacs-exempt', 'tax_calc', 'cart', 'set_vat_exempt', [
				[
					{
						source: 'payment.method',
						operator: 'equals',
						value: 'bacs',
					},
				],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openBlockCheckout( page );
		const total = page.locator(
			'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
		);
		// The first method (bank transfer) is what the server assumes: exempt.
		await expect
			.poll( () => h.amount( total ), { timeout: 30000 } )
			.toBe( 300000 );

		const addressSaved = page.waitForResponse(
			( r ) =>
				/update-customer/.test( r.request().postData() || '' ) &&
				/Peace Avenue 2/.test( r.request().postData() || '' ),
			{ timeout: 30000 }
		);
		await h.fillBlockAddress( page, { waitForSave: false } );
		// Right after typing: the choice is sent only after WooCommerce has sent the address.
		await page.getByLabel( 'Cash on delivery' ).check();
		await expect
			.poll( () => h.amount( total ), { timeout: 30000 } )
			.toBe( 330000 );
		await expect( page.locator( '#shipping-first_name' ) ).toHaveValue(
			'Block'
		);
		await expect( page.locator( '#shipping-address_1' ) ).toHaveValue(
			'Peace Avenue 2'
		);
		await expect( page.locator( '#email' ) ).toHaveValue(
			'block-buyer@example.test'
		);

		await page.getByLabel( 'Direct bank transfer' ).check();
		await expect
			.poll( () => h.amount( total ), { timeout: 30000 } )
			.toBe( 300000 );
		// On this slow test site the Checkout Block can stay "processing" when the
		// order is placed before WooCommerce has saved the address (also without rules).
		await addressSaved;
		await h.placeBlockOrder( page );
		await expect( page ).toHaveURL( /order-received/ );
	} finally {
		restore();
	}
} );

test(
	'admin: an order rule with a message and a category subtotal condition',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [] );
		await h.useAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=ccfo-checkout-rules', {
			waitUntil: 'domcontentloaded',
		} );
		await page.getByRole( 'button', { name: 'Add rule' } ).click();
		await page.getByLabel( 'Rule name' ).fill( 'Minimum for heavy goods' );
		await page.getByLabel( 'Applies to' ).selectOption( 'order' );
		await page.getByLabel( 'Action' ).selectOption( 'block_checkout' );
		await page
			.getByLabel( 'Message shown to customers' )
			.fill( 'Add {remaining} of heavy goods.' );

		await page.getByRole( 'button', { name: '+ Add condition' } ).click();
		const source = page.getByRole( 'combobox', { name: 'Condition' } );
		await source.fill( 'Subtotal of category "CCFO Heavy"' );
		await page
			.getByRole( 'option', {
				name: /Subtotal of category "CCFO Heavy"/,
			} )
			.click();
		await page.getByLabel( 'Operator' ).selectOption( 'lt' );
		await page.getByLabel( 'Value', { exact: true } ).fill( '400000' );

		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Rules saved.' )
		).toBeVisible();

		const stored = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		);
		expect( stored.rules[ 0 ] ).toMatchObject( {
			title: 'Minimum for heavy goods',
			target: { type: 'order', key: 'order' },
			action: 'block_checkout',
			args: { message: 'Add {remaining} of heavy goods.' },
			groups: [
				[
					{
						source: `cart.category_subtotal:${ fx.terms[ 0 ].id }`,
						operator: 'lt',
						value: 400000,
					},
				],
			],
		} );
		await expect( page.locator( '.ccfo-rule' ).first() ).toContainText(
			'Order'
		);

		// Default message in the Settings tab.
		await page.getByRole( 'tab', { name: 'Settings' } ).click();
		await page
			.getByLabel( 'Default order rule message' )
			.fill( 'Not yet: {remaining} to go.' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Settings saved.' )
		).toBeVisible();
		expect(
			JSON.parse(
				h.wp( 'option', 'get', 'ccfo_settings', '--format=json' )
			).order_blocked_message
		).toBe( 'Not yet: {remaining} to go.' );
		h.deleteOption( 'ccfo_settings' );
	}
);
