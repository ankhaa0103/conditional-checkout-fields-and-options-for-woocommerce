<?php
/**
 * Resolved outcome for one target.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of the Resolver.
 */
final class Decision {

	/**
	 * Constructor.
	 *
	 * @param bool                                                 $active     Field visible / method enabled / flag on.
	 * @param bool                                                 $required   Field required (fields only).
	 * @param list<string>                                         $applied    Ids of rules that determined the outcome.
	 * @param array{winner: string, overridden: list<string>}|null $conflict   Opposite rules matched at once.
	 * @param bool                                                 $changed    Whether rules changed the default outcome.
	 */
	public function __construct(
		public readonly bool $active,
		public readonly bool $required = false,
		public readonly array $applied = [],
		public readonly ?array $conflict = null,
		public readonly bool $changed = false,
	) {}

	/**
	 * Array form for the browser and logs.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'active'   => $this->active,
			'required' => $this->required,
			'applied'  => $this->applied,
			'conflict' => $this->conflict,
		];
	}
}
