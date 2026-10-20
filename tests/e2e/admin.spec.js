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

test(
	'two-step conditions, lists over the form and aligned rows',
	{ tag: '@smoke' },
	async ( { page } ) => {
		h.setRules( [] );
		await h.useAdmin( page );
		await openRules( page );
		await page.getByRole( 'button', { name: 'Add rule' } ).click();
		await page
			.getByLabel( 'Rule name' )
			.fill( 'Pickup only without company' );

		// "Rule name" and "Priority" start on the same line.
		const name = await page.getByLabel( 'Rule name' ).boundingBox();
		const priority = await page.getByLabel( 'Priority' ).boundingBox();
		expect( Math.abs( name.y - priority.y ) ).toBeLessThan( 2 );
		// "Enabled" comes after them, centred on their inputs.
		const toggle = await page
			.locator( '.ccfo-editor__enabled .components-form-toggle' )
			.boundingBox();
		expect( toggle.x ).toBeGreaterThan( priority.x + priority.width );
		expect(
			Math.abs(
				toggle.y +
					toggle.height / 2 -
					( priority.y + priority.height / 2 )
			)
		).toBeLessThan( 2 );

		await page.getByLabel( 'Applies to' ).selectOption( 'shipping' );
		const method = page.getByRole( 'combobox', {
			name: 'Shipping method',
		} );
		await method.fill( 'Pickup' );
		await page.getByRole( 'option', { name: /CCFO Pickup/ } ).click();
		await page.getByLabel( 'Action' ).selectOption( 'disable' );
		await page.getByRole( 'button', { name: '+ Add condition' } ).click();

		// The condition list holds one entry for all fields and one for all categories.
		const source = page.getByRole( 'combobox', { name: 'Condition' } );
		const addAnd = page.getByRole( 'button', { name: '+ AND condition' } );
		const before = await addAnd.boundingBox();
		await source.click();
		await source.fill( '' );
		const options = page
			.locator( '.ccfo-condition__source' )
			.getByRole( 'option' );
		await expect( options.first() ).toBeVisible();
		const labels = await options.allInnerTexts();
		expect(
			labels.filter( ( l ) => l.startsWith( 'Field value' ) )
		).toEqual( [ 'Field value: a checkout field…' ] );
		expect(
			labels.filter( ( l ) =>
				/Subtotal of (a )?(product )?category/.test( l )
			)
		).toHaveLength( 1 );

		// The open list floats over the form: the next row does not move, and many
		// entries are visible at once.
		const after = await addAnd.boundingBox();
		expect( after.y ).toBe( before.y );
		const list = await page
			.locator( '.components-form-token-field__suggestions-list' )
			.first()
			.boundingBox();
		expect( list.height ).toBeGreaterThan( 250 );

		await source.fill( 'a checkout field' );
		await page
			.getByRole( 'option', { name: 'Field value: a checkout field…' } )
			.click();
		const field = page.getByRole( 'combobox', { name: 'Checkout field' } );
		await field.fill( 'billing_company' );
		await page
			.getByRole( 'option', { name: /\(billing_company\)/ } )
			.click();
		await page.getByLabel( 'Operator' ).selectOption( 'in' );

		// The value input is on the same line as the condition (no visible label);
		// the how-to text is shown below it.
		const row = page.locator( '.ccfo-condition' ).first();
		const sourceBox = await row
			.locator(
				'.ccfo-condition__source .components-combobox-control__suggestions-container'
			)
			.boundingBox();
		const valueBox = await row
			.locator(
				'.ccfo-condition__value .components-form-token-field__input-container'
			)
			.boundingBox();
		expect( Math.abs( sourceBox.y - valueBox.y ) ).toBeLessThan( 3 );
		await expect(
			row.locator( '.components-form-token-field__help' )
		).toBeVisible();
		await expect(
			row.locator( '.ccfo-condition__value input' )
		).toHaveAttribute( 'placeholder', 'Type a value and press Enter' );

		await row.locator( '.ccfo-condition__value input' ).fill( 'Acme' );
		await page.keyboard.press( 'Enter' );

		await page.getByRole( 'button', { name: 'Save rules' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Rules saved.' )
		).toBeVisible();
		const stored = JSON.parse(
			h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
		);
		expect( stored.rules[ 0 ].groups ).toEqual( [
			[
				{
					source: 'field:billing_company',
					operator: 'in',
					value: [ 'Acme' ],
				},
			],
		] );

		// After reload, both steps show the saved choice.
		await openRules( page );
		await page.getByRole( 'button', { name: 'Edit' } ).first().click();
		await expect(
			page.getByRole( 'combobox', { name: 'Condition' } )
		).toHaveValue( 'Field value: a checkout field…' );
		await expect(
			page.getByRole( 'combobox', { name: 'Checkout field' } )
		).toHaveValue( /billing_company/ );
		h.setRules( [] );
	}
);

test( 'several values typed into a one-value text condition', async ( {
	page,
} ) => {
	h.setRules( [
		h.rule( 'co', 'payment', 'bacs', 'enable', [
			[
				{
					source: 'field:billing_company',
					operator: 'contains',
					value: 'ewq,ttwet,tewt',
				},
			],
		] ),
	] );
	await h.useAdmin( page );
	await openRules( page );
	await page.getByRole( 'button', { name: 'Edit' } ).first().click();
	const row = page.locator( '.ccfo-condition' ).first();
	const hint = row.locator( '.ccfo-condition__hint' );

	// "contains" has no list form: explain the OR group.
	await expect( hint ).toContainText(
		'This compares the whole text "ewq,ttwet,tewt".'
	);
	await expect( hint ).toContainText( 'add an OR group for each word' );
	await expect( hint.getByRole( 'button' ) ).toHaveCount( 0 );

	// "is" can switch to "is one of" with the values split.
	await page.getByLabel( 'Operator' ).selectOption( 'equals' );
	await hint.getByRole( 'button', { name: 'Use "is one of"' } ).click();
	await expect( page.getByLabel( 'Operator' ) ).toHaveValue( 'in' );
	await expect( hint ).toHaveCount( 0 );
	await expect(
		row.locator( '.components-form-token-field__token' )
	).toHaveCount( 3 );

	await page.getByRole( 'button', { name: 'Save rules' } ).click();
	await expect(
		page.locator( '.components-notice' ).getByText( 'Rules saved.' )
	).toBeVisible();
	const stored = JSON.parse(
		h.wp( 'option', 'get', 'ccfo_rules', '--format=json' )
	);
	expect( stored.rules[ 0 ].groups[ 0 ][ 0 ] ).toEqual( {
		source: 'field:billing_company',
		operator: 'in',
		value: [ 'ewq', 'ttwet', 'tewt' ],
	} );

	// One value: no hint.
	await page.getByLabel( 'Operator' ).selectOption( 'not_equals' );
	await row.locator( '.ccfo-condition__value input' ).fill( 'Acme' );
	await expect( hint ).toHaveCount( 0 );
	h.setRules( [] );
} );
