<?php
/**
 * Exhaustive PHP/JS parity and SPEC oracle (tests/fixtures/matrix.json, built by tests/js/matrix.test.js).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\unit;

use CCFO\Engine\Context;
use CCFO\Engine\Evaluator;
use CCFO\Engine\Operators;
use CCFO\Engine\Resolver;
use CCFO\Rules\Rule;
use PHPUnit\Framework\TestCase;

/**
 * Every operator/value pair, every action combination and every AND/OR shape.
 */
final class MatrixTest extends TestCase {

	/**
	 * Matrix data.
	 *
	 * @var array<string, list<array<string, mixed>>>|null
	 */
	private static ?array $matrix = null;

	/**
	 * Load the matrix once.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function matrix(): array {
		if ( null === self::$matrix ) {
			self::$matrix = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/matrix.json' ), true, 512, JSON_THROW_ON_ERROR );
		}
		return self::$matrix;
	}

	/**
	 * Rule objects from matrix rule arrays.
	 *
	 * @param list<array<string, mixed>> $rules Rules.
	 * @return list<Rule>
	 */
	private static function rules( array $rules ): array {
		return array_map(
			static fn ( array $r ): Rule => new Rule( $r['id'], '', $r['enabled'] ?? true, $r['priority'], 'field', 'x', $r['action'], [], $r['groups'] ),
			$rules
		);
	}

	public function test_matrix_is_complete(): void {
		$m = self::matrix();
		$this->assertCount( 4290, $m['operators'] );
		$this->assertCount( 504, $m['resolve'] );
		$this->assertCount( 2955, $m['groups'] );

		$ops = array_unique( array_column( $m['operators'], 'op' ) );
		$this->assertCount( 23, $ops );
	}

	public function test_every_operator_case_matches_js(): void {
		$failures = [];
		foreach ( self::matrix()['operators'] as $i => $c ) {
			$php = Operators::apply( $c['op'], $c['actual'], $c['value'] );
			if ( $php !== $c['expect'] ) {
				$failures[] = sprintf( '#%d %s(%s, %s): php=%s js=%s', $i, $c['op'], json_encode( $c['actual'], JSON_UNESCAPED_UNICODE ), json_encode( $c['value'], JSON_UNESCAPED_UNICODE ), var_export( $php, true ), var_export( $c['expect'], true ) );
			}
		}
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}

	public function test_every_group_shape_matches_js_and_and_or_semantics(): void {
		$failures = [];
		foreach ( self::matrix()['groups'] as $i => $c ) {
			$rule   = new Rule( 'g', '', true, 10, 'field', 'x', 'show', [], $c['groups'] );
			$php    = Evaluator::matches( $rule, new Context( $c['values'] ) );
			$oracle = [] === $c['groups'];
			foreach ( $c['groups'] as $group ) {
				$all = true;
				foreach ( $group as $condition ) {
					$all = $all && $c['values'][ $condition['source'] ];
				}
				$oracle = $oracle || $all;
			}
			if ( $php !== $c['expect'] || $php !== $oracle ) {
				$failures[] = "#$i php=" . var_export( $php, true ) . ' js=' . var_export( $c['expect'], true ) . ' oracle=' . var_export( $oracle, true );
			}
		}
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}

	public function test_every_action_combination_matches_js(): void {
		$failures = [];
		foreach ( self::matrix()['resolve'] as $i => $c ) {
			$rules   = self::rules( $c['rules'] );
			$context = new Context( $c['values'] );
			$php     = match ( $c['kind'] ) {
				'field'  => Resolver::field( $rules, $c['original_required'], $context ),
				'toggle' => Resolver::toggle( $rules, $context ),
				'flag'   => Resolver::flag( $rules, $c['action'], $context ),
			};
			if ( $php->to_array() !== $c['expect'] ) {
				$failures[] = "#$i " . json_encode( $c['states'] ) . ' php=' . json_encode( $php->to_array() ) . ' js=' . json_encode( $c['expect'] );
			}
		}
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}

