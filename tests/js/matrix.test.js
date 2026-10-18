/**
 * Exhaustive PHP/JS parity matrix.
 *
 * Builds every operator × actual value × rule value combination, every action
 * combination for the resolver and every AND/OR group shape, evaluates them with
 * the JS evaluator and compares with tests/fixtures/matrix.json.
 * tests/unit/MatrixTest.php runs the same file through the PHP evaluator, and
 * checks the resolver cases against an oracle written from SPEC §3/§8.
 *
 * Regenerate after an intended behaviour change: UPDATE_MATRIX=1 npm run test:js
 */
import fs from 'fs';
import path from 'path';
import {
	applyOperator,
	matches,
	resolveField,
	resolveFlag,
	resolveToggle,
} from '../../assets/src/shared/evaluator';

const FILE = path.join( __dirname, '../fixtures/matrix.json' );

const ACTUALS = [
	null,
	'',
	' ',
	'0',
	'1',
	'abc',
	' ABC ',
	'Улаанбаатар',
	'a,b',
	0,
	1,
	1.5,
	-2,
	10,
	'10',
	'10.0',
	' 12.5 ',
	'1e2',
	'.5',
	'5.',
	'x1',
	'on',
	'no',
	'false',
	true,
	false,
	[],
	[ 'a' ],
	[ 'A', ' b ' ],
	[ '1', '2' ],
	[ 1, 2 ],
	[ '', ' ' ],
	[ '3' ],
];

const VALUES = {
	scalar: [ 'abc', 'ABC', '1', '10', 'улаанбаатар', 'b', '' ],
	number: [ 0, 1, 10, 1.5, -2, '10', 'abc' ],
	list: [
		[ 'abc' ],
		[ 'ABC', 'x' ],
		[ '1', '2' ],
		[ '1' ],
		[ '3', '4' ],
		[],
		[ 'a', 'b', 'c' ],
		[ '10' ],
	],
	range: [
		[ 1, 10 ],
		[ 0, 0 ],
		[ 10, 1 ],
		[ -5, 1.5 ],
		[ '1', '10' ],
		[ 'a', 5 ],
	],
	none: [ null ],
};

const SHAPES = {
	equals: 'scalar',
	not_equals: 'scalar',
	contains: 'scalar',
	not_contains: 'scalar',
	in: 'list',
	not_in: 'list',
	empty: 'none',
	not_empty: 'none',
	checked: 'none',
	unchecked: 'none',
	is_true: 'none',
	is_false: 'none',
	eq: 'number',
	neq: 'number',
	gt: 'number',
	gte: 'number',
	lt: 'number',
	lte: 'number',
	between: 'range',
	any_in: 'list',
	all_in: 'list',
	none_in: 'list',
	only_in: 'list',
};

function operatorCases() {
	const cases = [];
	for ( const [ op, shape ] of Object.entries( SHAPES ) ) {
		for ( const actual of ACTUALS ) {
			for ( const value of VALUES[ shape ] ) {
				cases.push( {
					op,
					actual,
					value,
					expect: applyOperator( op, actual, value ),
				} );
			}
		}
	}
	return cases;
}

// Rule states: absent, matched p5, matched p10, unmatched p10, matched p10 + unmatched p5,
// and a disabled rule that would match with p1 (must be ignored).
const STATES = [ 'absent', 'M5', 'M10', 'U10', 'M10+U5', 'D1' ];

function rulesFor( action, state, values, tag = '' ) {
	const make = ( suffix, priority, matched, enabled = true ) => {
		const id = `${ action }${ tag }_${ suffix }`;
		values[ `c.${ id }` ] = matched;
		return {
			id,
			enabled,
			priority,
			action,
			groups: [ [ { source: `c.${ id }`, operator: 'is_true' } ] ],
		};
	};
	switch ( state ) {
		case 'M5':
			return [ make( 'a', 5, true ) ];
		case 'M10':
			return [ make( 'a', 10, true ) ];
		case 'U10':
			return [ make( 'a', 10, false ) ];
		case 'M10+U5':
			return [ make( 'a', 10, true ), make( 'b', 5, false ) ];
		case 'D1':
			return [ make( 'a', 1, true, false ) ];
	}
	return [];
}

