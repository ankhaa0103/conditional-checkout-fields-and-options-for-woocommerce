<?php
/**
 * Order rules and amount sources: schema and linter (SPEC §13).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Engine\Operators;
use CCFO\Rules\Linter;
use CCFO\Rules\Rule;
use CCFO\Rules\RuleSchema;
use PHPUnit\Framework\TestCase;

/**
 * Pure checks without WordPress.
 */
final class OrderRulesTest extends TestCase {

	/**
	 * Raw order rule.
	 *
	 * @param array<string, mixed> $override Overrides.
	 * @return array<string, mixed>
	 */
	private static function raw( array $override = [] ): array {
		return array_replace(
			[
				'id'       => 'min_order',
				'priority' => 10,
				'target'   => [
					'type' => 'order',
					'key'  => 'order',
				],
				'action'   => 'block_checkout',
				'args'     => [ 'message' => 'Add {remaining} more.' ],
				'groups'   => [
					[
						[
							'source'   => 'cart.subtotal',
							'operator' => 'lt',
							'value'    => 50000,
						],
					],
				],
			],
			$override
		);
	}

	/**
	 * Rule object.
	 *
	 * @param string                           $action Action.
	 * @param list<list<array<string, mixed>>> $groups Groups.
	 * @param array<string, mixed>             $args   Args.
	 * @param string                           $type   Target type.
	 */
	private static function rule( string $action, array $groups, array $args = [], string $type = 'order' ): Rule {
		$keys = [
			'order'   => 'order',
			'payment' => 'cod',
			'field'   => 'billing_company',
		];
		return new Rule( 'r', 'r', true, 10, $type, $keys[ $type ], $action, $args, $groups );
	}

	/**
	 * Finding codes.
	 *
	 * @param Rule                 $rule Rule.
	 * @param array<string, mixed> $env  Environment.
	 * @return list<string>
	 */
	private static function codes( Rule $rule, array $env = [] ): array {
		return array_column( ( new Linter() )->lint( [ $rule ], $env ), 'code' );
	}

	public function test_valid_order_rules_and_their_message(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate(
			[
				self::raw(),
				self::raw(
					[
						'id'     => 'max',
						'action' => 'block_order',
					]
				),
			]
		);
		$this->assertSame( [], $schema->errors() );
		$this->assertCount( 2, $rules );
		$this->assertSame( 'order', $rules[0]->target_key );
		$this->assertSame( [ 'message' => 'Add {remaining} more.' ], $rules[0]->args );
		$this->assertSame( 'block_order', $rules[1]->action );
	}

	public function test_message_is_optional_cleaned_and_limited(): void {
		$schema = new RuleSchema();

		$rule = $schema->validate( [ self::raw( [ 'args' => [] ] ) ] )[0];
		$this->assertSame( [ 'message' => '' ], $rule->args );
		$rule = $schema->validate( [ self::raw( [ 'args' => null ] ) ] )[0];
		$this->assertSame( [ 'message' => '' ], $rule->args );

		$rule = $schema->validate( [ self::raw( [ 'args' => [ 'message' => "  <b>Min</b> {min}\n<script>x</script> " ] ] ) ] )[0];
		$this->assertSame( 'Min {min}', $rule->args['message'] );

		$rule = $schema->validate( [ self::raw( [ 'args' => [ 'message' => str_repeat( 'é', 500 ) ] ] ) ] )[0];
		$this->assertSame( 500, mb_strlen( $rule->args['message'] ) );

		foreach ( [ str_repeat( 'a', 501 ), [ 'x' ], 12, true ] as $bad ) {
			$this->assertSame( [], $schema->validate( [ self::raw( [ 'args' => [ 'message' => $bad ] ] ) ] ), 'message ' . json_encode( $bad ) );
			$this->assertSame( 'args.message', $schema->errors()[0]['path'] );
		}
	}

	public function test_message_is_dropped_for_other_actions(): void {
		$schema = new RuleSchema();
		$rule   = $schema->validate(
			[
				self::raw(
					[
						'target' => [
							'type' => 'payment',
							'key'  => 'cod',
						],
						'action' => 'disable',
					]
				),
			]
		)[0];
		$this->assertSame( [], $rule->args );
	}

