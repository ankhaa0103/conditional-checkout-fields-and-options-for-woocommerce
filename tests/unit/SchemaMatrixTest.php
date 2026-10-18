<?php
/**
 * Every target × source × operator × value shape against SPEC §3–§5.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Rules\Definitions;
use CCFO\Rules\RuleSchema;
use PHPUnit\Framework\TestCase;

/**
 * The expected tables below are copied from docs/SPEC.md, not from the code.
 */
final class SchemaMatrixTest extends TestCase {

	private const TARGETS = [ 'field', 'payment', 'shipping', 'tax_display', 'tax_calc' ];

	/**
	 * SPEC §2/§3: actions per target.
	 */
	private const SPEC_ACTIONS = [
		'field'       => [ 'show', 'hide', 'require' ],
		'payment'     => [ 'enable', 'disable' ],
		'shipping'    => [ 'enable', 'disable' ],
		'tax_display' => [ 'hide_tax_note' ],
		'tax_calc'    => [ 'set_vat_exempt', 'set_tax_class' ],
	];

	/**
	 * SPEC §4: source → [field, payment, shipping, tax]. "tax" covers tax_display and tax_calc.
	 */
	private const SPEC_SOURCES = [
		'field:billing_company' => [ 1, 1, 1, 1 ],
		'cart.subtotal'         => [ 1, 1, 1, 1 ],
		'cart.total'            => [ 1, 1, 0, 0 ],
		'cart.item_count'       => [ 1, 1, 1, 1 ],
		'cart.qty'              => [ 1, 1, 1, 1 ],
		'cart.weight'           => [ 1, 1, 1, 1 ],
		'cart.products'         => [ 1, 1, 1, 1 ],
		'cart.categories'       => [ 1, 1, 1, 1 ],
		'cart.tags'             => [ 1, 1, 1, 1 ],
		'cart.shipping_classes' => [ 1, 1, 1, 1 ],
		'payment.method'        => [ 1, 0, 0, 1 ],
		'shipping.method'       => [ 1, 1, 0, 1 ],
		'customer.is_guest'     => [ 1, 1, 1, 1 ],
		'customer.role'         => [ 1, 1, 1, 1 ],
		'billing.country'       => [ 1, 1, 1, 1 ],
		'billing.state'         => [ 1, 1, 1, 1 ],
		'billing.postcode'      => [ 1, 1, 1, 1 ],
		'shipping.country'      => [ 1, 1, 1, 1 ],
		'shipping.state'        => [ 1, 1, 1, 1 ],
		'shipping.postcode'     => [ 1, 1, 1, 1 ],
		'tax.vat_exempt'        => [ 1, 1, 0, 0 ],
		'tax.has_tax'           => [ 1, 1, 0, 0 ],
	];

