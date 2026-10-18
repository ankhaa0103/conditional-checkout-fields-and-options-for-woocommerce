<?php
/**
 * Shared PHP/JS evaluator fixtures.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Engine\Context;
use CCFO\Engine\Operators;
use CCFO\Engine\Resolver;
use CCFO\Rules\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs tests/fixtures/evaluator-cases.json, which the Jest suite runs too.
 */
final class FixtureCasesTest extends TestCase {

	/**
	 * Fixture data.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixtures(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/evaluator-cases.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Operator cases.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function operator_cases(): iterable {
		foreach ( self::fixtures()['operators'] as $i => $case ) {
			yield sprintf( '#%d %s %s', $i, $case['op'], (string) json_encode( $case['actual'] ?? null, JSON_UNESCAPED_UNICODE ) ) => [ $case ];
		}
	}

	/**
	 * Resolver cases.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function resolve_cases(): iterable {
		foreach ( self::fixtures()['resolve'] as $case ) {
			yield $case['name'] => [ $case ];
		}
	}

	/**
	 * Operators.
	 *
	 * @param array<string, mixed> $c Case.
	 */
	#[DataProvider( 'operator_cases' )]
	public function test_operator( array $c ): void {
		$this->assertSame( $c['expect'], Operators::apply( $c['op'], $c['actual'] ?? null, $c['value'] ?? null ) );
	}

	/**
	 * Resolver.
	 *
	 * @param array<string, mixed> $c Case.
	 */
	#[DataProvider( 'resolve_cases' )]
	public function test_resolve( array $c ): void {
		$rules   = array_map(
			static fn ( array $r ): Rule => new Rule( $r['id'], '', $r['enabled'] ?? true, $r['priority'], 'field', 'x', $r['action'], [], $r['groups'] ),
			$c['rules']
		);
		$context = new Context( $c['values'] );

		$decision = match ( $c['kind'] ) {
			'field'  => Resolver::field( $rules, $c['original_required'], $context ),
			'toggle' => Resolver::toggle( $rules, $context ),
			'flag'   => Resolver::flag( $rules, $c['action'], $context ),
		};

		$this->assertSame( $c['expect'], $decision->to_array() );
	}
}
