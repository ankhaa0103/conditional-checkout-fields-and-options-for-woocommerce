/**
 * docs/MANUAL-TEST.md as automated checks, plus the "field value → payment method"
 * regression (a guest typing a company name must get BACS without saving a profile).
 */
const fs = require( 'fs' );
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

test.afterEach( () => {
	h.setRules( [] );
	h.deleteOption( 'ccfo_settings' );
	h.deleteOption( 'ccfo_blocked_log' );
} );

const companyBacsRule = () =>
	h.rule( 'bacs-company', 'payment', 'bacs', 'enable', [
		[ { source: 'field:billing_company', operator: 'not_empty' } ],
	] );

async function openRules( page ) {
	await page.goto( '/wp-admin/admin.php?page=ccfo-checkout-rules', {
		waitUntil: 'domcontentloaded',
	} );
	await expect(
		page.getByRole( 'button', { name: 'Add rule' } )
	).toBeVisible( { timeout: 30000 } );
}

const notice = ( page, text ) =>
	page.locator( '.components-notice' ).getByText( text );

// ---------------------------------------------------------------------------
// Regression: field value read by a payment rule.
// ---------------------------------------------------------------------------

test.describe( 'payment rule that reads a checkout field', () => {
	test( 'guest gets BACS right after leaving the company field', async ( {
		page,
	} ) => {
		h.setRules( [ companyBacsRule() ] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 0 );

		await page.fill( '#billing_company', 'Acme LLC' );
		await page.locator( '#billing_first_name' ).focus();
		await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 1 );

		await page.fill( '#billing_company', '' );
		await page.locator( '#billing_first_name' ).focus();
		await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 0 );
	} );

	test( 'while typing, the checkout refreshes once after the delay', async ( {
		page,
	} ) => {
		h.setRules( [ companyBacsRule() ] );
		h.setSettings( { live_update_delay: '2' } );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );

		let refreshes = 0;
		page.on( 'request', ( r ) => {
			if ( r.url().includes( 'update_order_review' ) ) {
				refreshes++;
			}
		} );

		await page
			.locator( '#billing_company' )
			.pressSequentially( 'Acme LLC', {
				delay: 50,
			} );
		// Still typing focus, no blur: the refresh comes from the delay.
		await expect( page.locator( '#payment_method_bacs' ) ).toHaveCount( 1, {
			timeout: 20000,
		} );
		expect( refreshes ).toBe( 1 );

		// More typing does not change "not empty", so no further refresh.
		await page.locator( '#billing_company' ).pressSequentially( ' Group' );
		await page.waitForTimeout( 3000 );
		expect( refreshes ).toBe( 1 );
	} );

	test( 'the script loads although there are no field rules', async ( {
		page,
	} ) => {
		h.setRules( [ companyBacsRule() ] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );
		const data = await page.evaluate( () => window.ccfoCheckout );
		expect( data.triggers.conditions ).toEqual( [
			{ source: 'field:billing_company', operator: 'not_empty' },
		] );
		// wp_localize_script() sends numbers as strings; the script converts it.
		expect( Number( data.updateDelay ) ).toBe( 5000 );
	} );
} );

// ---------------------------------------------------------------------------
// Admin.
// ---------------------------------------------------------------------------

