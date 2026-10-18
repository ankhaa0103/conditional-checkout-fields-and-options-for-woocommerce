<?php
/**
 * Rule schema validation.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Rules\RuleSchema;
use PHPUnit\Framework\TestCase;

/**
 * RuleSchema.
 */
final class RuleSchemaTest extends TestCase {

	/**
	 * A valid rule with overrides.
	 *
	 * @param array<string, mixed> $override Overrides.
	 * @return array<string, mixed>
	 */
	private function rule( array $override = [] ): array {
		return array_replace(
			[
				'id'       => 'r1',
				'title'    => 'Hide COD',
				'enabled'  => true,
				'priority' => 10,
				'target'   => [
					'type' => 'payment',
					'key'  => 'cod',
				],
				'action'   => 'disable',
				'groups'   => [
					[
						[
							'source'   => 'cart.total',
							'operator' => 'gt',
							'value'    => '500000',
						],
					],
				],
			],
			$override
		);
	}

	/**
	 * Error paths.
	 *
	 * @param RuleSchema $schema Schema.
	 * @return list<string>
	 */
	private function paths( RuleSchema $schema ): array {
		return array_map( static fn ( array $e ): string => $e['path'], $schema->errors() );
	}

	public function test_valid_rule_is_normalised(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate( [ $this->rule() ] );

		$this->assertSame( [], $schema->errors() );
		$this->assertCount( 1, $rules );
		$this->assertSame( 500000, $rules[0]->groups[0][0]['value'] );
		$this->assertSame( [ 'cart.total' ], $rules[0]->sources() );
	}

	public function test_not_a_list(): void {
		$schema = new RuleSchema();
		$this->assertSame( [], $schema->validate( [ 'a' => $this->rule() ] ) );
		$this->assertSame( [ 'rules' ], $this->paths( $schema ) );
	}

	public function test_circular_source_is_rejected(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate(
			[
				$this->rule(
					[
						'groups' => [
							[
								[
									'source'   => 'payment.method',
									'operator' => 'equals',
									'value'    => 'cod',
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [], $rules );
		$this->assertSame( [ 'groups.0.0.source' ], $this->paths( $schema ) );
	}

	public function test_shipping_rule_cannot_use_cart_total(): void {
		$schema = new RuleSchema();
		$schema->validate(
			[
				$this->rule(
					[
						'target' => [
							'type' => 'shipping',
							'key'  => 'flat_rate:3',
						],
					]
				),
			]
		);
		$this->assertSame( [ 'groups.0.0.source' ], $this->paths( $schema ) );
	}

	public function test_field_rule_cannot_depend_on_itself(): void {
		$schema = new RuleSchema();
		$schema->validate(
			[
				$this->rule(
					[
						'target' => [
							'type' => 'field',
							'key'  => 'billing_company',
						],
						'action' => 'show',
						'groups' => [
							[
								[
									'source'   => 'field:billing_company',
									'operator' => 'not_empty',
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [ 'groups.0.0.source' ], $this->paths( $schema ) );
	}

	public function test_operator_must_match_source_type(): void {
		$schema = new RuleSchema();
		$schema->validate(
			[
				$this->rule(
					[
						'groups' => [
							[
								[
									'source'   => 'cart.total',
									'operator' => 'contains',
									'value'    => '1',
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [ 'groups.0.0.operator' ], $this->paths( $schema ) );
	}

	public function test_values_are_checked_per_operator(): void {
		$schema = new RuleSchema();
		$schema->validate(
			[
				$this->rule(
					[
						'groups' => [
							[
								[
									'source'   => 'cart.total',
									'operator' => 'gt',
									'value'    => 'abc',
								],
							],
						],
					]
				),
				$this->rule(
					[
						'id'     => 'r2',
						'groups' => [
							[
								[
									'source'   => 'cart.total',
									'operator' => 'between',
									'value'    => [ 5, 1 ],
								],
							],
						],
					]
				),
				$this->rule(
					[
						'id'     => 'r3',
						'groups' => [
							[
								[
									'source'   => 'cart.categories',
									'operator' => 'any_in',
									'value'    => [],
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [ 'groups.0.0.value', 'groups.0.0.value', 'groups.0.0.value' ], $this->paths( $schema ) );
	}

	public function test_action_must_fit_target(): void {
		$schema = new RuleSchema();
		$schema->validate( [ $this->rule( [ 'action' => 'show' ] ) ] );
		$this->assertSame( [ 'action' ], $this->paths( $schema ) );
	}

	public function test_shipping_keys(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate(
			[
				$this->rule(
					[
						'target' => [
							'type' => 'shipping',
							'key'  => 'flat_rate:3',
						],
						'groups' => [],
					]
				),
				$this->rule(
					[
						'id'     => 'r2',
						'target' => [
							'type' => 'shipping',
							'key'  => 'flat rate',
						],
						'groups' => [],
					]
				),
			]
		);
		$this->assertCount( 1, $rules );
		$this->assertSame( [ 'target.key' ], $this->paths( $schema ) );
	}

	public function test_duplicate_ids(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate( [ $this->rule(), $this->rule() ] );
		$this->assertCount( 1, $rules );
		$this->assertSame( [ 'id' ], $this->paths( $schema ) );
	}

	public function test_tax_class_must_exist(): void {
		$tax_rule = $this->rule(
			[
				'target' => [ 'type' => 'tax_calc' ],
				'action' => 'set_tax_class',
				'args'   => [ 'tax_class' => 'zero-rate' ],
				'groups' => [],
			]
		);

		$schema = new RuleSchema();
		$this->assertSame( [], $schema->validate( [ $tax_rule ], [ 'tax_classes' => [ '', 'reduced-rate' ] ] ) );

		$rules = $schema->validate( [ $tax_rule ], [ 'tax_classes' => [ '', 'zero-rate' ] ] );
		$this->assertSame( 'cart', $rules[0]->target_key );
		$this->assertSame( [ 'tax_class' => 'zero-rate' ], $rules[0]->args );
	}

	public function test_title_is_cleaned(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate( [ $this->rule( [ 'title' => '<b>Bold</b><script>x</script>' ] ) ] );
		$this->assertSame( 'Bold', $rules[0]->title );
	}

	public function test_product_and_term_conditions_need_ids(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate(
			[
				$this->rule(
					[
						'groups' => [
							[
								[
									'source'   => 'cart.products',
									'operator' => 'any_in',
									'value'    => [ '529', 'undefined' ],
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [], $rules );
		$this->assertSame( [ 'groups.0.0.value' ], $this->paths( $schema ) );
	}

	public function test_list_values_become_unique_strings(): void {
		$schema = new RuleSchema();
		$rules  = $schema->validate(
			[
				$this->rule(
					[
						'groups' => [
							[
								[
									'source'   => 'cart.categories',
									'operator' => 'any_in',
									'value'    => [ 23, '23', 40 ],
								],
							],
						],
					]
				),
			]
		);
		$this->assertSame( [ '23', '40' ], $rules[0]->groups[0][0]['value'] );
	}
}
