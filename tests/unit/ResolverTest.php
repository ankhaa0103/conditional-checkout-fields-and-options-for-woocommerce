<?php
/**
 * Resolver behaviour not covered by the shared fixtures.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Engine\Context;
use CCFO\Engine\Resolver;
use CCFO\Rules\Rule;
use CCFO\Rules\RuleIndex;
use PHPUnit\Framework\TestCase;

/**
 * Resolver, Context and RuleIndex.
 */
final class ResolverTest extends TestCase {

	/**
	 * Build an enabled rule.
	 *
	 * @param string                           $id       Id.
	 * @param string                           $type     Target type.
	 * @param string                           $key      Target key.
	 * @param string                           $action   Action.
	 * @param int                              $priority Priority.
	 * @param list<list<array<string, mixed>>> $groups   Groups.
	 * @param array<string, mixed>             $args     Args.
	 */
	private function rule( string $id, string $type, string $key, string $action, int $priority = 10, array $groups = [], array $args = [] ): Rule {
		return new Rule( $id, '', true, $priority, $type, $key, $action, $args, $groups );
	}

	public function test_context_resolves_lazily_once(): void {
		$calls   = 0;
		$context = new Context(
			[ 'a' => 1 ],
			static function ( string $source ) use ( &$calls ) {
				++$calls;
				return $source . '!';
			}
		);

		$this->assertSame( 1, $context->get( 'a' ) );
		$this->assertSame( 'b!', $context->get( 'b' ) );
		$this->assertSame( 'b!', $context->get( 'b' ) );
		$this->assertSame( 1, $calls );
		$this->assertSame(
			[
				'a' => 1,
				'b' => 'b!',
			],
			$context->snapshot( [ 'b', 'a' ] )
		);
	}

	public function test_rate_rules_match_instance_and_method(): void {
		$index = new RuleIndex(
			[
				$this->rule( 'i', 'shipping', 'flat_rate:3', 'disable', 20 ),
				$this->rule( 'm', 'shipping', 'flat_rate', 'enable', 5 ),
				$this->rule( 'o', 'shipping', 'flat_rate:4', 'disable' ),
			]
		);

		$rules = $index->for_rate( 'flat_rate:3', 'flat_rate' );
		$this->assertSame( [ 'i', 'm' ], array_map( static fn ( Rule $r ): string => $r->id, $rules ) );

		$decision = Resolver::toggle( $rules, new Context() );
		$this->assertTrue( $decision->active );
		$this->assertSame(
			[
				'winner'     => 'm',
				'overridden' => [ 'i' ],
			],
			$decision->conflict
		);
	}

	public function test_index_sources_and_disabled_rules(): void {
		$disabled = new Rule(
			'd',
			'',
			false,
			10,
			'payment',
			'cod',
			'disable',
			[],
			[
				[
					[
						'source'   => 'cart.qty',
						'operator' => 'gt',
						'value'    => 1,
					],
				],
			]
		);
		$index    = new RuleIndex(
			[
				$disabled,
				$this->rule(
					'a',
					'payment',
					'bacs',
					'disable',
					10,
					[
						[
							[
								'source'   => 'cart.total',
								'operator' => 'gt',
								'value'    => 1,
							],
						],
					]
				),
				$this->rule(
					'b',
					'field',
					'billing_company',
					'show',
					10,
					[
						[
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'DE',
							],
						],
					]
				),
			],
			7
		);

		$this->assertSame( 7, $index->revision );
		$this->assertCount( 3, $index->all() );
		$this->assertSame( [], $index->for_target( 'payment', 'cod' ) );
		$this->assertSame( [ 'bacs' ], $index->keys( 'payment' ) );
		$this->assertSame( [ 'cart.total' ], $index->sources( [ 'payment' ] ) );
		$this->assertSame( [ 'billing.country', 'cart.total' ], $index->sources( [ 'payment', 'field' ] ) );
		$this->assertFalse( $index->has( 'shipping' ) );
	}

	public function test_pick_returns_best_matching_rule(): void {
		$rules = [
			$this->rule( 'low', 'tax_calc', 'cart', 'set_tax_class', 20, [], [ 'tax_class' => 'a' ] ),
			$this->rule(
				'high',
				'tax_calc',
				'cart',
				'set_tax_class',
				1,
				[
					[
						[
							'source'   => 'cart.qty',
							'operator' => 'gt',
							'value'    => 5,
						],
					],
				],
				[ 'tax_class' => 'b' ]
			),
		];

		$this->assertSame( 'low', Resolver::pick( $rules, 'set_tax_class', new Context( [ 'cart.qty' => 1 ] ) )?->id );
		$this->assertSame( 'high', Resolver::pick( $rules, 'set_tax_class', new Context( [ 'cart.qty' => 9 ] ) )?->id );
		$this->assertNull( Resolver::pick( $rules, 'set_vat_exempt', new Context() ) );
	}

	public function test_field_changed_flag(): void {
		$require = [ $this->rule( 'r', 'field', 'x', 'require' ) ];
		$this->assertTrue( Resolver::field( $require, false, new Context() )->changed );
		$this->assertFalse( Resolver::field( $require, true, new Context() )->changed );
		$this->assertFalse( Resolver::field( [], true, new Context() )->changed );
	}
}