test.describe( 'admin', () => {
	test( 'the rules screen and API need manage_woocommerce', async ( {
		page,
		request,
	} ) => {
		const api = await request.get( '/wp-json/ccfo/v1/rules' );
		expect( api.status() ).toBe( 401 );

		await h.login( page, 'ccfo_customer' );
		await page.goto( '/wp-admin/admin.php?page=ccfo-checkout-rules' );
		await expect( page.locator( '#ccfo-admin' ) ).toHaveCount( 0 );
	} );

	test( 'add, duplicate, delete and disable rules; unsaved changes warn', async ( {
		page,
	} ) => {
		await h.useAdmin( page );
		await openRules( page );

		await page.getByRole( 'button', { name: 'Add rule' } ).click();
		await page.getByLabel( 'Rule name' ).fill( 'First' );
		await page.getByLabel( 'Applies to' ).selectOption( 'payment' );
		await page
			.getByRole( 'combobox', { name: 'Payment method' } )
			.fill( 'Cash' );
		await page.getByRole( 'option', { name: /Cash on delivery/ } ).click();

		await page.getByRole( 'button', { name: 'Duplicate' } ).click();
		await expect( page.locator( '.ccfo-rule' ) ).toHaveCount( 2 );

		page.once( 'dialog', ( d ) => d.accept() );
		await page.getByRole( 'button', { name: 'Delete' } ).nth( 1 ).click();
		await expect( page.locator( '.ccfo-rule' ) ).toHaveCount( 1 );

		await page
			.locator( '.ccfo-rule' )
			.getByLabel( 'Enabled' )
			.first()
			.click();
		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect( notice( page, 'Rules saved.' ) ).toBeVisible();

		const stored = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		).rules;
		expect( stored ).toHaveLength( 1 );
		expect( stored[ 0 ] ).toMatchObject( {
			title: 'First',
			enabled: false,
		} );

		// Unsaved change → the browser asks before leaving.
		await page.getByRole( 'button', { name: 'Edit' } ).click();
		await page.getByLabel( 'Rule name' ).fill( 'Changed' );
		const dialog = page.waitForEvent( 'dialog' );
		await page.close( { runBeforeUnload: true } );
		const seen = await dialog;
		expect( seen.type() ).toBe( 'beforeunload' );
		await seen.dismiss();
	} );

	test( 'saving over a newer version is refused and can be reloaded', async ( {
		browser,
	} ) => {
		const a = await browser.newPage();
		const b = await browser.newPage();
		await h.useAdmin( a );
		await h.useAdmin( b );
		await openRules( a );
		await openRules( b );

		for ( const [ page, name ] of [
			[ a, 'From A' ],
			[ b, 'From B' ],
		] ) {
			await page.getByRole( 'button', { name: 'Add rule' } ).click();
			await page.getByLabel( 'Rule name' ).fill( name );
			await page.getByLabel( 'Applies to' ).selectOption( 'payment' );
			await page
				.getByRole( 'combobox', { name: 'Payment method' } )
				.fill( 'Cash' );
			await page
				.getByRole( 'option', { name: /Cash on delivery/ } )
				.click();
		}

		await a.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect( notice( a, 'Rules saved.' ) ).toBeVisible();

		await b.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect( notice( b, /changed in another window/ ) ).toBeVisible();
		await b.getByRole( 'button', { name: 'Reload rules' } ).click();
		await expect( b.locator( '.ccfo-rule' ) ).toHaveCount( 1 );
		await expect( b.locator( '.ccfo-rule' ) ).toContainText( 'From A' );

		await a.close();
		await b.close();
	} );

	test( 'export and import round-trip; a broken file is reported', async ( {
		page,
	}, testInfo ) => {
		h.setRules( [ companyBacsRule() ] );
		await h.useAdmin( page );
		await openRules( page );

		const download = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Export' } ).click();
		const file = testInfo.outputPath( 'rules.json' );
		await ( await download ).saveAs( file );
		const exported = JSON.parse( fs.readFileSync( file, 'utf8' ) );
		expect( exported.rules[ 0 ].id ).toBe( 'bacs-company' );

		h.setRules( [] );
		await openRules( page );
		await expect( page.locator( '.ccfo-rule' ) ).toHaveCount( 0 );

		await page.locator( 'input[type="file"]' ).setInputFiles( file );
		await expect( notice( page, /Rules imported/ ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect( notice( page, 'Rules saved.' ) ).toBeVisible();
		const stored = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		).rules;
		expect( stored[ 0 ] ).toMatchObject( {
			id: 'bacs-company',
			target: { type: 'payment', key: 'bacs' },
		} );

		await page.locator( 'input[type="file"]' ).setInputFiles( {
			name: 'broken.json',
			mimeType: 'application/json',
			buffer: Buffer.from( '{ not json' ),
		} );
		await expect(
			page.locator( '.components-notice.is-error' )
		).toBeVisible();
	} );

	test( 'product conditions search by name and show names after saving', async ( {
		page,
	} ) => {
		h.setRules( [
			h.rule( 'p', 'payment', 'cod', 'disable', [
				[
					{
						source: 'cart.products',
						operator: 'any_in',
						value: [ String( fx.products.heavy ) ],
					},
				],
			] ),
		] );
		await h.useAdmin( page );
		await openRules( page );
		await page.getByRole( 'button', { name: 'Edit' } ).click();

		const tokens = page.locator( '.components-form-token-field' );
		await expect( tokens ).toContainText( 'CCFO Test Heavy' );

		await tokens.locator( 'input' ).fill( 'CCFO Test L' );
		await page.getByRole( 'option', { name: /CCFO Test Light/ } ).click();
		await expect( tokens ).toContainText( 'CCFO Test Light' );
		// Close the suggestion list first; it shifts the layout while closing.
		await page.keyboard.press( 'Escape' );
		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect( notice( page, 'Rules saved.' ) ).toBeVisible();

		const value = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		).rules[ 0 ].groups[ 0 ][ 0 ].value;
		expect( value.sort() ).toEqual(
			[ String( fx.products.heavy ), String( fx.products.light ) ].sort()
		);
	} );

	test( 'tax rules warn while taxes are disabled', async ( { page } ) => {
		h.setRules( [ h.rule( 'ex', 'tax_calc', 'cart', 'set_vat_exempt' ) ] );
		await h.useAdmin( page );
		await openRules( page );
		await expect( page.locator( '.ccfo-findings' ) ).toContainText(
			'Taxes are disabled in WooCommerce'
		);
	} );

	test( 'a blocked checkout is reported in the admin until reviewed', async ( {
		page,
		browser,
	} ) => {
		h.setRules( [
			h.rule( 'no-cod', 'payment', 'cod', 'disable' ),
			h.rule( 'no-bacs', 'payment', 'bacs', 'disable' ),
		] );
		const shopper = await browser.newPage();
		await h.fillCart( shopper, fx, { light: 1 } );
		await h.openClassicCheckout( shopper );
		await expect( shopper.locator( '#payment' ) ).toContainText(
			'No payment method is available'
		);
		await shopper.close();

		await h.useAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wc-settings', {
			waitUntil: 'domcontentloaded',
		} );
		await expect( page.locator( '.notice-warning' ) ).toContainText(
			'removed every payment method'
		);

		await openRules( page );
		const banner = page
			.locator( '.components-notice' )
			.filter( { hasText: 'Every payment method was removed' } );
		await expect( banner ).toBeVisible();
		await banner.getByRole( 'button', { name: /close|dismiss/i } ).click();
		await expect( banner ).toHaveCount( 0 );

		await page.goto( '/wp-admin/admin.php?page=wc-settings', {
			waitUntil: 'domcontentloaded',
		} );
		await expect(
			page.locator( '.notice-warning', {
				hasText: 'removed every payment method',
			} )
		).toHaveCount( 0 );
	} );
} );

