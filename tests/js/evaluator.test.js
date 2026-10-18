/**
 * Shared PHP/JS evaluator fixtures (see tests/unit/FixtureCasesTest.php).
 */
import cases from '../fixtures/evaluator-cases.json';
import {
	applyOperator,
	resolveField,
	resolveToggle,
	resolveFlag,
} from '../../assets/src/shared/evaluator';

describe( 'operators', () => {
	cases.operators.forEach( ( c, i ) => {
		it( `#${ i } ${ c.op } ${ JSON.stringify( c.actual ?? null ) }`, () => {
			expect(
				applyOperator( c.op, c.actual ?? null, c.value ?? null )
			).toBe( c.expect );
		} );
	} );
} );

describe( 'resolver', () => {
	cases.resolve.forEach( ( c ) => {
		it( `${ c.name }`, () => {
			const rules = c.rules.map( ( r ) => ( { enabled: true, ...r } ) );
			const getValue = ( source ) =>
				Object.prototype.hasOwnProperty.call( c.values, source )
					? c.values[ source ]
					: null;

			let decision;
			if ( c.kind === 'field' ) {
				decision = resolveField( rules, c.original_required, getValue );
			} else if ( c.kind === 'toggle' ) {
				decision = resolveToggle( rules, getValue );
			} else {
				decision = resolveFlag( rules, c.action, getValue );
			}

			expect( decision ).toEqual( c.expect );
		} );
	} );
} );
