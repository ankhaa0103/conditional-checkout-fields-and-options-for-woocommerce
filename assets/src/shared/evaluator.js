/**
 * Rule evaluator for the browser.
 *
 * Mirrors src/Engine/Operators.php, Evaluator.php and Resolver.php. Both
 * implementations run tests/fixtures/evaluator-cases.json, so change them
 * together. The server stays authoritative; this only updates the UI early.
 */

const FALSY = [ '', '0', 'false', 'no', 'off' ];

/**
 * Normalised text: trimmed, lower-cased.
 *
 * @param {*} value Value.
 * @return {string} Text.
 */
export function text( value ) {
	if ( value === null || value === undefined || value === false ) {
		return '';
	}
	if ( value === true ) {
		return '1';
	}
	if ( Array.isArray( value ) ) {
		return value.map( text ).join( ', ' );
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? String( value ) : '';
	}
	if ( typeof value !== 'string' ) {
		return '';
	}
	return value.trim().toLowerCase();
}

/**
 * Normalised list of unique, non-empty text items.
 *
 * @param {*} value Scalar or list.
 * @return {string[]} Items.
 */
export function list( value ) {
	if ( ! Array.isArray( value ) ) {
		const t = text( value );
		return t === '' ? [] : [ t ];
	}
	const out = [];
	for ( const item of value ) {
		const t = text( item );
		if ( t !== '' && ! out.includes( t ) ) {
			out.push( t );
		}
	}
	return out;
}

/**
 * Strict number or null. Matches PHP is_numeric for plain decimal input.
 *
 * @param {*} value Value.
 * @return {number|null} Number.
 */
export function number( value ) {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? value : null;
	}
	if ( typeof value === 'string' ) {
		const t = value.trim();
		if ( /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test( t ) ) {
			return parseFloat( t );
		}
	}
	return null;
}

function truthy( value ) {
	if ( Array.isArray( value ) ) {
		return list( value ).length > 0;
	}
	return ! FALSY.includes( text( value ) );
}

function isEmpty( value ) {
	if ( Array.isArray( value ) ) {
		return list( value ).length === 0;
	}
	return text( value ) === '';
}

function contains( haystack, needle ) {
	const n = text( needle );
	return n !== '' && text( haystack ).includes( n );
}

function compare( actual, expected, fn ) {
	const a = number( actual );
	const b = number( expected );
	return a !== null && b !== null && fn( a, b );
}

const EPSILON = 1e-9;

/**
 * Apply an operator.
 *
 * @param {string} operator Operator.
 * @param {*}      actual   Context value.
 * @param {*}      expected Rule value.
 * @return {boolean} Result.
 */
export function applyOperator( operator, actual, expected ) {
	switch ( operator ) {
		case 'equals':
			return text( actual ) === text( expected );
		case 'not_equals':
			return text( actual ) !== text( expected );
		case 'contains':
			return contains( actual, expected );
		case 'not_contains':
			return ! contains( actual, expected );
		case 'in':
			return list( expected ).includes( text( actual ) );
		case 'not_in':
			return ! list( expected ).includes( text( actual ) );
		case 'empty':
			return isEmpty( actual );
		case 'not_empty':
			return ! isEmpty( actual );
		case 'checked':
		case 'is_true':
			return truthy( actual );
		case 'unchecked':
		case 'is_false':
			return ! truthy( actual );
		case 'eq':
			return compare(
				actual,
				expected,
				( a, b ) => Math.abs( a - b ) < EPSILON
			);
		case 'neq':
			return ! compare(
				actual,
				expected,
				( a, b ) => Math.abs( a - b ) < EPSILON
			);
		case 'gt':
			return compare( actual, expected, ( a, b ) => a > b );
		case 'gte':
			return compare( actual, expected, ( a, b ) => a >= b );
		case 'lt':
			return compare( actual, expected, ( a, b ) => a < b );
		case 'lte':
			return compare( actual, expected, ( a, b ) => a <= b );
		case 'between': {
			if ( ! Array.isArray( expected ) || expected.length !== 2 ) {
				return false;
			}
			const a = number( actual );
			const min = number( expected[ 0 ] );
			const max = number( expected[ 1 ] );
			return (
				a !== null &&
				min !== null &&
				max !== null &&
				a >= min &&
				a <= max
			);
		}
		case 'any_in': {
			const e = list( expected );
			return list( actual ).some( ( item ) => e.includes( item ) );
		}
		case 'all_in': {
			const a = list( actual );
			return list( expected ).every( ( item ) => a.includes( item ) );
		}
		case 'none_in': {
			const e = list( expected );
			return ! list( actual ).some( ( item ) => e.includes( item ) );
		}
		case 'only_in': {
			const a = list( actual );
			const e = list( expected );
			return a.length > 0 && a.every( ( item ) => e.includes( item ) );
		}
	}
	return false;
}

