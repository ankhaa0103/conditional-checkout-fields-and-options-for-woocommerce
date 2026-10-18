/**
 * Classic checkout refresh decisions.
 */
import { createRefresher } from '../../assets/src/classic/refresher';

function setup( delay = 5000 ) {
	const values = { company: '' };
	const refresh = jest.fn();
	const refresher = createRefresher( {
		conditions: [
			{ source: 'field:billing_company', operator: 'not_empty' },
		],
		delay,
		evaluate: () => values.company.trim() !== '',
		refresh,
		timers: { setTimeout, clearTimeout },
	} );
	refresher.sent();
	return { values, refresh, refresher };
}

describe( 'refresher', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'refreshes after the delay while typing, once', () => {
		const { values, refresh, refresher } = setup();
		values.company = 'A';
		refresher.changed( false );
		values.company = 'Ac';
		refresher.changed( false );
		jest.advanceTimersByTime( 4999 );
		expect( refresh ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( refresh ).toHaveBeenCalledTimes( 1 );

		values.company = 'Acme';
		refresher.changed( false );
		jest.advanceTimersByTime( 10000 );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'refreshes at once when the field is left', () => {
		const { values, refresh, refresher } = setup();
		values.company = 'Acme';
		refresher.changed( true );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not refresh when the result is back to what the server saw', () => {
		const { values, refresh, refresher } = setup();
		values.company = 'A';
		refresher.changed( false );
		values.company = '';
		refresher.changed( false );
		jest.advanceTimersByTime( 10000 );
		expect( refresh ).not.toHaveBeenCalled();
	} );

	it( 'a server refresh resets the baseline', () => {
		const { values, refresh, refresher } = setup();
		values.company = 'Acme';
		refresher.sent();
		refresher.changed( true );
		expect( refresh ).not.toHaveBeenCalled();
	} );

	it( 'delay 0 refreshes on every result change', () => {
		const { values, refresh, refresher } = setup( 0 );
		values.company = 'A';
		refresher.changed( false );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );
} );
