<?php
/**
 * Linter findings.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Rules\Linter;
use CCFO\Rules\Rule;
use PHPUnit\Framework\TestCase;

/**
 * Linter.
 */
final class LinterTest extends TestCase {

	/**
	 * Build a rule.
	 *
	 * @param string                           $id       Id.
	 * @param string                           $type     Target type.
	 * @param string                           $key      Key.
	 * @param string                           $action   Action.
	 * @param int                              $priority Priority.
	 * @param list<list<array<string, mixed>>> $groups   Groups.
	 */
	private function rule( string $id, string $type, string $key, string $action, int $priority = 10, array $groups = [
		[
			[
				'source'   => 'cart.qty',
				'operator' => 'gt',
				'value'    => 1,
			],
		],
	] ): Rule {
		return new Rule( $id, $id, true, $priority, $type, $key, $action, [], $groups );
	}

	/**
	 * Codes per rule.
	 *
	 * @param list<array{rule: string, level: string, code: string, message: string}> $findings Findings.
	 * @return array<string, list<string>>
	 */
	private function codes( array $findings ): array {
		$out = [];
		foreach ( $findings as $f ) {
			$out[ $f['rule'] ][] = $f['code'];
		}
		return $out;
	}

	public function test_a10_conflict_reported_on_both_rules(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule( 's', 'field', 'billing_company', 'show' ),
				$this->rule( 'h', 'field', 'billing_company', 'hide' ),
			],
			[ 'fields' => [ 'billing_company' ] ]
		);

		$codes = $this->codes( $findings );
		$this->assertSame( [ 'conflict' ], $codes['s'] );
		$this->assertSame( [ 'conflict' ], $codes['h'] );
		$this->assertStringContainsString( '"h" wins', $findings[0]['message'] );
	}

	public function test_priority_decides_conflict_winner_text(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule( 'e', 'payment', 'cod', 'enable', 1 ),
				$this->rule( 'd', 'payment', 'cod', 'disable', 5 ),
			],
			[]
		);
		$this->assertStringContainsString( '"e" wins', $findings[0]['message'] );
	}

	public function test_different_shipping_instances_do_not_conflict(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule( 'a', 'shipping', 'flat_rate:1', 'disable' ),
				$this->rule( 'b', 'shipping', 'flat_rate:2', 'enable' ),
			],
			[]
		);
		$this->assertSame( [], $findings );
	}

	public function test_method_rule_conflicts_with_instance_rule(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule( 'a', 'shipping', 'flat_rate', 'disable' ),
				$this->rule( 'b', 'shipping', 'flat_rate:2', 'enable' ),
			],
			[]
		);
		$this->assertSame( [ 'conflict', 'conflict' ], array_column( $findings, 'code' ) );
	}

	public function test_missing_targets_and_condition_fields(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule(
					'f',
					'field',
					'billing_gone',
					'hide',
					10,
					[
						[
							[
								'source'   => 'field:also_gone',
								'operator' => 'not_empty',
							],
						],
					]
				),
				$this->rule( 'p', 'payment', 'stripe', 'disable' ),
			],
			[
				'fields'   => [ 'billing_company' ],
				'gateways' => [ 'cod' ],
			]
		);
		$codes    = $this->codes( $findings );
		$this->assertSame( [ 'missing_target', 'missing_source_field' ], $codes['f'] );
		$this->assertSame( [ 'missing_target' ], $codes['p'] );
	}

	public function test_all_payment_methods_can_be_removed(): void {
		$findings = ( new Linter() )->lint(
			[
				$this->rule( 'a', 'payment', 'cod', 'disable' ),
				$this->rule( 'b', 'payment', 'bacs', 'enable' ),
			],
			[ 'gateways' => [ 'cod', 'bacs' ] ]
		);
		$codes    = $this->codes( $findings );
		$this->assertContains( 'can_remove_all', $codes['a'] );
		$this->assertContains( 'can_remove_all', $codes['b'] );

		$findings = ( new Linter() )->lint( [ $this->rule( 'a', 'payment', 'cod', 'disable' ) ], [ 'gateways' => [ 'cod', 'bacs' ] ] );
		$this->assertSame( [], $findings );
	}

	public function test_all_shipping_instances_covered_by_method_rules(): void {
		$findings = ( new Linter() )->lint(
			[ $this->rule( 'a', 'shipping', 'flat_rate', 'disable' ) ],
			[ 'shipping' => [ 'flat_rate', 'flat_rate:1', 'flat_rate:2' ] ]
		);
		$this->assertSame( [ 'can_remove_all' ], array_column( $findings, 'code' ) );
	}

	public function test_tax_rules_without_effect(): void {
		$rules = [
			$this->rule( 'note', 'tax_display', 'checkout', 'hide_tax_note' ),
			$this->rule( 'calc', 'tax_calc', 'cart', 'set_vat_exempt' ),
		];

		$codes = $this->codes( ( new Linter() )->lint( $rules, [ 'tax_enabled' => false ] ) );
		$this->assertSame( [ 'no_tax_note' ], $codes['note'] );
		$this->assertSame( [ 'taxes_disabled' ], $codes['calc'] );

		$codes = $this->codes(
			( new Linter() )->lint(
				$rules,
				[
					'tax_enabled'   => true,
					'tax_inclusive' => false,
				]
			)
		);
		$this->assertSame( [ 'no_tax_note' ], $codes['note'] );
		$this->assertArrayNotHasKey( 'calc', $codes );

		$this->assertSame(
			[],
			( new Linter() )->lint(
				$rules,
				[
					'tax_enabled'   => true,
					'tax_inclusive' => true,
				]
			)
		);
	}

	public function test_block_checkout_support_notes(): void {
		$env   = [
			'block_checkout' => true,
			'block_fields'   => [ 'billing_company' ],
		];
		$codes = $this->codes(
			( new Linter() )->lint(
				[
					$this->rule( 'r', 'field', 'billing_company', 'require' ),
					$this->rule( 's', 'field', 'billing_company', 'show', 10, [] ),
					$this->rule( 'v', 'field', 'billing_vat', 'require' ),
				],
				$env
			)
		);
		$this->assertSame( [ 'block_submit_only' ], $codes['r'] );
		$this->assertSame( [ 'block_unsupported' ], $codes['s'] );
		$this->assertSame( [ 'block_unsupported' ], $codes['v'] );
	}

	public function test_critical_and_unconditional_rules(): void {
		$codes = $this->codes( ( new Linter() )->lint( [ $this->rule( 'e', 'field', 'billing_email', 'hide', 10, [] ) ], [] ) );
		$this->assertSame( [ 'critical_field', 'unconditional' ], $codes['e'] );
	}

	public function test_disabled_rules_are_ignored(): void {
		$disabled = new Rule( 'x', 'x', false, 10, 'payment', 'missing', 'disable', [], [] );
		$this->assertSame( [], ( new Linter() )->lint( [ $disabled ], [ 'gateways' => [ 'cod' ] ] ) );
	}
}
