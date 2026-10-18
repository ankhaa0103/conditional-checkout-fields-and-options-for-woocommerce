/**
 * Classic checkout runtime in a DOM (SPEC 1.2, 1.3, 1.6): visibility, required
 * markup, values read from the form for every client source, locale base and
 * the tax note class.
 */
import $ from 'jquery';
import { resolveField, resolveFlag } from '../../assets/src/shared/evaluator';
import { createFieldRuntime } from '../../assets/src/classic/runtime';

$.escapeSelector = $.escapeSelector || ( ( s ) => s );

const FORM = `
<form class="checkout">
	<p class="form-row" id="billing_country_field"><label>Country</label>
		<select name="billing_country"><option value="MN">MN</option><option value="DE">DE</option></select></p>
	<p class="form-row" id="billing_state_field"><label>State</label><input name="billing_state" value="UB"></p>
	<p class="form-row" id="billing_postcode_field"><label>Postcode</label><input name="billing_postcode" value="14200"></p>
	<p class="form-row" id="billing_company_field"><label>Company&nbsp;<span class="optional">(optional)</span></label><input name="billing_company" value=""></p>
	<p class="form-row" id="billing_vat_field"><label>VAT&nbsp;<span class="optional">(optional)</span></label><input name="billing_vat" value=""></p>
	<p class="form-row validate-required" id="billing_first_name_field"><label>First&nbsp;<span class="required" aria-hidden="true">*</span></label><input name="billing_first_name" value="A"></p>
	<p class="form-row" id="newsletter_field"><label>News</label><input type="checkbox" name="newsletter" value="1"></p>
	<p class="form-row" id="topics_field"><label>Topics</label><select name="topics[]" multiple><option value="a" selected>a</option><option value="b" selected>b</option></select></p>
	<p class="form-row" id="pickup_field"><label>Pickup</label>
		<input type="radio" name="pickup" value="am"><input type="radio" name="pickup" value="pm" checked></p>
	<input type="checkbox" id="ship-to-different-address-checkbox">
	<p class="form-row" id="shipping_country_field"><label>Ship country</label><input name="shipping_country" value="FR"></p>
	<p class="form-row" id="shipping_postcode_field"><label>Ship postcode</label><input name="shipping_postcode" value="75001"></p>
</form>
<ul id="shipping_method"><li><input type="radio" class="shipping_method" name="shipping_method[0]" value="flat_rate:2" checked></li>
	<li><input type="radio" class="shipping_method" name="shipping_method[0]" value="local_pickup:3"></li></ul>
<div id="payment"><input type="radio" name="payment_method" value="cod"><input type="radio" name="payment_method" value="bacs" checked></div>
`;

function rule( id, key, action, groups, extra = {} ) {
	return {
		id,
		enabled: true,
		priority: 10,
		target: { type: 'field', key },
		action,
		groups,
		...extra,
	};
}

function setup( rules, required = {} ) {
	document.body.innerHTML = FORM;
	document.body.className = '';
	return createFieldRuntime( {
		$,
		data: {
			rules,
			required: { billing_first_name: true, ...required },
			optionalText: 'optional',
			triggers: { conditions: [], payment: false },
		},
		resolveField,
		resolveFlag,
		root: document,
	} );
}

const row = ( key ) => $( `#${ key }_field` );

