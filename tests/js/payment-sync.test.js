/**
 * Checkout Block payment method sync: not sent when the server already uses the
 * method, and never right after address input that WooCommerce has not sent yet.
 */
import { createPaymentSync } from '../../assets/src/blocks/sync';

function fakeStores() {
	const state = {
		method: 'bacs',
		server: 'bacs',
		updating: false,
		customer: { shippingAddress: { first_name: '' } },
	};
	const select = ( name ) =>
		name === 'wc/store/payment'
			? { getActivePaymentMethod: () => state.method }
			: {
					isCustomerDataUpdating: () => state.updating,
					getCustomerData: () => state.customer,
					getCartData: () => ( {
						extensions: { ccfo: { paymentMethod: state.server } },
					} ),
			  };
	const type = ( name ) => {
		state.customer = { shippingAddress: { first_name: name } };
	};
	return { state, select, type };
}

function fakeClock() {
	let clock = 1000;
	let queue = [];
	return {
		now: () => clock,
		timers: {
			setTimeout: ( fn, ms ) => queue.push( { fn, at: clock + ms } ),
			clearTimeout: () => {},
		},
		advance( ms ) {
			const end = clock + ms;
			while ( true ) {
				queue.sort( ( a, b ) => a.at - b.at );
				const next = queue[ 0 ];
				if ( ! next || next.at > end ) {
					break;
				}
				queue = queue.slice( 1 );
				clock = next.at;
				next.fn();
			}
			clock = end;
		},
	};
}

function setup( options = {} ) {
	const stores = fakeStores();
	const clock = fakeClock();
	const send = jest.fn();
	const sync = createPaymentSync( {
		select: stores.select,
		send,
		timers: clock.timers,
		now: clock.now,
		...options,
	} );
	return { ...stores, clock, send, sync };
}

describe( 'payment method sync', () => {
	test( 'nothing is sent while the server already uses the method', () => {
		const { sync, send, type, clock } = setup();
		sync.check();
		type( 'B' );
		sync.check();
		type( 'Bl' );
		sync.check();
		clock.advance( 20000 );
		expect( send ).not.toHaveBeenCalled();
	} );

	test( 'a new method waits for a pause in address input', () => {
		const { state, sync, send, type, clock } = setup();
		sync.check();
		type( 'Block' );
		sync.check();

		state.method = 'cod';
		sync.check();
		expect( send ).not.toHaveBeenCalled();
		clock.advance( 2000 );
		type( 'Block B' );
		sync.check();
		clock.advance( 3000 );
		expect( send ).not.toHaveBeenCalled();
		clock.advance( 700 );
		expect( send ).toHaveBeenCalledWith( 'cod' );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'no address input: sent at once; same method again: not sent', () => {
		const { state, sync, send } = setup();
		state.method = 'cod';
		sync.check();
		expect( send ).toHaveBeenCalledWith( 'cod' );
		sync.check();
		expect( send ).toHaveBeenCalledTimes( 1 );

		// The server now uses cod; back to bacs is a change again.
		state.server = 'cod';
		state.method = 'bacs';
		sync.check();
		expect( send ).toHaveBeenLastCalledWith( 'bacs' );
	} );

	test( 'waits while WooCommerce sends the address', () => {
		const { state, sync, send, clock } = setup();
		state.updating = true;
		state.method = 'cod';
		sync.check();
		clock.advance( 1000 );
		expect( send ).not.toHaveBeenCalled();
		state.updating = false;
		clock.advance( 300 );
		expect( send ).toHaveBeenCalledWith( 'cod' );
	} );

	test( 'never waits longer than maxWait', () => {
		const { state, sync, send, clock } = setup( { maxWaitMs: 2000 } );
		state.updating = true;
		state.method = 'cod';
		sync.check();
		clock.advance( 1800 );
		expect( send ).not.toHaveBeenCalled();
		clock.advance( 400 );
		expect( send ).toHaveBeenCalledWith( 'cod' );
	} );

	test( 'checks while waiting do not start more timers', () => {
		const stores = fakeStores();
		const clock = fakeClock();
		const setTimeout = jest.fn( clock.timers.setTimeout );
		const sync = createPaymentSync( {
			select: stores.select,
			send: jest.fn(),
			timers: { ...clock.timers, setTimeout },
			now: clock.now,
		} );
		stores.state.updating = true;
		stores.state.method = 'cod';
		sync.check();
		sync.check();
		sync.check();
		expect( setTimeout ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'no method selected: nothing is sent', () => {
		const { state, sync, send } = setup();
		state.method = '';
		sync.check();
		expect( send ).not.toHaveBeenCalled();
	} );
} );
