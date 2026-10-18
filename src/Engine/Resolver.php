<?php
/**
 * Combines matching rules into one decision per target (SPEC §3, §8).
 *
 * Mirrored in assets/src/shared/evaluator.js.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

use CCFO\Rules\Rule;

/**
 * Conflict policy:
 * 1. The matched rule with the lowest priority number wins.
 * 2. On a tie the restrictive action wins (hide over show/require, disable over enable).
 * 3. If "show"/"enable" rules exist and none matches, the target is hidden/disabled.
 */
final class Resolver {

	/**
	 * Visibility and required state of a field.
	 *
	 * @param list<Rule> $rules             Rules targeting the field.
	 * @param bool       $original_required Required state before our rules.
	 * @param Context    $context           Context.
	 */
	public static function field( array $rules, bool $original_required, Context $context ): Decision {
		$decision = self::resolve( $rules, $context, [ 'show', 'require' ], [ 'hide' ], 'show' );

		if ( ! $decision->active ) {
			return new Decision( false, false, $decision->applied, $decision->conflict, true );
		}

		$required_by_rule = false;
		foreach ( $rules as $rule ) {
			if ( 'require' === $rule->action && in_array( $rule->id, $decision->applied, true ) ) {
				$required_by_rule = true;
				break;
			}
		}

		return new Decision(
			true,
			$required_by_rule || $original_required,
			$decision->applied,
			$decision->conflict,
			$decision->changed || ( $required_by_rule && ! $original_required )
		);
	}

	/**
	 * Availability of a payment gateway or shipping rate.
	 *
	 * @param list<Rule> $rules   Rules targeting the method.
	 * @param Context    $context Context.
	 */
	public static function toggle( array $rules, Context $context ): Decision {
		return self::resolve( $rules, $context, [ 'enable' ], [ 'disable' ], 'enable' );
	}

	/**
	 * A flag that is on when any rule with the action matches.
	 *
	 * @param list<Rule> $rules   Rules targeting the flag.
	 * @param string     $action  Action that turns the flag on.
	 * @param Context    $context Context.
	 */
	public static function flag( array $rules, string $action, Context $context ): Decision {
		$applied = [];
		foreach ( self::ordered( $rules ) as $rule ) {
			if ( $action === $rule->action && Evaluator::matches( $rule, $context ) ) {
				$applied[] = $rule->id;
			}
		}
		return new Decision( [] !== $applied, false, $applied, null, [] !== $applied );
	}

	/**
	 * The winning matched rule for an action that takes one value (e.g. set_tax_class).
	 *
	 * @param list<Rule> $rules   Rules targeting the item.
	 * @param string     $action  Action.
	 * @param Context    $context Context.
	 */
	public static function pick( array $rules, string $action, Context $context ): ?Rule {
		foreach ( self::ordered( $rules ) as $rule ) {
			if ( $action === $rule->action && Evaluator::matches( $rule, $context ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Shared positive/negative resolution.
	 *
	 * @param list<Rule>   $rules     Rules.
	 * @param Context      $context   Context.
	 * @param list<string> $positive  Actions that make the target active.
	 * @param list<string> $negative  Actions that make it inactive.
	 * @param string       $exclusive Action whose mere presence makes the target inactive by default.
	 */
	private static function resolve( array $rules, Context $context, array $positive, array $negative, string $exclusive ): Decision {
		$matched_positive = [];
		$matched_negative = [];
		$exclusive_rules  = [];

		foreach ( self::ordered( $rules ) as $rule ) {
			if ( ! $rule->enabled ) {
				continue;
			}
			if ( $exclusive === $rule->action ) {
				$exclusive_rules[] = $rule->id;
			}
			$is_positive = in_array( $rule->action, $positive, true );
			$is_negative = in_array( $rule->action, $negative, true );
			if ( ! ( $is_positive || $is_negative ) || ! Evaluator::matches( $rule, $context ) ) {
				continue;
			}
			if ( $is_positive ) {
				$matched_positive[] = $rule;
			} else {
				$matched_negative[] = $rule;
			}
		}

		$ids = static fn ( array $rule_list ): array => array_map( static fn ( Rule $r ): string => $r->id, $rule_list );

		if ( [] !== $matched_negative && [] !== $matched_positive ) {
			// Lists are ordered, so [0] is the best of each side; a tie goes to the restrictive side.
			$negative_wins = $matched_negative[0]->priority <= $matched_positive[0]->priority;
			$winners       = $negative_wins ? $matched_negative : $matched_positive;
			$losers        = $negative_wins ? $matched_positive : $matched_negative;
			return new Decision(
				! $negative_wins,
				false,
				$ids( $winners ),
				[
					'winner'     => $winners[0]->id,
					'overridden' => $ids( $losers ),
				],
				true
			);
		}

		if ( [] !== $matched_negative ) {
			return new Decision( false, false, $ids( $matched_negative ), null, true );
		}

		if ( [] !== $matched_positive ) {
			return new Decision( true, false, $ids( $matched_positive ), null, false );
		}

		if ( [] !== $exclusive_rules ) {
			return new Decision( false, false, $exclusive_rules, null, true );
		}

		return new Decision( true );
	}

	/**
	 * Enabled rules sorted by priority, keeping list order on ties.
	 *
	 * @param list<Rule> $rules Rules.
	 * @return list<Rule>
	 */
	private static function ordered( array $rules ): array {
		$rules = array_values( array_filter( $rules, static fn ( Rule $r ): bool => $r->enabled ) );
		$order = array_flip( array_map( static fn ( Rule $r ): string => $r->id, $rules ) );
		usort(
			$rules,
			static fn ( Rule $a, Rule $b ): int => [ $a->priority, $order[ $a->id ] ] <=> [ $b->priority, $order[ $b->id ] ]
		);
		return $rules;
	}
}
