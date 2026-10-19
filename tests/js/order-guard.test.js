/**
 * Cart and Checkout Blocks: order rule messages and held-back buttons (SPEC §13, 3.3).
 */
import { BUTTONS, createOrderGuard } from '../../assets/src/blocks/order';

/**
 * Fake `@wordpress/data` dispatch that records notice and validation calls.
 *
 * @return {Object} Spy.
 */
function fakeDispatch() {
	const notices = new Map();
	const validation = {};
	const stores = {
		'core/notices': {
			createNotice: jest.fn( ( status, message, options ) =>
				notices.set( `${ options.context }|${ options.id }`, {
					status,
					message,
					...options,
				} )
			),
			removeNotice: jest.fn( ( id, context ) =>
				notices.delete( `${ context }|${ id }` )
			),
		},
		'wc/store/validation': {
			setValidationErrors: jest.fn( ( errors ) =>
				Object.assign( validation, errors )
			),
			clearValidationError: jest.fn(
				( key ) => delete validation[ key ]
			),
		},
	};
	return {
		dispatch: ( name ) => stores[ name ],
		stores,
		notices,
		validation,
	};
}

function page( kind ) {
	document.body.className = '';
	document.body.innerHTML =
		kind === 'checkout'
			? `<div class="wp-block-woocommerce-checkout"><button class="wc-block-components-checkout-place-order-button">Place order</button></div>`
			: `<div class="wp-block-woocommerce-cart"><a class="wc-block-cart__submit-button" href="/checkout/">Proceed to checkout</a></div>`;
}

function click( selector ) {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );
	document.querySelector( selector ).dispatchEvent( event );
	return event.defaultPrevented;
}

function press( selector, name ) {
	const event = new window.KeyboardEvent( 'keydown', {
		key: name,
		bubbles: true,
		cancelable: true,
	} );
	document.querySelector( selector ).dispatchEvent( event );
	return event.defaultPrevented;
}

