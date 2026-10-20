/**
 * Helpers for condition values typed as text.
 */

/**
 * Operators that compare one whole text, with their "one of" counterpart.
 *
 * `contains` has no list counterpart: several words need an OR group each.
 */
export const LIST_COUNTERPART = {
	equals: 'in',
	not_equals: 'not_in',
	contains: null,
	not_contains: null,
};

/**
 * Whether a one-value text looks like several values separated by commas.
 *
 * @param {*} value Value.
 * @return {boolean} Comma separated.
 */
export function looksLikeSeveral( value ) {
	return typeof value === 'string' && splitValues( value ).length > 1;
}

/**
 * Split comma separated text into trimmed, unique, non-empty values.
 *
 * @param {string} text Text.
 * @return {string[]} Values.
 */
export function splitValues( text ) {
	const out = [];
	String( text ?? '' )
		.split( ',' )
		.map( ( v ) => v.trim() )
		.filter( ( v ) => v !== '' )
		.forEach( ( v ) => {
			if ( ! out.includes( v ) ) {
				out.push( v );
			}
		} );
	return out;
}

/**
 * What to tell when a one-value text operator gets several values.
 *
 * @param {Object}   condition        Condition.
 * @param {string[]} allowedOperators Operators of the condition's source.
 * @return {{switchTo: string|null}|null} Hint, or null when none is needed.
 */
export function commaHint( condition, allowedOperators ) {
	if (
		! Object.prototype.hasOwnProperty.call(
			LIST_COUNTERPART,
			condition.operator
		) ||
		! looksLikeSeveral( condition.value )
	) {
		return null;
	}
	const target = LIST_COUNTERPART[ condition.operator ];
	return {
		switchTo:
			target && ( allowedOperators || [] ).includes( target )
				? target
				: null,
	};
}

/**
 * The condition switched to the "one of" operator with the values split.
 *
 * @param {Object} condition Condition.
 * @param {string} operator  List operator.
 * @return {Object} Condition.
 */
export function toListCondition( condition, operator ) {
	return {
		source: condition.source,
		operator,
		value: splitValues( condition.value ),
	};
}
