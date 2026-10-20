/**
 * Classic cart and checkout: order rule messages kept at the top of the page.
 */
import $ from 'jquery';
import { createOrderNotices } from '../../assets/src/classic/notices';

const data = ( messages ) =>
	`<span class="ccfo-order-notices-data" hidden data-messages='${ JSON.stringify(
		messages
	).replace( /'/g, '&#39;' ) }'></span>`;

function page( { box = true, messages = [] } = {} ) {
	document.body.innerHTML = `
		<div class="woocommerce">
			<div class="woocommerce-notices-wrapper"></div>
			${ box ? '<div class="ccfo-order-notices" aria-live="polite"></div>' : '' }
			<form class="woocommerce-cart-form"></form>
			<div class="cart_totals">${ data( messages ) }</div>
		</div>`;
}

const texts = () =>
	$( '.ccfo-order-notices .ccfo-order-notice' )
		.map( ( i, el ) => $( el ).text() )
		.get();

function setup( options ) {
	page( options );
	const scroll = jest.fn();
	const notices = createOrderNotices( { $, root: document, scroll } );
	return { notices, scroll };
}

describe( 'order notices at the top of the page', () => {
	test( 'copies the current messages into the container as WooCommerce notices', () => {
		const { notices } = setup( { messages: [ 'Add ₮ 100 more.', 'B' ] } );
		notices.sync();
		expect( texts() ).toEqual( [ 'Add ₮ 100 more.', 'B' ] );
		const notice = $( '.ccfo-order-notice' ).first();
		expect( notice.hasClass( 'woocommerce-info' ) ).toBe( true );
		expect( notice.attr( 'role' ) ).toBe( 'status' );
	} );

	test( 'follows refreshed parts and clears when released', () => {
		const { notices } = setup( { messages: [ 'Old' ] } );
		notices.sync();
		$( '.cart_totals' ).html( data( [ 'New' ] ) );
		notices.sync( true );
		expect( texts() ).toEqual( [ 'New' ] );
		$( '.cart_totals' ).html( data( [] ) );
		notices.sync( true );
		expect( texts() ).toEqual( [] );
	} );

	test( 'removes copies that WooCommerce moved into its own notices wrapper', () => {
		const { notices } = setup( { messages: [ 'Min' ] } );
		notices.sync();
		// cart.js re-adds `.woocommerce-info` notices of the response to the wrapper.
		$( '.woocommerce-notices-wrapper' ).append(
			'<div class="woocommerce-info ccfo-order-blocked ccfo-order-notice">Min</div>'
		);
		$( '.woocommerce-notices-wrapper' ).append(
			'<div class="woocommerce-info other">Coupon applied.</div>'
		);
		notices.sync( true );
		expect( $( '.ccfo-order-notice' ) ).toHaveLength( 1 );
		expect( texts() ).toEqual( [ 'Min' ] );
		expect( $( '.woocommerce-info.other' ) ).toHaveLength( 1 );
	} );

	test( 'creates the container after the WooCommerce notices when missing', () => {
		const { notices } = setup( { box: false, messages: [ 'X' ] } );
		notices.sync();
		expect(
			$( '.woocommerce-notices-wrapper' )
				.next()
				.hasClass( 'ccfo-order-notices' )
		).toBe( true );
		expect( texts() ).toEqual( [ 'X' ] );
	} );

	test( 'scrolls only to new messages after a refresh, when out of view', () => {
		const { notices, scroll } = setup( { messages: [ 'A' ] } );
		notices.sync( false );
		expect( scroll ).not.toHaveBeenCalled();

		const box = document.querySelector( '.ccfo-order-notices' );
		box.getBoundingClientRect = () => ( { top: -500 } );
		notices.sync( true );
		expect( scroll ).not.toHaveBeenCalled(); // Same messages.

		$( '.cart_totals' ).html( data( [ 'B' ] ) );
		notices.sync( true );
		expect( scroll ).toHaveBeenCalledTimes( 1 );

		box.getBoundingClientRect = () => ( { top: 20 } );
		$( '.cart_totals' ).html( data( [ 'C' ] ) );
		notices.sync( true );
		expect( scroll ).toHaveBeenCalledTimes( 1 ); // In view.
	} );

	test( 'no data or broken data leaves the page alone; text is not HTML', () => {
		const { notices } = setup( { messages: [] } );
		$( '.cart_totals' ).empty();
		$( '.ccfo-order-notices' ).html(
			'<div class="ccfo-order-notice">Server</div>'
		);
		notices.sync( true );
		expect( texts() ).toEqual( [ 'Server' ] );

		$( '.cart_totals' ).html(
			'<span class="ccfo-order-notices-data" data-messages="{oops"></span>'
		);
		notices.sync( true );
		expect( texts() ).toEqual( [ 'Server' ] );

		$( '.cart_totals' ).html( data( [ '<b>bold</b>' ] ) );
		notices.sync( true );
		expect( texts() ).toEqual( [ '<b>bold</b>' ] );
		expect( $( '.ccfo-order-notices b' ) ).toHaveLength( 0 );
	} );
} );