	/**
	 * Oracle written from SPEC §3 (action meaning) and §8 (conflict policy),
	 * independent of the Resolver implementation.
	 */
	public function test_every_action_combination_follows_the_spec(): void {
		$failures = [];
		foreach ( self::matrix()['resolve'] as $i => $c ) {
			$rules_o = self::rules( $c['rules'] );
			$ctx     = new Context( $c['values'] );
			$php     = match ( $c['kind'] ) {
				'field'  => Resolver::field( $rules_o, $c['original_required'], $ctx ),
				'toggle' => Resolver::toggle( $rules_o, $ctx ),
				'flag'   => Resolver::flag( $rules_o, $c['action'], $ctx ),
			};
			$matched = static fn ( array $r ): bool => ( $r['enabled'] ?? true ) && true === $c['values'][ $r['groups'][0][0]['source'] ];
			$enabled = array_values( array_filter( $c['rules'], static fn ( array $r ): bool => $r['enabled'] ?? true ) );
			$best    = static function ( array $rules ): int {
				return [] === $rules ? PHP_INT_MAX : min( array_column( $rules, 'priority' ) );
			};
			$of      = static fn ( array $actions ): array => array_values( array_filter( $c['rules'], static fn ( array $r ): bool => in_array( $r['action'], $actions, true ) && $matched( $r ) ) );

			if ( 'flag' === $c['kind'] ) {
				$expected = [ 'active' => [] !== $of( [ 'hide_tax_note' ] ) ];
				$actual   = [ 'active' => $c['expect']['active'] ];
				$php_out  = [ 'active' => $php->active ];
			} elseif ( 'toggle' === $c['kind'] ) {
				$enable  = $of( [ 'enable' ] );
				$disable = $of( [ 'disable' ] );
				// §3: enable = "only when"; §8: lower priority wins, ties go to disable.
				$has_enable = [] !== array_filter( $enabled, static fn ( array $r ): bool => 'enable' === $r['action'] );
				if ( $disable && $enable ) {
					$active = $best( $enable ) < $best( $disable );
				} elseif ( $disable ) {
					$active = false;
				} elseif ( $enable ) {
					$active = true;
				} else {
					$active = ! $has_enable;
				}
				$expected = [ 'active' => $active ];
				$actual   = [ 'active' => $c['expect']['active'] ];
				$php_out  = [ 'active' => $php->active ];
			} else {
				$positive = $of( [ 'show', 'require' ] );
				$hide     = $of( [ 'hide' ] );
				$has_show = [] !== array_filter( $enabled, static fn ( array $r ): bool => 'show' === $r['action'] );
				// §3: show = "only when", require = shown + required when matched, hide = hidden when matched.
				// §8: lower priority wins; ties go to hide ("require loses to hide").
				if ( $hide && $positive ) {
					$visible = $best( $positive ) < $best( $hide );
				} elseif ( $hide ) {
					$visible = false;
				} elseif ( $positive ) {
					$visible = true;
				} else {
					$visible = ! $has_show;
				}
				$required_rule = [] !== $of( [ 'require' ] );
				// §3: a hidden field is never required; require never removes the original required state.
				$required = $visible && ( $required_rule || $c['original_required'] );
				$expected = [
					'active'   => $visible,
					'required' => $required,
				];
				$actual   = [
					'active'   => $c['expect']['active'],
					'required' => $c['expect']['required'],
				];
				$php_out  = [
					'active'   => $php->active,
					'required' => $php->required,
				];
			}

			if ( $expected !== $actual || $expected !== $php_out ) {
				$failures[] = "#$i {$c['kind']} " . json_encode( $c['states'] ) . ( isset( $c['original_required'] ) ? ' orig=' . var_export( $c['original_required'], true ) : '' ) . ' spec=' . json_encode( $expected ) . ' js=' . json_encode( $actual ) . ' php=' . json_encode( $php_out );
			}
		}
		$this->assertSame( [], $failures, implode( "\n", array_slice( $failures, 0, 30 ) ) );
	}
}
