/**
 * Admin UI: create a rule, save, conflicts, messages (docs/SPEC.md 1.11, A10).
 */
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

test.beforeAll( () => {
	h.setRules( [] );
} );

test.afterAll( () => {
	h.setRules( [] );
} );

async function openRules( page ) {
	await page.goto( '/wp-admin/admin.php?page=ccfo-checkout-rules', {
		waitUntil: 'domcontentloaded',
	} );
	await expect(
		page.getByRole( 'button', { name: 'Add rule' } )
	).toBeVisible( { timeout: 30000 } );
}

test(
	'creates and saves a payment rule through the UI',
	{ tag: '@smoke' },
	async ( { page } ) => {
		await h.useAdmin( page );
		await openRules( page );

		// SPEC 2.5: Checkout Block fields of other plugins are listed as managed by them.
		await expect( page.locator( '.ccfo-app' ) ).toContainText(
			'PO number (ccfo-test/po-number)'
		);

		await page.getByRole( 'button', { name: 'Add rule' } ).click();
		await page.getByLabel( 'Rule name' ).fill( 'No COD for big carts' );
		await page.getByLabel( 'Applies to' ).selectOption( 'payment' );

		const method = page.getByRole( 'combobox', { name: 'Payment method' } );
		await method.fill( 'Cash' );
		await page.getByRole( 'option', { name: /Cash on delivery/ } ).click();

		await page.getByLabel( 'Action' ).selectOption( 'disable' );
		await page.getByRole( 'button', { name: '+ Add condition' } ).click();

		const source = page.getByRole( 'combobox', { name: 'Condition' } );
		await source.fill( 'Number of items' );
		await page.getByRole( 'option', { name: /Number of items/ } ).click();
		await page.getByLabel( 'Operator' ).selectOption( 'gt' );
		await page.getByLabel( 'Value', { exact: true } ).fill( '1' );

		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Rules saved.' )
		).toBeVisible();

		const stored = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		);
		expect( stored.rules ).toHaveLength( 1 );
		expect( stored.rules[ 0 ] ).toMatchObject( {
			title: 'No COD for big carts',
			target: { type: 'payment', key: 'cod' },
			action: 'disable',
			groups: [ [ { source: 'cart.qty', operator: 'gt', value: 1 } ] ],
		} );
	}
);

test(
	'A10: conflicting rules are explained on both rules',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [
			h.rule( 'show-co', 'field', 'billing_company', 'show' ),
			h.rule( 'hide-co', 'field', 'billing_company', 'hide' ),
		] );
		await h.useAdmin( page );
		await openRules( page );

		const warnings = page
			.locator( '.ccfo-findings' )
			.getByText( /Conflicts with/ );
		await expect( warnings ).toHaveCount( 2 );
		await expect( warnings.first() ).toContainText( '"hide-co" wins' );
	}
);

test( 'invalid rules are rejected by the server with the reason', async ( {
	page,
} ) => {
	h.setRules( [] );
	await h.useAdmin( page );
	await openRules( page );

	await page.getByRole( 'button', { name: 'Add rule' } ).click();
	// Field rule without a field chosen.
	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect(
		page
			.locator( '.components-notice' )
			.getByText( 'Some rules are not valid.' )
	).toBeVisible();
	await expect(
		page
			.locator( '.ccfo-findings' )
			.getByText( 'Target key is missing or invalid.' )
			.first()
	).toBeVisible();
} );

test(
	'customer messages and live update delay are saved',
	{ tag: '@smoke' },
	async ( { page } ) => {
		await h.useAdmin( page );
		await openRules( page );
		await page.getByRole( 'tab', { name: 'Settings' } ).click();
		await page
			.getByLabel( 'No payment method message' )
			.fill( 'Please call us to order.' );
		await page.getByLabel( 'Live update delay (seconds)' ).fill( '3' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Settings saved.' )
		).toBeVisible();

		const settings = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_settings', '--format=json' )
		);
		expect( settings.payment_blocked_message ).toBe(
			'Please call us to order.'
		);
		expect( settings.live_update_delay ).toBe( '3' );
		h.deleteOption( 'ccfo_settings' );
	}
);
