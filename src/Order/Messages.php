<?php
/**
 * Placeholders in order rule messages (SPEC §13.3).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Order;

defined( 'ABSPATH' ) || exit;

use CCFO\Engine\Context;
use CCFO\Engine\Operators;

/**
 * Fills {min}, {max}, {remaining}, {excess} and {current} from the conditions of
 * the group that matched:
 *
 * - {min} / {remaining}: the first "<" or "≤" condition: its limit, and limit − current value.
 * - {max} / {excess}:    the first ">" or "≥" condition: its limit, and current value − limit.
 * - {current}:           the current value of the first number comparison.
 *
 * A placeholder that has no such condition becomes empty. Pure apart from the
 * formatter, so it is unit tested without WordPress.
 */
final class Messages {

	private const LOWER = [ 'lt', 'lte' ];
	private const UPPER = [ 'gt', 'gte' ];
	private const ANY   = [ 'lt', 'lte', 'gt', 'gte', 'eq', 'neq', 'between' ];

	/**
	 * Replace the placeholders in a message.
	 *
	 * @param string                                                        $message    Message with placeholders.
	 * @param list<array{source: string, operator: string, value?: mixed}> $conditions Conditions of the matched group.
	 * @param Context                                                       $context    Context.
	 * @param callable(string, float): string                               $format     Formats a number of a source.
	 */
	public static function fill( string $message, array $conditions, Context $context, callable $format ): string {
		if ( ! str_contains( $message, '{' ) ) {
			return $message;
		}

		$min     = self::bound( $conditions, $context, self::LOWER );
		$max     = self::bound( $conditions, $context, self::UPPER );
		$current = self::bound( $conditions, $context, self::ANY );

		$values = [
			'{min}'       => null === $min ? '' : $format( $min['source'], $min['limit'] ),
			'{remaining}' => null === $min ? '' : $format( $min['source'], max( 0.0, $min['limit'] - $min['current'] ) ),
			'{max}'       => null === $max ? '' : $format( $max['source'], $max['limit'] ),
			'{excess}'    => null === $max ? '' : $format( $max['source'], max( 0.0, $max['current'] - $max['limit'] ) ),
			'{current}'   => null === $current ? '' : $format( $current['source'], $current['current'] ),
		];

		return strtr( $message, $values );
	}

	/**
	 * Placeholders a message uses.
	 *
	 * @param string $message Message.
	 * @return list<string>
	 */
	public static function placeholders( string $message ): array {
		preg_match_all( '/\{[a-z_]+\}/', $message, $matches );
		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Operators that give a value to a placeholder.
	 *
	 * @param string $placeholder Placeholder.
	 * @return list<string>
	 */
	public static function operators_for( string $placeholder ): array {
		switch ( $placeholder ) {
			case '{min}':
			case '{remaining}':
				return self::LOWER;
			case '{max}':
			case '{excess}':
				return self::UPPER;
			case '{current}':
				return self::ANY;
		}
		return [];
	}

	/**
	 * First number comparison with one of the operators whose current value is a number.
	 *
	 * @param list<array{source: string, operator: string, value?: mixed}> $conditions Conditions.
	 * @param Context                                                       $context    Context.
	 * @param list<string>                                                  $operators  Operators.
	 * @return array{source: string, limit: float, current: float}|null
	 */
	private static function bound( array $conditions, Context $context, array $operators ): ?array {
		foreach ( $conditions as $condition ) {
			if ( ! in_array( $condition['operator'], $operators, true ) ) {
				continue;
			}
			$current = Operators::number( $context->get( $condition['source'] ) );
			$value   = $condition['value'] ?? null;
			$limit   = Operators::number( is_array( $value ) ? ( $value[0] ?? null ) : $value );
			if ( null === $current || null === $limit ) {
				continue;
			}
			return [
				'source'  => $condition['source'],
				'limit'   => $limit,
				'current' => $current,
			];
		}
		return null;
	}
}