// ---------------------------------------------------------------------------
// Classic checkout.
// ---------------------------------------------------------------------------

test.describe( 'classic checkout', () => {
	test( 'hiding fields does not break the layout', async ( { page } ) => {
		h.setRules( [
			h.rule( 'hide-vat', 'field', 'billing_vat', 'hide' ),
			h.rule( 'hide-a2', 'field', 'billing_address_2', 'hide' ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await h.openClassicCheckout( page );

		await expect( page.locator( '#billing_vat_field' ) ).toBeHidden();
		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth >
				document.documentElement.clientWidth
		);
		expect( overflow ).toBe( false );
		// The rows around the hidden ones keep their order and do not overlap.
		const last = await page
			.locator( '#billing_last_name_field' )
			.boundingBox();
		const country = await page
			.locator( '#billing_country_field' )
			.boundingBox();
		expect( country.y ).toBeGreaterThanOrEqual( last.y + last.height - 1 );
	} );

	test( 'country change: WooCommerce locale and our rule are both applied', async ( {
		page,
	} ) => {
		h.setRules( [
			h.rule( 'req-co', 'field', 'billing_company', 'require', [
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

		const localeRequired = ( country ) =>
			page.evaluate( ( c ) => {
				const locale = JSON.parse(
					window.wc_address_i18n_params.locale
				);
				const own = ( locale[ c ] || {} ).postcode || {};
				const base = locale.default.postcode || {};
				const required =
					own.required !== undefined ? own.required : base.required;
				return !! required && own.hidden !== true;
			}, country );

		for ( const country of [ 'DE', 'HK', 'MN' ] ) {
			await h.selectCountry( page, 'billing', country );
			const company = page.locator( '#billing_company_field' );
			if ( country === 'DE' ) {
				await expect( company ).toHaveClass( /validate-required/ );
			} else {
				await expect( company ).not.toHaveClass( /validate-required/ );
			}

			const postcode = page.locator( '#billing_postcode_field' );
			if ( await postcode.isVisible() ) {
				const expected = await localeRequired( country );
				if ( expected ) {
					await expect( postcode ).toHaveClass( /validate-required/ );
				} else {
					await expect( postcode ).not.toHaveClass(
						/validate-required/
					);
				}
			}
		}
	} );

	test( 'a saved address sets the initial state before any script runs', async ( {
		browser,
	} ) => {
		h.setRules( [
			h.rule( 'show-co', 'field', 'billing_company', 'show', [
				[
					{
						source: 'billing.country',
						operator: 'equals',
						value: 'DE',
					},
				],
			] ),
		] );
		const user = fx.users.customer;
		for ( const [ country, hidden ] of [
			[ 'DE', false ],
			[ 'MN', true ],
		] ) {
			h.wp(
				'user',
				'meta',
				'update',
				String( user ),
				'billing_country',
				country
			);
			// WooCommerce keeps the customer data of a session; start from the saved profile.
			h.wpEval(
				`global $wpdb; $wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', [ 'session_key' => '${ user }' ] ); delete_user_meta( ${ user }, '_woocommerce_persistent_cart_' . get_current_blog_id() );`
			);
			const context = await browser.newContext( {
				javaScriptEnabled: false,
				ignoreHTTPSErrors: true,
				baseURL: test.info().project.use.baseURL,
			} );
			const page = await context.newPage();
			await h.login( page, 'ccfo_customer' );
			await page.goto( `/?add-to-cart=${ fx.products.light }` );
			await page.goto( '/checkout/' );
			const row = page.locator( '#billing_company_field' );
			if ( hidden ) {
				await expect( row ).toHaveClass( /ccfo-hidden/ );
			} else {
				await expect( row ).not.toHaveClass( /ccfo-hidden/ );
			}
			await context.close();
		}
		h.wp( 'user', 'meta', 'delete', String( user ), 'billing_country' );
	} );

	test( 'the admin order screen lists the applied rules', async ( {
		page,
		browser,
	} ) => {
		h.setRules( [ companyBacsRule() ] );
		const shopper = await browser.newPage();
		await h.fillCart( shopper, fx, { light: 1 } );
		await h.openClassicCheckout( shopper );
		await h.fillClassicBilling( shopper );
		await shopper.locator( '#payment_method_cod' ).check();
		await h.placeClassicOrder( shopper );
		await expect( shopper ).toHaveURL( /order-received/ );
		const orderId = h.orderIdFromUrl( shopper.url() );
		await shopper.close();

		await h.useAdmin( page );
		await page.goto(
			`/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
		);
		const box = page.locator( '.ccfo-applied-rules' );
		await expect( box ).toContainText( 'Checkout rules applied' );
		await expect( box ).toContainText( 'payment:bacs' );
		await expect( box ).toContainText( 'bacs-company' );
	} );

	test( 'tax note is hidden, total unchanged (prices shown incl. tax)', async ( {
		page,
	} ) => {
		const restore = h.withTaxes( { woocommerce_tax_display_cart: 'incl' } );
		try {
			await h.fillCart( page, fx, { light: 1 } );
			await h.openClassicCheckout( page );
			const note = page.locator( '.order-total small.includes_tax' );
			await expect( note ).toBeVisible();
			const totalWithout = await h.amount(
				page.locator( '.order-total .woocommerce-Price-amount' )
			);

			h.setRules( [
				h.rule( 'note', 'tax_display', 'checkout', 'hide_tax_note', [
					[ { source: 'customer.is_guest', operator: 'is_true' } ],
				] ),
			] );
			await h.openClassicCheckout( page );
			await expect( note ).toBeHidden();
			expect(
				await h.amount(
					page.locator( '.order-total .woocommerce-Price-amount' )
				)
			).toBe( totalWithout );
			expect( totalWithout ).toBe( 330000 );
		} finally {
			restore();
		}
	} );
} );

// ---------------------------------------------------------------------------
// Checkout Block.
// ---------------------------------------------------------------------------

test.describe( 'checkout block', () => {
	async function openBlockCheckout( page ) {
		await page.goto( '/ccfo-block-checkout/', {
			waitUntil: 'domcontentloaded',
		} );
		await page.waitForSelector( '.wc-block-checkout__form', {
			timeout: 30000,
		} );
	}

	test( 'choosing a payment method recalculates tax rules that read it', async ( {
		page,
	} ) => {
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
			await openBlockCheckout( page );
			const total = page.locator(
				'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
			);

			await page.getByLabel( 'Cash on delivery' ).check();
			await expect.poll( () => h.amount( total ) ).toBe( 330000 );

			await page.getByLabel( 'Direct bank transfer' ).check();
			await expect.poll( () => h.amount( total ) ).toBe( 300000 );

			await page.getByLabel( 'Cash on delivery' ).check();
			await expect.poll( () => h.amount( total ) ).toBe( 330000 );
		} finally {
			restore();
		}
	} );

	test( 'the required-field message names the field', async ( { page } ) => {
		h.setRules( [
			h.rule( 'req-a2', 'field', 'shipping_address_2', 'require', [
				[
					{
						source: 'shipping.country',
						operator: 'equals',
						value: 'MN',
					},
				],
			] ),
		] );
		await h.fillCart( page, fx, { light: 1 } );
		await openBlockCheckout( page );
		await page.fill( '#email', 'block@example.test' );
		for ( const [ key, value ] of Object.entries( {
			first_name: 'B',
			last_name: 'C',
			address_1: 'Street 1',
			city: 'UB',
			postcode: '14200',
			state: 'UB',
			phone: '99112233',
		} ) ) {
			const input = page.locator( `#shipping-${ key }` );
			if ( ( await input.count() ) && ( await input.isVisible() ) ) {
				await input.fill( value );
			}
		}
		await page.getByLabel( 'Cash on delivery' ).check();
		await page
			.locator( '.wc-block-components-checkout-place-order-button' )
			.click();
		await expect(
			page
				.locator( '.wc-block-components-notice-banner.is-error' )
				.first()
		).toContainText( /Apartment.*is a required field/ );
	} );

	test( 'the admin explains Checkout Block limits when it is the checkout page', async ( {
		page,
	} ) => {
		const synced = [
			'woocommerce_checkout_company_field',
			'woocommerce_checkout_address_2_field',
			'woocommerce_checkout_phone_field',
		];
		const before = synced.map( ( name ) =>
			h.wpEval( `echo wp_json_encode( get_option( '${ name }' ) );` )
		);
		h.setRules( [
			h.rule( 'show-co', 'field', 'billing_company', 'show', [
				[
					{
						source: 'billing.country',
						operator: 'equals',
						value: 'DE',
					},
				],
			] ),
		] );
		h.wp(
			'option',
			'update',
			'woocommerce_checkout_page_id',
			String( fx.pages.block_checkout )
		);
		try {
			await h.useAdmin( page );
			await openRules( page );
			await expect( page.locator( '.ccfo-app' ) ).toContainText(
				'uses the Checkout Block'
			);
			await expect( page.locator( '.ccfo-findings' ) ).toContainText(
				'works in the classic checkout only'
			);
		} finally {
			h.wp(
				'option',
				'update',
				'woocommerce_checkout_page_id',
				String( fx.pages.classic_checkout )
			);
			synced.forEach( ( name, i ) => {
				const value = JSON.parse( before[ i ] );
				if ( value === false ) {
					h.deleteOption( name );
				} else {
					h.wp( 'option', 'update', name, value );
				}
			} );
		}
	} );
} );