describe( 'order guard', () => {
	test( 'checkout: notices, validation error, held-back button and release', () => {
		page( 'checkout' );
		const spy = fakeDispatch();
		const guard = createOrderGuard( {
			dispatch: spy.dispatch,
			doc: document,
		} );
		const button = '.wc-block-components-checkout-place-order-button';

		guard.update( {
			checkoutBlocked: [ 'cart message' ],
			orderBlocked: [ 'First.', 'Second.' ],
		} );

		expect( guard.isBlocked() ).toBe( true );
		expect( [ ...spy.notices.values() ].map( ( n ) => n.message ) ).toEqual(
			[ 'First.', 'Second.' ]
		);
		expect(
			[ ...spy.notices.values() ].every(
				( n ) =>
					n.context === 'wc/checkout' &&
					n.status === 'error' &&
					n.isDismissible === false
			)
		).toBe( true );
		expect( spy.validation[ 'ccfo-order-blocked' ] ).toEqual( {
			message: 'First. Second.',
			hidden: true,
		} );
		expect( document.body.classList.contains( 'ccfo-order-blocked' ) ).toBe(
			true
		);
		expect(
			document.querySelector( button ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
		expect( click( button ) ).toBe( true );
		expect( press( button, 'Enter' ) ).toBe( true );
		expect( press( button, ' ' ) ).toBe( true );
		expect( press( button, 'Tab' ) ).toBe( false );

		// Same data again: nothing is created twice.
		guard.update( {
			checkoutBlocked: [ 'cart message' ],
			orderBlocked: [ 'First.', 'Second.' ],
		} );
		expect(
			spy.stores[ 'core/notices' ].createNotice
		).toHaveBeenCalledTimes( 2 );

		// One message left.
		guard.update( { orderBlocked: [ 'Second.' ] } );
		expect( [ ...spy.notices.values() ].map( ( n ) => n.message ) ).toEqual(
			[ 'Second.' ]
		);

		// Released.
		guard.update( { orderBlocked: [], checkoutBlocked: [] } );
		expect( guard.isBlocked() ).toBe( false );
		expect( spy.notices.size ).toBe( 0 );
		expect( spy.validation ).toEqual( {} );
		expect( document.body.classList.contains( 'ccfo-order-blocked' ) ).toBe(
			false
		);
		expect(
			document.querySelector( button ).hasAttribute( 'aria-disabled' )
		).toBe( false );
		expect( click( button ) ).toBe( false );
	} );

	test( 'cart: only checkoutBlocked counts, no validation error', () => {
		page( 'cart' );
		const spy = fakeDispatch();
		const guard = createOrderGuard( {
			dispatch: spy.dispatch,
			doc: document,
		} );
		const button = '.wc-block-cart__submit-button';

		guard.update( {
			checkoutBlocked: [],
			orderBlocked: [ 'Order only.' ],
		} );
		expect( guard.isBlocked() ).toBe( false );
		expect( spy.notices.size ).toBe( 0 );
		expect( click( button ) ).toBe( false );

		guard.update( {
			checkoutBlocked: [ 'Add more.' ],
			orderBlocked: [ 'Add more.' ],
		} );
		expect( guard.isBlocked() ).toBe( true );
		expect( [ ...spy.notices.values() ] ).toEqual( [
			expect.objectContaining( {
				message: 'Add more.',
				context: 'wc/cart',
			} ),
		] );
		expect(
			spy.stores[ 'wc/store/validation' ].setValidationErrors
		).not.toHaveBeenCalled();
		expect( click( button ) ).toBe( true );
	} );

	test( 'missing data, missing stores and clicks elsewhere are safe', () => {
		page( 'checkout' );
		const guard = createOrderGuard( {
			dispatch: () => undefined,
			doc: document,
		} );
		expect( () => guard.update( undefined ) ).not.toThrow();
		expect( () =>
			guard.update( { orderBlocked: [ 'Blocked.' ] } )
		).not.toThrow();
		expect( guard.isBlocked() ).toBe( true );

		document.body.insertAdjacentHTML(
			'beforeend',
			'<button class="other">Other</button>'
		);
		expect( click( '.other' ) ).toBe( false );
	} );

	test( 'checkout: messages follow the selected payment method without a server round trip', () => {
		page( 'checkout' );
		const spy = fakeDispatch();
		const guard = createOrderGuard( {
			dispatch: spy.dispatch,
			doc: document,
		} );
		const data = {
			orderBlocked: [ 'Session method message.' ],
			orderBlockedByMethod: { cod: [ 'COD over the limit.' ], bacs: [] },
		};
		const messages = () =>
			[ ...spy.notices.values() ].map( ( n ) => n.message );

		guard.update( data, 'cod' );
		expect( messages() ).toEqual( [ 'COD over the limit.' ] );
		guard.update( data, 'bacs' );
		expect( messages() ).toEqual( [] );
		expect( guard.isBlocked() ).toBe( false );
		// A method the map does not know falls back to the session's decision.
		guard.update( data, 'paypal' );
		expect( messages() ).toEqual( [ 'Session method message.' ] );
		// No map (rules do not read the payment method).
		guard.update( { orderBlocked: [] }, 'cod' );
		expect( messages() ).toEqual( [] );
	} );

	test( 'buttons rendered again by the block are marked again', () => {
		page( 'cart' );
		const guard = createOrderGuard( {
			dispatch: fakeDispatch().dispatch,
			doc: document,
		} );
		const selector = '.wc-block-cart__submit-button';
		guard.update( { checkoutBlocked: [ 'Min.' ] } );
		expect(
			document.querySelector( selector ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		// React replaces the attribute or the whole button.
		document.querySelector( selector ).setAttribute( 'aria-disabled', '' );
		guard.markButtons();
		expect(
			document.querySelector( selector ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
		page( 'cart' );
		guard.markButtons();
		expect(
			document.querySelector( selector ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		// A button the block disables itself is left alone when released.
		guard.update( { checkoutBlocked: [] } );
		expect(
			document.querySelector( selector ).hasAttribute( 'aria-disabled' )
		).toBe( false );
		document
			.querySelector( selector )
			.setAttribute( 'aria-disabled', 'true' );
		guard.markButtons();
		expect(
			document.querySelector( selector ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
	} );

	test( 'the button selector covers both blocks', () => {
		expect( BUTTONS ).toContain( '.wc-block-cart__submit-button' );
		expect( BUTTONS ).toContain(
			'.wc-block-components-checkout-place-order-button'
		);
	} );
} );
