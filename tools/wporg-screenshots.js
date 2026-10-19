/**
 * Takes the WordPress.org screenshots (readme "== Screenshots ==" 1–6) from the
 * local test site into .wordpress-org/. Needs the E2E fixtures.
 *
 *   node tools/wporg-screenshots.js
 */
const path = require( 'path' );
const { chromium } = require( '@playwright/test' );
const h = require( '../tests/e2e/helpers' );

const OUT = path.join( __dirname, '..', '.wordpress-org' );
const BASE = process.env.CCFO_BASE_URL || 'https://brand.mn';

const companyBacs = h.rule(
	'bank_transfer_companies',
	'payment',
	'bacs',
	'enable',
	[
		[ { source: 'field:billing_company', operator: 'not_empty' } ],
		[
			{
				source: 'customer.role',
				operator: 'any_in',
				value: [ 'ccfo_wholesale' ],
			},
		],
	]
);
companyBacs.title = 'Bank transfer for companies and wholesale customers';

const vatRequired = h.rule(
	'vat_for_companies',
	'field',
	'billing_vat',
	'require',
	[ [ { source: 'field:billing_company', operator: 'not_empty' } ] ]
);
vatRequired.title = 'VAT number required for companies';

const noCod = h.rule( 'no_cod_large_orders', 'payment', 'cod', 'disable', [
	[ { source: 'cart.total', operator: 'gt', value: 500000 } ],
] );
noCod.title = 'No cash on delivery above 500,000';

const companyDe = h.rule( 'company_de', 'field', 'billing_company', 'show', [
	[ { source: 'billing.country', operator: 'in', value: [ 'DE', 'FR' ] } ],
] );
companyDe.title = 'Company name only for Germany and France';

const hideCompanyGuests = h.rule(
	'company_guests',
	'field',
	'billing_company',
	'hide',
	[ [ { source: 'customer.is_guest', operator: 'is_true' } ] ]
);
hideCompanyGuests.title = 'Hide company name for guests';

// Fixture-only notices are not part of the product screenshots.
const HIDE_FIXTURE_NOTICES = `
	.ccfo-app > .components-notice:has( > .components-notice__content:not(:empty) ):nth-of-type(n+2) { display: none !important; }
	.ccfo-savebar { position: static !important; }
`;

async function adminPage( browser ) {
	const context = await browser.newContext( {
		baseURL: BASE,
		ignoreHTTPSErrors: true,
		viewport: { width: 1280, height: 900 },
		deviceScaleFactor: 1,
	} );
	const page = await context.newPage();
	await h.login( page, 'ccfo_admin' );
	return page;
}

async function openRules( page ) {
	await page.goto( '/wp-admin/admin.php?page=ccfo-checkout-rules' );
	await page.getByRole( 'button', { name: 'Add rule' } ).waitFor();
	await page.addStyleTag( { content: HIDE_FIXTURE_NOTICES } );
	await page.waitForTimeout( 1200 );
}

( async () => {
	const fx = h.fixtures();
	const browser = await chromium.launch();
	try {
		// 1. Rules list with conflict warnings.
		h.setRules( [
			companyBacs,
			vatRequired,
			noCod,
			companyDe,
			hideCompanyGuests,
		] );
		const admin = await adminPage( browser );
		await openRules( admin );
		await admin
			.locator( '.ccfo-rules' )
			.screenshot( { path: path.join( OUT, 'screenshot-1.png' ) } );

		// 2. Rule editor.
		await admin
			.locator( '.ccfo-rule' )
			.first()
			.getByRole( 'button', { name: 'Edit' } )
			.click();
		await admin.waitForTimeout( 800 );
		await admin
			.locator( '.ccfo-rule' )
			.first()
			.screenshot( { path: path.join( OUT, 'screenshot-2.png' ) } );

		// 3. Settings.
		await openRules( admin );
		await admin.getByRole( 'tab', { name: 'Settings' } ).click();
		await admin.locator( '.ccfo-messages' ).waitFor();
		await admin
			.locator( '.ccfo-tabs' )
			.screenshot( { path: path.join( OUT, 'screenshot-3.png' ) } );

		// 4 + 5. Classic checkout as a guest.
		h.setRules( [ companyBacs, vatRequired, noCod ] );
		const shopContext = await browser.newContext( {
			baseURL: BASE,
			ignoreHTTPSErrors: true,
			// Tall enough that the theme's sticky header never kicks in.
			viewport: { width: 1280, height: 2400 },
		} );
		const shop = await shopContext.newPage();
		await h.fillCart( shop, fx, { light: 1 } );
		await h.openClassicCheckout( shop );
		await h.fillClassicBilling( shop );
		await shop.fill( '#billing_company', 'Acme Trading LLC' );
		await shop.locator( '#billing_first_name' ).focus();
		await shop.locator( '#payment_method_bacs' ).waitFor();
		await h.waitForCheckoutIdle( shop );
		await shop.waitForTimeout( 800 );
		await shop
			.locator( '.woocommerce-billing-fields' )
			.screenshot( { path: path.join( OUT, 'screenshot-4.png' ) } );
		await shop.locator( '#payment_method_bacs' ).check();
		await h.waitForCheckoutIdle( shop );
		await shop.waitForTimeout( 800 );
		await shop
			.locator( '#payment' )
			.screenshot( { path: path.join( OUT, 'screenshot-5.png' ) } );

		// 6. Order screen.
		await shop.fill( '#billing_vat', 'DE123456789' );
		await shop.locator( '#payment_method_bacs' ).check();
		await h.placeClassicOrder( shop );
		const orderId = h.orderIdFromUrl( shop.url() );
		await admin.goto(
			`/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
		);
		await admin.locator( '#order_data' ).waitFor();
		await admin
			.locator( '#order_data' )
			.screenshot( { path: path.join( OUT, 'screenshot-6.png' ) } );

		// 7. Cart below the minimum order amount.
		const minimum = h.rule(
			'minimum_order',
			'order',
			'order',
			'block_checkout',
			[ [ { source: 'cart.subtotal', operator: 'lt', value: 400000 } ] ],
			{
				args: {
					message: 'Add {remaining} more to order (minimum {min}).',
				},
			}
		);
		h.setRules( [ minimum ] );
		await h.fillCart( shop, fx, { light: 1 } );
		await shop.goto( '/cart/', { waitUntil: 'domcontentloaded' } );
		await shop.locator( '.cart_totals .ccfo-order-blocked' ).waitFor();
		await shop.waitForTimeout( 800 );
		await shop
			.locator( '.cart_totals' )
			.screenshot( { path: path.join( OUT, 'screenshot-7.png' ) } );

		// eslint-disable-next-line no-console
		console.log( 'Screenshots written to', OUT );
	} finally {
		h.setRules( [] );
		await browser.close();
	}
} )();
