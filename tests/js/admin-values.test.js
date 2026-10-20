/**
 * Admin helpers for condition values typed as text.
 */
import {
	commaHint,
	looksLikeSeveral,
	splitValues,
	toListCondition,
} from '../../assets/src/admin/values';

const TEXT_OPERATORS = [
	'equals',
	'not_equals',
	'contains',
	'not_contains',
	'in',
	'not_in',
	'empty',
	'not_empty',
];

describe( 'condition values typed as text', () => {
	test( 'splits, trims and removes empty and repeated values', () => {
		expect( splitValues( 'ewq,ttwet,tewt' ) ).toEqual( [
			'ewq',
			'ttwet',
			'tewt',
		] );
		expect( splitValues( ' a , ,b,a, ' ) ).toEqual( [ 'a', 'b' ] );
		expect( splitValues( '' ) ).toEqual( [] );
		expect( splitValues( undefined ) ).toEqual( [] );
	} );

	test( 'only text with two or more values counts as several', () => {
		expect( looksLikeSeveral( 'a,b' ) ).toBe( true );
		expect( looksLikeSeveral( 'a,' ) ).toBe( false );
		expect( looksLikeSeveral( 'Acme, Inc.' ) ).toBe( true );
		expect( looksLikeSeveral( 'abc' ) ).toBe( false );
		expect( looksLikeSeveral( [ 'a', 'b' ] ) ).toBe( false );
		expect( looksLikeSeveral( 5 ) ).toBe( false );
	} );

	test( 'hint per operator', () => {
		const c = ( operator, value = 'a,b' ) => ( {
			source: 'field:billing_company',
			operator,
			value,
		} );
		expect( commaHint( c( 'equals' ), TEXT_OPERATORS ) ).toEqual( {
			switchTo: 'in',
		} );
		expect( commaHint( c( 'not_equals' ), TEXT_OPERATORS ) ).toEqual( {
			switchTo: 'not_in',
		} );
		expect( commaHint( c( 'contains' ), TEXT_OPERATORS ) ).toEqual( {
			switchTo: null,
		} );
		expect( commaHint( c( 'not_contains' ), TEXT_OPERATORS ) ).toEqual( {
			switchTo: null,
		} );
		// No hint: one value, list operators, numbers.
		expect( commaHint( c( 'equals', 'abc' ), TEXT_OPERATORS ) ).toBeNull();
		expect(
			commaHint( c( 'in', [ 'a', 'b' ] ), TEXT_OPERATORS )
		).toBeNull();
		expect( commaHint( c( 'gt', '1,5' ), TEXT_OPERATORS ) ).toBeNull();
		// The source does not offer "is one of": hint without a switch.
		expect( commaHint( c( 'equals' ), [ 'equals' ] ) ).toEqual( {
			switchTo: null,
		} );
	} );

	test( 'switching keeps the source and splits the value', () => {
		expect(
			toListCondition(
				{
					source: 'billing.postcode',
					operator: 'equals',
					value: '14200, 14210',
				},
				'in'
			)
		).toEqual( {
			source: 'billing.postcode',
			operator: 'in',
			value: [ '14200', '14210' ],
		} );
	} );
} );