describe( 'classic runtime: visibility', () => {
	it( 'show rule hides the row, disables its inputs and restores them', () => {
		const rt = setup( [
			rule( 's', 'billing_company', 'show', [
				[
					{
						source: 'billing.country',
						operator: 'equals',
						value: 'DE',
					},
				],
			] ),
		] );
		rt.apply();
		expect( row( 'billing_company' ).hasClass( 'ccfo-hidden' ) ).toBe(
			true
		);
		expect( row( 'billing_company' ).attr( 'aria-hidden' ) ).toBe( 'true' );
		expect( $( '[name=billing_company]' ).prop( 'disabled' ) ).toBe( true );

		$( '[name=billing_country]' ).val( 'DE' );
		rt.apply();
		expect( row( 'billing_company' ).hasClass( 'ccfo-hidden' ) ).toBe(
			false
		);
		expect(
			row( 'billing_company' ).attr( 'aria-hidden' )
		).toBeUndefined();
		expect( $( '[name=billing_company]' ).prop( 'disabled' ) ).toBe(
			false
		);
	} );

	it( 'does not re-enable inputs another script disabled', () => {
		const rt = setup( [
			rule( 'h', 'billing_vat', 'hide', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		$( '[name=billing_vat]' ).prop( 'disabled', true );
		$( '[name=billing_company]' ).val( 'x' );
		rt.apply();
		expect( row( 'billing_vat' ).hasClass( 'ccfo-hidden' ) ).toBe( true );
		$( '[name=billing_company]' ).val( '' );
		rt.apply();
		expect( row( 'billing_vat' ).hasClass( 'ccfo-hidden' ) ).toBe( false );
		expect( $( '[name=billing_vat]' ).prop( 'disabled' ) ).toBe( true );
	} );

	it( 'a hidden field reads as empty for other rules, as on the server', () => {
		const rt = setup( [
			rule( 'h', 'billing_company', 'hide', [] ),
			rule( 'r', 'billing_vat', 'require', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		$( '[name=billing_company]' ).val( 'Acme' );
		rt.apply();
		expect( rt.getValue( 'field:billing_company' ) ).toBe( '' );
		expect( row( 'billing_vat' ).hasClass( 'validate-required' ) ).toBe(
			false
		);
	} );
} );

describe( 'classic runtime: required markup', () => {
	it( 'require adds the WooCommerce required markup and removes it again', () => {
		const rt = setup( [
			rule( 'r', 'billing_vat', 'require', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		$( '[name=billing_company]' ).val( 'Acme' );
		rt.apply();
		const r = row( 'billing_vat' );
		expect( r.hasClass( 'validate-required' ) ).toBe( true );
		expect( r.find( 'label .required[aria-hidden="true"]' ).length ).toBe(
			1
		);
		expect( r.find( 'label .optional' ).length ).toBe( 0 );
		expect( r.find( 'input' ).attr( 'aria-required' ) ).toBe( 'true' );

		$( '[name=billing_company]' ).val( '' );
		rt.apply();
		expect( r.hasClass( 'validate-required' ) ).toBe( false );
		expect( r.find( 'label .required' ).length ).toBe( 0 );
		expect( r.find( 'label .optional' ).text() ).toBe( '(optional)' );
		expect( r.find( 'input' ).attr( 'aria-required' ) ).toBeUndefined();
	} );

	it( 'never removes the original required state', () => {
		const rt = setup( [
			rule( 'r', 'billing_first_name', 'require', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		rt.apply();
		expect(
			row( 'billing_first_name' ).hasClass( 'validate-required' )
		).toBe( true );
		expect(
			row( 'billing_first_name' ).find( 'label .required' ).length
		).toBe( 1 );
	} );

	it( 'hiding a required field drops required and markup is not duplicated', () => {
		const rt = setup( [
			rule( 'h', 'billing_first_name', 'hide', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		$( '[name=billing_company]' ).val( 'x' );
		rt.apply();
		rt.apply();
		expect( row( 'billing_first_name' ).hasClass( 'ccfo-hidden' ) ).toBe(
			true
		);
		$( '[name=billing_company]' ).val( '' );
		rt.apply();
		rt.apply();
		expect(
			row( 'billing_first_name' ).find( 'label .required' ).length
		).toBe( 1 );
	} );
} );

describe( 'classic runtime: values of every client source', () => {
	it( 'reads field, address, payment and shipping values', () => {
		const rt = setup( [] );
		$( '[name=newsletter]' ).prop( 'checked', true );
		expect( rt.getValue( 'field:billing_postcode' ) ).toBe( '14200' );
		expect( rt.getValue( 'field:newsletter' ) ).toBe( '1' );
		expect( rt.getValue( 'field:topics' ) ).toEqual( [ 'a', 'b' ] );
		expect( rt.getValue( 'field:pickup' ) ).toBe( 'pm' );
		expect( rt.getValue( 'field:missing' ) ).toBe( '' );
		$( '[name=newsletter]' ).prop( 'checked', false );
		expect( rt.getValue( 'field:newsletter' ) ).toBe( '' );

		expect( rt.getValue( 'billing.country' ) ).toBe( 'MN' );
		expect( rt.getValue( 'billing.state' ) ).toBe( 'UB' );
		expect( rt.getValue( 'billing.postcode' ) ).toBe( '14200' );
		// Shipping = billing unless "ship to a different address" is checked.
		expect( rt.getValue( 'shipping.country' ) ).toBe( 'MN' );
		$( '#ship-to-different-address-checkbox' ).prop( 'checked', true );
		expect( rt.getValue( 'shipping.country' ) ).toBe( 'FR' );
		expect( rt.getValue( 'shipping.postcode' ) ).toBe( '75001' );

		expect( rt.getValue( 'payment.method' ) ).toBe( 'bacs' );
		expect( rt.getValue( 'shipping.method' ) ).toEqual( [
			'flat_rate:2',
			'flat_rate',
		] );
	} );

	it( 'takes server-only values from the live context', () => {
		const rt = setup( [] );
		expect( rt.getValue( 'cart.total' ) ).toBeNull();
		rt.setFacts( {
			'cart.total': 600000,
			'customer.role': [ 'customer' ],
		} );
		expect( rt.getValue( 'cart.total' ) ).toBe( 600000 );
		expect( rt.getValue( 'customer.role' ) ).toEqual( [ 'customer' ] );
	} );
} );

describe( 'classic runtime: locale base and tax note', () => {
	afterEach( () => {
		delete window.wc_address_i18n_params;
	} );

	it( 'takes the required state WooCommerce set for locale fields after a country change', () => {
		window.wc_address_i18n_params = {
			locale_fields: JSON.stringify( {
				postcode: '#billing_postcode_field',
			} ),
		};
		const rt = setup( [
			rule( 'x', 'billing_postcode', 'hide', [
				[ { source: 'field:billing_company', operator: 'not_empty' } ],
			] ),
		] );
		rt.apply();
		expect(
			row( 'billing_postcode' ).hasClass( 'validate-required' )
		).toBe( false );

		// address-i18n.js marks the postcode required for the new country.
		row( 'billing_postcode' ).addClass( 'validate-required' );
		rt.captureBase();
		rt.apply();
		expect(
			row( 'billing_postcode' ).hasClass( 'validate-required' )
		).toBe( true );
		expect(
			row( 'billing_postcode' ).find( 'label .required' ).length
		).toBe( 1 );
	} );

	it( 'toggles the tax note body class from tax display rules', () => {
		const rt = setup( [
			{
				id: 't',
				enabled: true,
				priority: 10,
				target: { type: 'tax_display', key: 'checkout' },
				action: 'hide_tax_note',
				groups: [
					[
						{
							source: 'payment.method',
							operator: 'equals',
							value: 'cod',
						},
					],
				],
			},
		] );
		rt.apply();
		expect( document.body.classList.contains( 'ccfo-hide-tax-note' ) ).toBe(
			false
		);
		$( '[name=payment_method][value=cod]' ).prop( 'checked', true );
		rt.apply();
		expect( document.body.classList.contains( 'ccfo-hide-tax-note' ) ).toBe(
			true
		);
	} );
} );