	public function test_order_actions_only_on_the_order_target(): void {
		$schema = new RuleSchema();
		$this->assertSame(
			[],
			$schema->validate(
				[
					self::raw(
						[
							'target' => [
								'type' => 'payment',
								'key'  => 'cod',
							],
						]
					),
				]
			)
		);
		$this->assertSame( [], $schema->validate( [ self::raw( [ 'action' => 'disable' ] ) ] ) );
		// The fixed key is forced.
		$rule = $schema->validate(
			[
				self::raw(
					[
						'target' => [
							'type' => 'order',
							'key'  => 'whatever',
						],
					]
				),
			]
		)[0];
		$this->assertSame( 'order', $rule->target_key );
	}

	public function test_category_subtotal_source_ids(): void {
		$schema = new RuleSchema();
		foreach ( [
			'cart.category_subtotal:7'                    => true,
			'cart.category_subtotal:12345678901234567890' => true,
			'cart.category_subtotal:0'                    => false,
			'cart.category_subtotal:07'                   => false,
			'cart.category_subtotal:'                     => false,
			'cart.category_subtotal:abc'                  => false,
			'cart.category_subtotal:1:2'                  => false,
			'cart.category_subtotal'                      => false,
		] as $source => $ok ) {
			$raw = self::raw(
				[
					'groups' => [
						[
							[
								'source'   => $source,
								'operator' => 'gte',
								'value'    => 100,
							],
						],
					],
				]
			);
			$this->assertCount( $ok ? 1 : 0, $schema->validate( [ $raw ] ), $source );
		}
	}

	public function test_two_step_sources_without_their_second_step(): void {
		$schema = new RuleSchema();
		foreach ( [
			'field:'                  => 'Choose a checkout field.',
			'cart.category_subtotal:' => 'Choose a product category.',
			'cart.category_subtotal'  => 'Choose a product category.',
			'cart.nope'               => 'Unknown condition source.',
		] as $source => $message ) {
			$raw = self::raw(
				[
					'groups' => [
						[
							[
								'source'   => $source,
								'operator' => 'not_empty',
							],
						],
					],
				]
			);
			$this->assertSame( [], $schema->validate( [ $raw ] ), $source );
			$this->assertSame( $message, $schema->errors()[0]['message'], $source );
			$this->assertSame( 'groups.0.0.source', $schema->errors()[0]['path'], $source );
		}
	}

	public function test_coupon_codes_are_a_list(): void {
		$schema = new RuleSchema();
		$raw    = self::raw(
			[
				'groups' => [
					[
						[
							'source'   => 'cart.coupons',
							'operator' => 'any_in',
							'value'    => [ 'BigSale', 'vip-10' ],
						],
					],
				],
			]
		);
		$this->assertCount( 1, $schema->validate( [ $raw ] ) );
		$raw['groups'][0][0]['operator'] = 'equals';
		$this->assertCount( 0, $schema->validate( [ $raw ] ) );
		// Coupon codes are compared like other lists: case-insensitive.
		$this->assertTrue( Operators::apply( 'any_in', [ 'bigsale' ], [ 'BigSale' ] ) );
	}

	public function test_rule_without_conditions_blocks_everyone(): void {
		$this->assertContains( 'always_blocks', self::codes( self::rule( 'block_checkout', [] ) ) );
		$this->assertContains( 'always_blocks', self::codes( self::rule( 'block_order', [] ) ) );
		$findings = ( new Linter() )->lint( [ self::rule( 'block_order', [] ) ], [] );
		$this->assertSame( 'warning', $findings[0]['level'] );
		$this->assertStringContainsString( 'place an order', $findings[0]['message'] );
	}

	public function test_placeholders_need_matching_conditions(): void {
		$lt = [
			[
				[
					'source'   => 'cart.subtotal',
					'operator' => 'lt',
					'value'    => 100,
				],
			],
		];
		$gt = [
			[
				[
					'source'   => 'cart.total',
					'operator' => 'gt',
					'value'    => 100,
				],
			],
		];

		$this->assertSame( [], self::codes( self::rule( 'block_checkout', $lt, [ 'message' => 'Add {remaining}, minimum {min}, now {current}.' ] ) ) );
		$this->assertSame( [], self::codes( self::rule( 'block_order', $gt, [ 'message' => 'Max {max}, over by {excess}.' ] ) ) );
		$this->assertSame( [ 'empty_placeholder', 'empty_placeholder' ], self::codes( self::rule( 'block_order', $gt, [ 'message' => '{min} {remaining}' ] ) ) );
		$this->assertSame( [ 'empty_placeholder' ], self::codes( self::rule( 'block_order', $lt, [ 'message' => '{excess}' ] ) ) );
		$this->assertSame( [ 'unknown_placeholder' ], self::codes( self::rule( 'block_order', $lt, [ 'message' => 'Hi {name}' ] ) ) );
		// No message: the default message is used, nothing to check here.
		$this->assertSame( [], self::codes( self::rule( 'block_order', $gt, [ 'message' => '' ] ) ) );
		// Messages of other targets are not checked.
		$this->assertSame( [], self::codes( self::rule( 'disable', $gt, [ 'message' => '{foo}' ], 'payment' ) ) );
	}