/**
 * Whether a rule's conditions hold. AND inside a group, OR between groups.
 *
 * @param {Object}   rule     Rule.
 * @param {Function} getValue Source id → value.
 * @return {boolean} Match.
 */
export function matches( rule, getValue ) {
	if ( ! rule.groups || rule.groups.length === 0 ) {
		return true;
	}
	return rule.groups.some( ( group ) =>
		group.every( ( c ) =>
			applyOperator( c.operator, getValue( c.source ), c.value )
		)
	);
}

function ordered( rules ) {
	return rules
		.map( ( rule, index ) => ( { rule, index } ) )
		.filter( ( { rule } ) => rule.enabled !== false )
		.sort(
			( a, b ) => a.rule.priority - b.rule.priority || a.index - b.index
		)
		.map( ( { rule } ) => rule );
}

function resolve( rules, getValue, positive, negative, exclusive ) {
	const matchedPositive = [];
	const matchedNegative = [];
	const exclusiveRules = [];

	for ( const rule of ordered( rules ) ) {
		if ( rule.action === exclusive ) {
			exclusiveRules.push( rule.id );
		}
		const isPositive = positive.includes( rule.action );
		const isNegative = negative.includes( rule.action );
		if ( ! ( isPositive || isNegative ) || ! matches( rule, getValue ) ) {
			continue;
		}
		( isPositive ? matchedPositive : matchedNegative ).push( rule );
	}

	const ids = ( l ) => l.map( ( r ) => r.id );

	if ( matchedNegative.length && matchedPositive.length ) {
		const negativeWins =
			matchedNegative[ 0 ].priority <= matchedPositive[ 0 ].priority;
		const winners = negativeWins ? matchedNegative : matchedPositive;
		const losers = negativeWins ? matchedPositive : matchedNegative;
		return {
			active: ! negativeWins,
			required: false,
			applied: ids( winners ),
			conflict: { winner: winners[ 0 ].id, overridden: ids( losers ) },
		};
	}
	if ( matchedNegative.length ) {
		return {
			active: false,
			required: false,
			applied: ids( matchedNegative ),
			conflict: null,
		};
	}
	if ( matchedPositive.length ) {
		return {
			active: true,
			required: false,
			applied: ids( matchedPositive ),
			conflict: null,
		};
	}
	if ( exclusiveRules.length ) {
		return {
			active: false,
			required: false,
			applied: exclusiveRules,
			conflict: null,
		};
	}
	return { active: true, required: false, applied: [], conflict: null };
}

/**
 * Field visibility and required state.
 *
 * @param {Object[]} rules            Rules targeting the field.
 * @param {boolean}  originalRequired Required state before our rules.
 * @param {Function} getValue         Source id → value.
 * @return {{active: boolean, required: boolean, applied: string[], conflict: Object|null}} Decision.
 */
export function resolveField( rules, originalRequired, getValue ) {
	const d = resolve(
		rules,
		getValue,
		[ 'show', 'require' ],
		[ 'hide' ],
		'show'
	);
	if ( ! d.active ) {
		return { ...d, required: false };
	}
	const requiredByRule = rules.some(
		( r ) => r.action === 'require' && d.applied.includes( r.id )
	);
	return { ...d, required: requiredByRule || !! originalRequired };
}

/**
 * Payment gateway / shipping rate availability.
 *
 * @param {Object[]} rules    Rules targeting the method.
 * @param {Function} getValue Source id → value.
 * @return {Object} Decision.
 */
export function resolveToggle( rules, getValue ) {
	return resolve( rules, getValue, [ 'enable' ], [ 'disable' ], 'enable' );
}

/**
 * Flag that is on when any rule with the action matches.
 *
 * @param {Object[]} rules    Rules.
 * @param {string}   action   Action.
 * @param {Function} getValue Source id → value.
 * @return {Object} Decision.
 */
export function resolveFlag( rules, action, getValue ) {
	const applied = ordered( rules )
		.filter( ( r ) => r.action === action && matches( r, getValue ) )
		.map( ( r ) => r.id );
	return {
		active: applied.length > 0,
		required: false,
		applied,
		conflict: null,
	};
}