function resolverCases() {
	const cases = [];
	const getter = ( values ) => ( source ) =>
		Object.prototype.hasOwnProperty.call( values, source )
			? values[ source ]
			: null;

	for ( const show of STATES ) {
		for ( const hide of STATES ) {
			for ( const require of STATES ) {
				for ( const original of [ false, true ] ) {
					const values = {};
					const rules = [
						...rulesFor( 'show', show, values ),
						...rulesFor( 'hide', hide, values ),
						...rulesFor( 'require', require, values ),
					];
					cases.push( {
						kind: 'field',
						states: { show, hide, require },
						original_required: original,
						rules,
						values,
						expect: resolveField(
							rules,
							original,
							getter( values )
						),
					} );
				}
			}
		}
	}

	for ( const enable of STATES ) {
		for ( const disable of STATES ) {
			const values = {};
			const rules = [
				...rulesFor( 'enable', enable, values ),
				...rulesFor( 'disable', disable, values ),
			];
			cases.push( {
				kind: 'toggle',
				states: { enable, disable },
				rules,
				values,
				expect: resolveToggle( rules, getter( values ) ),
			} );
		}
	}

	for ( const a of STATES ) {
		for ( const b of STATES ) {
			const values = {};
			const rules = [
				...rulesFor( 'hide_tax_note', a, values, '1' ),
				...rulesFor( 'hide_tax_note', b, values, '2' ),
			];
			cases.push( {
				kind: 'flag',
				action: 'hide_tax_note',
				states: { a, b },
				rules,
				values,
				expect: resolveFlag( rules, 'hide_tax_note', getter( values ) ),
			} );
		}
	}
	return cases;
}

// AND/OR: every shape with up to 3 groups of up to 3 conditions, every truth assignment.
function groupCases() {
	const cases = [];
	const shapes = [ [] ];
	for ( let groups = 1; groups <= 3; groups++ ) {
		const sizes = [ [] ];
		for ( let g = 0; g < groups; g++ ) {
			const next = [];
			sizes.forEach( ( s ) =>
				[ 1, 2, 3 ].forEach( ( n ) => next.push( [ ...s, n ] ) )
			);
			sizes.splice( 0, sizes.length, ...next );
		}
		shapes.push( ...sizes );
	}
	for ( const shape of shapes ) {
		const total = shape.reduce( ( a, b ) => a + b, 0 );
		for ( let bits = 0; bits < 2 ** total; bits++ ) {
			const values = {};
			let k = 0;
			const groups = shape.map( ( size ) =>
				Array.from( { length: size }, () => {
					const source = `c.${ k }`;
					values[ source ] = Math.floor( bits / 2 ** k ) % 2 === 1;
					k++;
					return { source, operator: 'is_true' };
				} )
			);
			const rule = {
				id: 'g',
				enabled: true,
				priority: 10,
				action: 'show',
				groups,
			};
			cases.push( {
				groups,
				values,
				expect: matches( rule, ( s ) => values[ s ] ),
			} );
		}
	}
	return cases;
}

const built = {
	operators: operatorCases(),
	resolve: resolverCases(),
	groups: groupCases(),
};

if ( process.env.UPDATE_MATRIX ) {
	fs.writeFileSync( FILE, JSON.stringify( built ) + '\n' );
}

describe( 'parity matrix', () => {
	const stored = JSON.parse( fs.readFileSync( FILE, 'utf8' ) );

	it( 'covers every operator, action combination and group shape', () => {
		expect( stored.operators.length ).toBe( built.operators.length );
		expect( stored.resolve.length ).toBe( built.resolve.length );
		expect( stored.groups.length ).toBe( built.groups.length );
	} );

	it( 'JS evaluator matches the stored operator results', () => {
		expect( built.operators ).toEqual( stored.operators );
	} );

	it( 'JS resolver matches the stored resolver results', () => {
		expect( built.resolve ).toEqual( stored.resolve );
	} );

	it( 'JS AND/OR evaluation matches the stored results', () => {
		expect( built.groups ).toEqual( stored.groups );
	} );
} );