	public function test_missing_category_and_missing_fields_on_order_rules(): void {
		$rule = self::rule(
			'block_order',
			[
				[
					[
						'source'   => 'cart.category_subtotal:15',
						'operator' => 'gt',
						'value'    => 1,
					],
					[
						'source'   => 'field:billing_gone',
						'operator' => 'not_empty',
					],
				],
			]
		);
		$env  = [
			'categories' => [ '3', '4' ],
			'fields'     => [ 'billing_company' ],
		];
		$this->assertEqualsCanonicalizing( [ 'missing_category', 'missing_source_field' ], self::codes( $rule, $env ) );

		$env['categories'][] = '15';
		$env['fields'][]     = 'billing_gone';
		$this->assertSame( [], self::codes( $rule, $env ) );
	}

	/**
	 * Every pair and triple of number conditions on one source: the linter reports
	 * "never matches" exactly when no number satisfies all of them.
	 */
	public function test_never_matching_groups_against_brute_force(): void {
		$conditions = [];
		foreach ( [ 'gt', 'gte', 'lt', 'lte', 'eq' ] as $operator ) {
			foreach ( [ 0, 5, 10 ] as $value ) {
				$conditions[] = [
					'source'   => 'cart.total',
					'operator' => $operator,
					'value'    => $value,
				];
			}
		}
		foreach ( [ [ 0, 5 ], [ 5, 5 ], [ 5, 10 ] ] as $range ) {
			$conditions[] = [
				'source'   => 'cart.total',
				'operator' => 'between',
				'value'    => $range,
			];
		}

		// Every number that can tell the ranges apart.
		$grid = [];
		for ( $x = -2.0; $x <= 12.0; $x += 0.25 ) {
			$grid[] = $x;
		}

		$satisfiable = static function ( array $group ) use ( $grid ): bool {
			foreach ( $grid as $x ) {
				$all = true;
				foreach ( $group as $c ) {
					if ( ! Operators::apply( $c['operator'], $x, $c['value'] ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return true;
				}
			}
			return false;
		};

		$groups = [];
		foreach ( $conditions as $a ) {
			foreach ( $conditions as $b ) {
				$groups[] = [ $a, $b ];
			}
		}
		foreach ( $conditions as $i => $a ) {
			foreach ( $conditions as $j => $b ) {
				foreach ( $conditions as $k => $c ) {
					if ( $i < $j && $j < $k ) {
						$groups[] = [ $a, $b, $c ];
					}
				}
			}
		}

		$failures = [];
		foreach ( $groups as $group ) {
			$reported = in_array( 'never_matches', self::codes( self::rule( 'block_order', [ $group ] ) ), true );
			if ( $reported === $satisfiable( $group ) ) {
				$failures[] = json_encode( $group ) . ( $reported ? ' reported but satisfiable' : ' not reported' );
			}
		}

		// 18 conditions: 18 × 18 pairs and C(18, 3) triples.
		$this->assertSame( 18 * 18 + 816, count( $groups ) );
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 20 ) ) );
	}

	public function test_contradictions_are_per_source_and_per_group(): void {
		$lt = [
			'source'   => 'cart.total',
			'operator' => 'lt',
			'value'    => 100,
		];
		$gt = [
			'source'   => 'cart.subtotal',
			'operator' => 'gt',
			'value'    => 500,
		];
		// Different sources never contradict.
		$this->assertNotContains( 'never_matches', self::codes( self::rule( 'block_order', [ [ $lt, $gt ] ] ) ) );

		// The second group is impossible; the finding names it.
		$gt['source'] = 'cart.total';
		$findings     = ( new Linter() )->lint( [ self::rule( 'block_order', [ [ $lt ], [ $lt, $gt ] ] ) ], [] );
		$this->assertSame( [ 'never_matches' ], array_column( $findings, 'code' ) );
		$this->assertStringContainsString( 'group 2', $findings[0]['message'] );
		$this->assertStringContainsString( 'cart.total', $findings[0]['message'] );

		// Field and payment rules are checked too.
		$this->assertContains( 'never_matches', self::codes( self::rule( 'disable', [ [ $lt, $gt ] ], [], 'payment' ) ) );
	}
}
