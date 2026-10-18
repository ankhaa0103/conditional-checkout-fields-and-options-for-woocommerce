<?php
/**
 * Enabled rules indexed by target.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

/**
 * Fast lookup of the rules that apply to a target.
 */
final class RuleIndex {

	/**
	 * Rules by target type and key.
	 *
	 * @var array<string, array<string, list<Rule>>>
	 */
	private array $by_target = [];

	/**
	 * Constructor.
	 *
	 * @param list<Rule> $rules    All rules, enabled or not.
	 * @param int        $revision Revision of the stored rule set.
	 */
	public function __construct( private readonly array $rules, public readonly int $revision = 0 ) {
		foreach ( $rules as $rule ) {
			if ( $rule->enabled ) {
				$this->by_target[ $rule->target_type ][ $rule->target_key ][] = $rule;
			}
		}
	}

	/**
	 * All rules, including disabled ones.
	 *
	 * @return list<Rule>
	 */
	public function all(): array {
		return $this->rules;
	}

	/**
	 * Enabled rules for a target.
	 *
	 * @param string $type Target type.
	 * @param string $key  Target key.
	 * @return list<Rule>
	 */
	public function for_target( string $type, string $key ): array {
		return $this->by_target[ $type ][ $key ] ?? [];
	}

	/**
	 * Enabled rules for a shipping rate, matching both "flat_rate:3" and "flat_rate".
	 *
	 * @param string $rate_id   Rate id, e.g. "flat_rate:3".
	 * @param string $method_id Method id, e.g. "flat_rate".
	 * @return list<Rule>
	 */
	public function for_rate( string $rate_id, string $method_id ): array {
		$rules = $this->for_target( Definitions::TARGET_SHIPPING, $rate_id );
		if ( $rate_id !== $method_id ) {
			$rules = array_merge( $rules, $this->for_target( Definitions::TARGET_SHIPPING, $method_id ) );
		}
		return $rules;
	}

	/**
	 * Target keys that have enabled rules.
	 *
	 * @param string $type Target type.
	 * @return list<string>
	 */
	public function keys( string $type ): array {
		return array_map( 'strval', array_keys( $this->by_target[ $type ] ?? [] ) );
	}

	/**
	 * Whether any enabled rule targets the type.
	 *
	 * @param string $type Target type.
	 */
	public function has( string $type ): bool {
		return ! empty( $this->by_target[ $type ] );
	}

	/**
	 * Enabled rules of a target type.
	 *
	 * @param string $type Target type.
	 * @return list<Rule>
	 */
	public function of_type( string $type ): array {
		$out = [];
		foreach ( $this->by_target[ $type ] ?? [] as $rules ) {
			array_push( $out, ...$rules );
		}
		return $out;
	}

	/**
	 * Sources read by enabled rules of the given target types.
	 *
	 * @param list<string> $types Target types.
	 * @return list<string>
	 */
	public function sources( array $types ): array {
		$sources = [];
		foreach ( $types as $type ) {
			foreach ( $this->of_type( $type ) as $rule ) {
				foreach ( $rule->sources() as $source ) {
					$sources[ $source ] = true;
				}
			}
		}
		$sources = array_keys( $sources );
		sort( $sources );
		return $sources;
	}
}
