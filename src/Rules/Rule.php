<?php
/**
 * Validated rule value object.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * One rule: a target, an action and OR-ed groups of AND-ed conditions.
 *
 * Instances are only created by RuleSchema, so their data is always valid.
 *
 * @phpstan-type Condition array{source: string, operator: string, value?: mixed}
 */
final class Rule {

	/**
	 * Constructor.
	 *
	 * @param string                    $id          Stable rule id.
	 * @param string                    $title       Admin title.
	 * @param bool                      $enabled     Whether the rule runs.
	 * @param int                       $priority    Lower number wins conflicts.
	 * @param string                    $target_type One of Definitions::ACTIONS keys.
	 * @param string                    $target_key  Field key, gateway id, rate/method id or fixed key.
	 * @param string                    $action      Action allowed for the target type.
	 * @param array<string, mixed>      $args        Action arguments.
	 * @param list<list<Condition>>     $groups      OR-ed groups of AND-ed conditions.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $enabled,
		public readonly int $priority,
		public readonly string $target_type,
		public readonly string $target_key,
		public readonly string $action,
		public readonly array $args,
		public readonly array $groups,
	) {}

	/**
	 * Every source the rule reads.
	 *
	 * @return list<string>
	 */
	public function sources(): array {
		$sources = [];
		foreach ( $this->groups as $group ) {
			foreach ( $group as $condition ) {
				$sources[ $condition['source'] ] = true;
			}
		}
		return array_keys( $sources );
	}

	/**
	 * Plain array form, as stored and sent to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'       => $this->id,
			'title'    => $this->title,
			'enabled'  => $this->enabled,
			'priority' => $this->priority,
			'target'   => [
				'type' => $this->target_type,
				'key'  => $this->target_key,
			],
			'action'   => $this->action,
			'args'     => (object) $this->args,
			'groups'   => $this->groups,
		];
	}
}
