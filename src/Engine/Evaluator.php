<?php
/**
 * Rule condition evaluation.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

defined( 'ABSPATH' ) || exit;

use CCFO\Rules\Rule;

/**
 * AND inside a group, OR between groups. A rule without groups always matches.
 */
final class Evaluator {

	/**
	 * Whether the rule's conditions hold.
	 *
	 * @param Rule    $rule    Rule.
	 * @param Context $context Context.
	 */
	public static function matches( Rule $rule, Context $context ): bool {
		if ( [] === $rule->groups ) {
			return true;
		}

		foreach ( $rule->groups as $group ) {
			$group_matches = true;
			foreach ( $group as $condition ) {
				if ( ! Operators::apply( $condition['operator'], $context->get( $condition['source'] ), $condition['value'] ?? null ) ) {
					$group_matches = false;
					break;
				}
			}
			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}
}
