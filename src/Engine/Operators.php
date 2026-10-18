<?php
/**
 * Condition operators.
 *
 * Mirrored in assets/src/shared/evaluator.js; both are checked against
 * tests/fixtures/evaluator-cases.json, so change them together.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

/**
 * Pure operator functions with fixed coercion rules.
 *
 * - Text comparison trims and ignores case.
 * - Numbers must be numeric; a non-numeric value fails every numeric test.
 * - Lists are compared item by item as lower-cased text.
 */
final class Operators {

	private const FALSY = [ '', '0', 'false', 'no', 'off' ];

	/**
	 * Apply an operator.
	 *
	 * @param string $operator Operator name.
	 * @param mixed  $actual   Value from the context.
	 * @param mixed  $expected Value from the rule.
	 */
	public static function apply( string $operator, mixed $actual, mixed $expected = null ): bool {
		switch ( $operator ) {
			case 'equals':
				return self::text( $actual ) === self::text( $expected );
			case 'not_equals':
				return self::text( $actual ) !== self::text( $expected );
			case 'contains':
				return self::contains( $actual, $expected );
			case 'not_contains':
				return ! self::contains( $actual, $expected );
			case 'in':
				return in_array( self::text( $actual ), self::list( $expected ), true );
			case 'not_in':
				return ! in_array( self::text( $actual ), self::list( $expected ), true );
			case 'empty':
				return self::is_empty( $actual );
			case 'not_empty':
				return ! self::is_empty( $actual );
			case 'checked':
			case 'is_true':
				return self::truthy( $actual );
			case 'unchecked':
			case 'is_false':
				return ! self::truthy( $actual );
			case 'eq':
				return self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => abs( $a - $b ) < 1e-9 );
			case 'neq':
				return ! self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => abs( $a - $b ) < 1e-9 );
			case 'gt':
				return self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => $a > $b );
			case 'gte':
				return self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => $a >= $b );
			case 'lt':
				return self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => $a < $b );
			case 'lte':
				return self::compare( $actual, $expected, static fn ( float $a, float $b ): bool => $a <= $b );
			case 'between':
				return self::between( $actual, $expected );
			case 'any_in':
				return [] !== array_intersect( self::list( $actual ), self::list( $expected ) );
			case 'all_in':
				return [] === array_diff( self::list( $expected ), self::list( $actual ) );
			case 'none_in':
				return [] === array_intersect( self::list( $actual ), self::list( $expected ) );
			case 'only_in':
				$items = self::list( $actual );
				return [] !== $items && [] === array_diff( $items, self::list( $expected ) );
		}

		return false;
	}

	/**
	 * Normalised text.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( [ self::class, 'text' ], $value ) );
		}
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 1e15 ) {
			return (string) (int) $value;
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_strtolower( trim( (string) $value ) );
	}

	/**
	 * Normalised list of text items.
	 *
	 * @param mixed $value Scalar or list.
	 * @return list<string>
	 */
	public static function list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$text = self::text( $value );
			return '' === $text ? [] : [ $text ];
		}

		$items = [];
		foreach ( $value as $item ) {
			$text = self::text( $item );
			if ( '' !== $text ) {
				$items[] = $text;
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * Strict number or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function number( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
			return (float) trim( $value );
		}
		return null;
	}

	/**
	 * Whether a value counts as checked/true.
	 *
	 * @param mixed $value Value.
	 */
	private static function truthy( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return [] !== self::list( $value );
		}
		return ! in_array( self::text( $value ), self::FALSY, true );
	}

	/**
	 * Whether a value is empty.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_empty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return [] === self::list( $value );
		}
		return '' === self::text( $value );
	}

	/**
	 * Substring test. An empty needle never matches.
	 *
	 * @param mixed $haystack Haystack.
	 * @param mixed $needle   Needle.
	 */
	private static function contains( mixed $haystack, mixed $needle ): bool {
		$needle = self::text( $needle );
		return '' !== $needle && str_contains( self::text( $haystack ), $needle );
	}

	/**
	 * Numeric comparison; false when either side is not a number.
	 *
	 * @param mixed    $actual   Actual.
	 * @param mixed    $expected Expected.
	 * @param callable $compare  Comparison.
	 */
	private static function compare( mixed $actual, mixed $expected, callable $compare ): bool {
		$a = self::number( $actual );
		$b = self::number( $expected );
		return null !== $a && null !== $b && $compare( $a, $b );
	}

	/**
	 * Inclusive range test.
	 *
	 * @param mixed $actual   Actual.
	 * @param mixed $expected [min, max].
	 */
	private static function between( mixed $actual, mixed $expected ): bool {
		if ( ! is_array( $expected ) || 2 !== count( $expected ) ) {
			return false;
		}
		$expected = array_values( $expected );
		$a        = self::number( $actual );
		$min      = self::number( $expected[0] );
		$max      = self::number( $expected[1] );
		return null !== $a && null !== $min && null !== $max && $a >= $min && $a <= $max;
	}
}