	/**
	 * SPEC §5: operators per value kind.
	 */
	private const SPEC_OPERATORS = [
		'string' => [ 'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty' ],
		'number' => [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between' ],
		'list'   => [ 'any_in', 'all_in', 'none_in', 'only_in', 'empty', 'not_empty' ],
		'bool'   => [ 'is_true', 'is_false' ],
		// A checkout field holds text, a number or a checkbox state.
		'field'  => [ 'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty', 'checked', 'unchecked', 'gt', 'gte', 'lt', 'lte' ],
	];

	/**
	 * SPEC §4: value kind of each source.
	 */
	private const SPEC_KINDS = [
		'field:billing_company' => 'field',
		'cart.subtotal'         => 'number',
		'cart.total'            => 'number',
		'cart.item_count'       => 'number',
		'cart.qty'              => 'number',
		'cart.weight'           => 'number',
		'cart.products'         => 'list',
		'cart.categories'       => 'list',
		'cart.tags'             => 'list',
		'cart.shipping_classes' => 'list',
		'payment.method'        => 'string',
		'shipping.method'       => 'list',
		'customer.is_guest'     => 'bool',
		'customer.role'         => 'list',
		'billing.country'       => 'string',
		'billing.state'         => 'string',
		'billing.postcode'      => 'string',
		'shipping.country'      => 'string',
		'shipping.state'        => 'string',
		'shipping.postcode'     => 'string',
		'tax.vat_exempt'        => 'bool',
		'tax.has_tax'           => 'bool',
	];

	private const ALL_OPERATORS = [ 'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty', 'checked', 'unchecked', 'is_true', 'is_false', 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'any_in', 'all_in', 'none_in', 'only_in' ];

	/**
	 * A valid value for an operator and source.
	 *
	 * @param string $operator Operator.
	 * @param string $source   Source.
	 * @return array{0: bool, 1: mixed} [has value, value]
	 */
	private static function valid_value( string $operator, string $source ): array {
		$ids = in_array( $source, [ 'cart.products', 'cart.categories', 'cart.tags', 'cart.shipping_classes' ], true );
		switch ( Definitions::OPERATOR_VALUE[ $operator ] ) {
			case 'none':
				return [ false, null ];
			case 'scalar':
				return [ true, 'DE' ];
			case 'number':
				return [ true, 100 ];
			case 'range':
				return [ true, [ 1, 5 ] ];
			default:
				return [ true, $ids ? [ '12', '34' ] : [ 'a', 'b' ] ];
		}
	}

	/**
	 * Raw rule.
	 *
	 * @param string               $target    Target type.
	 * @param array<string, mixed> $condition Condition.
	 * @param string               $action    Action.
	 * @return array<string, mixed>
	 */
	private static function rule( string $target, array $condition, string $action = '' ): array {
		$keys = [
			'field'    => 'billing_vat',
			'payment'  => 'cod',
			'shipping' => 'flat_rate:3',
		];
		return [
			'id'       => 'r',
			'priority' => 10,
			'target'   => [
				'type' => $target,
				'key'  => $keys[ $target ] ?? '',
			],
			'action'   => '' !== $action ? $action : self::SPEC_ACTIONS[ $target ][0],
			'args'     => 'set_tax_class' === ( '' !== $action ? $action : self::SPEC_ACTIONS[ $target ][0] ) ? [ 'tax_class' => '' ] : [],
			'groups'   => [ [ $condition ] ],
		];
	}

	public function test_code_definitions_equal_the_spec_tables(): void {
		$this->assertSame( self::SPEC_ACTIONS, Definitions::ACTIONS );

		$sources = Definitions::sources();
		foreach ( self::SPEC_SOURCES as $source => $allowed ) {
			$definition = Definitions::source_definition( $source );
			$this->assertNotNull( $definition, $source );
			$expected = [];
			foreach ( [ 'field', 'payment', 'shipping' ] as $i => $target ) {
				if ( $allowed[ $i ] ) {
					$expected[] = $target;
				}
			}
			if ( $allowed[3] ) {
				$expected[] = 'tax_display';
				$expected[] = 'tax_calc';
			}
			$actual = $definition['targets'];
			sort( $expected );
			sort( $actual );
			$this->assertSame( $expected, $actual, "targets of $source" );
			$this->assertSame( self::SPEC_KINDS[ $source ], $definition['type'], "kind of $source" );
		}
		// No source in the code that the spec does not know.
		$this->assertSame( [], array_diff( array_keys( $sources ), array_keys( self::SPEC_SOURCES ) ) );

		foreach ( self::SPEC_OPERATORS as $kind => $operators ) {
			$this->assertEqualsCanonicalizing( $operators, Definitions::OPERATORS[ $kind ], "operators of $kind" );
		}
	}

	public function test_every_target_source_operator_combination(): void {
		$schema   = new RuleSchema();
		$failures = [];
		$count    = 0;

		foreach ( self::TARGETS as $t => $target ) {
			foreach ( self::SPEC_SOURCES as $source => $allowed ) {
				$source_allowed = 1 === $allowed[ min( $t, 3 ) ];
				foreach ( self::ALL_OPERATORS as $operator ) {
					++$count;
					[ $has_value, $value ] = self::valid_value( $operator, $source );
					$condition             = [
						'source'   => $source,
						'operator' => $operator,
					];
					if ( $has_value ) {
						$condition['value'] = $value;
					}

					$should_pass = $source_allowed && in_array( $operator, self::SPEC_OPERATORS[ self::SPEC_KINDS[ $source ] ], true );
					$rules       = $schema->validate( [ self::rule( $target, $condition ) ] );
					$passed      = 1 === count( $rules ) && [] === $schema->errors();

					if ( $passed !== $should_pass ) {
						$failures[] = "$target / $source / $operator: expected " . ( $should_pass ? 'valid' : 'invalid' ) . ', got ' . ( $passed ? 'valid' : json_encode( $schema->errors() ) );
					}
				}
			}
		}

		$this->assertSame( 5 * 22 * 23, $count );
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}

	public function test_every_action_on_every_target(): void {
		$schema   = new RuleSchema();
		$actions  = array_merge( ...array_values( self::SPEC_ACTIONS ) );
		$failures = [];
		foreach ( self::TARGETS as $target ) {
			foreach ( $actions as $action ) {
				$rule           = self::rule(
					$target,
					[
						'source'   => 'customer.is_guest',
						'operator' => 'is_true',
					],
					$action
				);
				$rule['groups'] = [];
				$passed         = 1 === count( $schema->validate( [ $rule ] ) );
				$should_pass    = in_array( $action, self::SPEC_ACTIONS[ $target ], true );
				if ( $passed !== $should_pass ) {
					$failures[] = "$target / $action";
				}
			}
		}
		$this->assertSame( [], $failures );
	}

	public function test_invalid_values_for_every_allowed_source_and_operator(): void {
		$invalid  = [
			'scalar' => [ 'missing', '', '   ', [ 'a' ], true, null ],
			'number' => [ 'missing', 'abc', '', [ 1 ], true, null ],
			'range'  => [
				'missing',
				[ 5, 1 ],
				[ 1 ],
				[ 1, 2, 3 ],
				[ 'a', 2 ],
				'x',
				[
					'min' => 1,
					'max' => 2,
				],
			],
			'list'   => [ 'missing', [], 'a', [ '' ], [ [ 'a' ] ], [ true ] ],
		];
		$schema   = new RuleSchema();
		$failures = [];
		$count    = 0;

		foreach ( self::SPEC_SOURCES as $source => $allowed ) {
			foreach ( self::SPEC_OPERATORS[ self::SPEC_KINDS[ $source ] ] as $operator ) {
				$shape = Definitions::OPERATOR_VALUE[ $operator ];
				if ( 'none' === $shape ) {
					continue;
				}
				$cases = $invalid[ $shape ];
				if ( 'list' === $shape && in_array( $source, [ 'cart.products', 'cart.categories', 'cart.tags', 'cart.shipping_classes' ], true ) ) {
					$cases[] = [ 'abc' ];
					$cases[] = [ '0' ];
					$cases[] = [ '12', 'undefined' ];
				}
				foreach ( $cases as $value ) {
					++$count;
					$condition = [
						'source'   => $source,
						'operator' => $operator,
					];
					if ( 'missing' !== $value ) {
						$condition['value'] = $value;
					}
					if ( [] !== $schema->validate( [ self::rule( 'field', $condition ) ] ) ) {
						$failures[] = "$source / $operator accepted " . json_encode( $value );
					}
				}
			}
		}
		$this->assertGreaterThan( 400, $count );
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}

	public function test_target_keys(): void {
		$cases  = [
			[ 'field', 'billing_vat', true ],
			[ 'field', 'my-plugin[field]', true ],
			[ 'field', 'bad key', false ],
			[ 'field', '', false ],
			[ 'payment', 'cod', true ],
			[ 'payment', 'stripe_cc-2', true ],
			[ 'payment', 'cod:1', false ],
			[ 'shipping', 'flat_rate', true ],
			[ 'shipping', 'flat_rate:12', true ],
			[ 'shipping', 'flat_rate:x', false ],
			[ 'shipping', 'flat_rate:1:2', false ],
		];
		$schema = new RuleSchema();
		foreach ( $cases as [ $target, $key, $ok ] ) {
			$rule                  = self::rule(
				$target,
				[
					'source'   => 'customer.is_guest',
					'operator' => 'is_true',
				]
			);
			$rule['target']['key'] = $key;
			$this->assertCount( $ok ? 1 : 0, $schema->validate( [ $rule ] ), "$target key '$key'" );
		}

		// Fixed keys are forced for tax targets whatever is sent.
		foreach ( [
			'tax_display' => 'checkout',
			'tax_calc'    => 'cart',
		] as $target => $fixed ) {
			$rule                  = self::rule(
				$target,
				[
					'source'   => 'customer.is_guest',
					'operator' => 'is_true',
				]
			);
			$rule['target']['key'] = 'anything';
			$this->assertSame( $fixed, $schema->validate( [ $rule ] )[0]->target_key );
		}
	}
}
