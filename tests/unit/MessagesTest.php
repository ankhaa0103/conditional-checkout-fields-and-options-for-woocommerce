<?php
/**
 * Order rule message placeholders (SPEC §13.3) and the matching group.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Engine\Context;
use CCFO\Engine\Evaluator;
use CCFO\Order\Messages;
use CCFO\Rules\Rule;
use PHPUnit\Framework\TestCase;

/**
 * Messages::fill() with a plain formatter "<source>=<number>".
 */
final class MessagesTest extends TestCase {

	/**
	 * Formatter that shows which source and number were used.
	 *
	 * @param string $source Source.
	 * @param float  $value  Value.
	 */
	public static function format( string $source, float $value ): string {
		return $source . '=' . rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Condition.
	 *
	 * @param string $source   Source.
	 * @param string $operator Operator.
	 * @param mixed  $value    Value.
	 * @return array{source: string, operator: string, value?: mixed}
	 */
	private static function c( string $source, string $operator, mixed $value = null ): array {
		$c = [
			'source'   => $source,
			'operator' => $operator,
		];
		if ( null !== $value ) {
			$c['value'] = $value;
		}
		return $c;
	}

	/**
	 * Fill with a context.
	 *
	 * @param string               $message    Message.
	 * @param list<array<string, mixed>> $conditions Conditions.
	 * @param array<string, mixed> $values     Context values.
	 */
	private static function fill( string $message, array $conditions, array $values ): string {
		return Messages::fill( $message, $conditions, new Context( $values ), [ self::class, 'format' ] );
	}

	public function test_minimum_and_remaining_from_a_less_than_condition(): void {
		$message = self::fill(
			'Minimum {min}, add {remaining} more (now {current}).',
			[ self::c( 'cart.subtotal', 'lt', 400000 ) ],
			[ 'cart.subtotal' => 300000.0 ]
		);
		$this->assertSame( 'Minimum cart.subtotal=400000, add cart.subtotal=100000 more (now cart.subtotal=300000).', $message );
	}

	public function test_maximum_and_excess_from_a_greater_than_condition(): void {
		$message = self::fill(
			'Max {max}, remove {excess}.',
			[ self::c( 'cart.total', 'gte', 500000 ) ],
			[ 'cart.total' => 620000.5 ]
		);
		$this->assertSame( 'Max cart.total=500000, remove cart.total=120000.5.', $message );
	}

	public function test_remaining_and_excess_are_never_negative(): void {
		// A group can match through other conditions while a bound is already met.
		$values = [
			'cart.subtotal' => 500.0,
			'cart.total'    => 100.0,
		];
		$this->assertSame( 'cart.subtotal=0', self::fill( '{remaining}', [ self::c( 'cart.subtotal', 'lte', 400 ) ], $values ) );
		$this->assertSame( 'cart.total=0', self::fill( '{excess}', [ self::c( 'cart.total', 'gt', 200 ) ], $values ) );
	}

	public function test_first_matching_operator_in_condition_order_is_used(): void {
		$conditions = [
			self::c( 'customer.is_guest', 'is_true' ),
			self::c( 'cart.qty', 'gt', 1 ),
			self::c( 'cart.subtotal', 'lt', 1000 ),
			self::c( 'cart.total', 'lt', 5000 ),
			self::c( 'cart.weight', 'gte', 2 ),
		];
		$values     = [
			'customer.is_guest' => true,
			'cart.qty'          => 3,
			'cart.subtotal'     => 400.0,
			'cart.total'        => 450.0,
			'cart.weight'       => 7.5,
		];
		$this->assertSame(
			'cart.subtotal=1000|cart.subtotal=600|cart.qty=1|cart.qty=2|cart.qty=3',
			self::fill( '{min}|{remaining}|{max}|{excess}|{current}', $conditions, $values )
		);
	}

	public function test_between_and_equality_give_only_the_current_value(): void {
		$values = [ 'cart.subtotal' => 250.0 ];
		$this->assertSame( '||cart.subtotal=250', self::fill( '{min}|{max}|{current}', [ self::c( 'cart.subtotal', 'between', [ 100, 300 ] ) ], $values ) );
		$this->assertSame( 'cart.subtotal=250', self::fill( '{current}', [ self::c( 'cart.subtotal', 'neq', 1 ) ], $values ) );
	}

	public function test_placeholders_without_a_condition_become_empty(): void {
		$this->assertSame( 'Add  more.', self::fill( 'Add {remaining} more.', [], [] ) );
		$this->assertSame( 'Over .', self::fill( 'Over {max}.', [ self::c( 'cart.subtotal', 'lt', 10 ) ], [ 'cart.subtotal' => 5.0 ] ) );
	}

	public function test_non_numeric_current_values_are_skipped(): void {
		// A field that holds text cannot give a number; the next comparison is used.
		$conditions = [
			self::c( 'field:billing_company', 'lt', 5 ),
			self::c( 'cart.subtotal', 'lt', 100 ),
		];
		$values     = [
			'field:billing_company' => 'Acme',
			'cart.subtotal'         => 40.0,
		];
		$this->assertSame( 'cart.subtotal=60', self::fill( '{remaining}', $conditions, $values ) );
		// Numeric text in a field counts as a number.
		$values['field:billing_company'] = ' 2 ';
		$this->assertSame( 'field:billing_company=3', self::fill( '{remaining}', $conditions, $values ) );
	}

	public function test_unknown_placeholders_and_plain_messages_are_left_alone(): void {
		$this->assertSame( 'Hello {name} and {}', self::fill( 'Hello {name} and {}', [ self::c( 'cart.qty', 'lt', 3 ) ], [ 'cart.qty' => 1 ] ) );
		$this->assertSame( 'No braces here', self::fill( 'No braces here', [], [] ) );
		$this->assertSame( 'cart.qty=2 cart.qty=2', self::fill( '{remaining} {remaining}', [ self::c( 'cart.qty', 'lt', 3 ) ], [ 'cart.qty' => 1 ] ) );
	}

	public function test_exhaustive_bounds(): void {
		$count = 0;
		foreach ( [ 'lt', 'lte', 'gt', 'gte' ] as $operator ) {
			foreach ( [ 0, 1, 99.5, 100, 1000 ] as $limit ) {
				foreach ( [ 0, 0.01, 50, 100, 1500 ] as $current ) {
					++$count;
					$out    = self::fill( '{min}/{remaining}/{max}/{excess}/{current}', [ self::c( 'cart.total', $operator, $limit ) ], [ 'cart.total' => (float) $current ] );
					$parts  = explode( '/', $out );
					$num    = static fn ( string $p ): ?float => '' === $p ? null : (float) substr( $p, strlen( 'cart.total=' ) );
					$lower  = in_array( $operator, [ 'lt', 'lte' ], true );
					$expect = [
						$lower ? (float) $limit : null,
						$lower ? max( 0.0, $limit - $current ) : null,
						$lower ? null : (float) $limit,
						$lower ? null : max( 0.0, $current - $limit ),
						(float) $current,
					];
					foreach ( $expect as $i => $e ) {
						if ( null === $e ) {
							$this->assertSame( '', $parts[ $i ], "$operator $limit $current part $i" );
						} else {
							$this->assertEqualsWithDelta( $e, $num( $parts[ $i ] ), 0.005, "$operator $limit $current part $i" );
						}
					}
				}
			}
		}
		$this->assertSame( 100, $count );
	}

	public function test_placeholder_helpers(): void {
		$this->assertSame( [ '{min}', '{foo}', '{remaining}' ], Messages::placeholders( 'a {min} b {foo} {min} {remaining} {Bad} {}' ) );
		$this->assertSame( [ 'lt', 'lte' ], Messages::operators_for( '{remaining}' ) );
		$this->assertSame( [ 'gt', 'gte' ], Messages::operators_for( '{excess}' ) );
		$this->assertContains( 'between', Messages::operators_for( '{current}' ) );
		$this->assertSame( [], Messages::operators_for( '{foo}' ) );
	}

	public function test_matching_group_returns_the_first_group_that_holds(): void {
		$g1   = [ self::c( 'cart.qty', 'gt', 5 ) ];
		$g2   = [ self::c( 'cart.qty', 'gt', 1 ), self::c( 'customer.is_guest', 'is_true' ) ];
		$g3   = [ self::c( 'cart.qty', 'gte', 0 ) ];
		$rule = new Rule( 'r', 'r', true, 10, 'order', 'order', 'block_order', [], [ $g1, $g2, $g3 ] );

		$this->assertSame(
			$g2,
			Evaluator::matching_group(
				$rule,
				new Context(
					[
						'cart.qty'          => 3,
						'customer.is_guest' => true,
					]
				)
			)
		);
		$this->assertSame(
			$g3,
			Evaluator::matching_group(
				$rule,
				new Context(
					[
						'cart.qty'          => 3,
						'customer.is_guest' => false,
					]
				)
			)
		);
		$this->assertSame(
			$g1,
			Evaluator::matching_group(
				$rule,
				new Context(
					[
						'cart.qty'          => 9,
						'customer.is_guest' => true,
					]
				)
			)
		);
		$this->assertNull( Evaluator::matching_group( $rule, new Context( [ 'cart.qty' => -1 ] ) ) );
		$this->assertFalse( Evaluator::matches( $rule, new Context( [ 'cart.qty' => -1 ] ) ) );

		$always = new Rule( 'a', 'a', true, 10, 'order', 'order', 'block_order', [], [] );
		$this->assertSame( [], Evaluator::matching_group( $always, new Context() ) );
		$this->assertTrue( Evaluator::matches( $always, new Context() ) );
	}
}
