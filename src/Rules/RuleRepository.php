<?php
/**
 * Rule storage in a single option.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

/**
 * Loads and saves the rule set stored in the `ccfo_rules` option (autoload off).
 *
 * Stored shape: { schema: int, revision: int, rules: list<array> }.
 */
final class RuleRepository {

	public const OPTION = 'ccfo_rules';

	/**
	 * Request cache.
	 *
	 * @var RuleIndex|null
	 */
	private ?RuleIndex $index = null;

	/**
	 * Rules of the stored set, indexed. Invalid stored rules are skipped and logged.
	 */
	public function index(): RuleIndex {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$stored = $this->stored();
		$schema = new RuleSchema();
		$rules  = $schema->validate( $stored['rules'] );

		if ( [] !== $schema->errors() && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'Skipped invalid stored rules: ' . wp_json_encode( $schema->errors() ),
				[ 'source' => 'ccfo' ]
			);
		}

		$this->index = new RuleIndex( $rules, $stored['revision'] );
		return $this->index;
	}

	/**
	 * Raw stored data, migrated to the current schema.
	 *
	 * @return array{schema: int, revision: int, rules: list<mixed>}
	 */
	public function stored(): array {
		$raw = get_option( self::OPTION, [] );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$rules = isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? array_values( $raw['rules'] ) : [];

		return [
			'schema'   => RuleSchema::VERSION,
			'revision' => isset( $raw['revision'] ) ? (int) $raw['revision'] : 0,
			'rules'    => $rules,
		];
	}

	/**
	 * Validate and save a full rule set.
	 *
	 * @param mixed                $raw_rules         Raw rules.
	 * @param int                  $expected_revision Revision the editor started from.
	 * @param array<string, mixed> $env               Validation environment.
	 * @return array{revision: int, rules: list<Rule>}|\WP_Error
	 */
	public function save( mixed $raw_rules, int $expected_revision, array $env = [] ): array|\WP_Error {
		$current = $this->stored();
		if ( $current['revision'] !== $expected_revision ) {
			return new \WP_Error(
				'ccfo_revision_conflict',
				__( 'The rules were changed in another window. Reload the page to get the latest rules.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
				[
					'status'   => 409,
					'revision' => $current['revision'],
				]
			);
		}

		$schema = new RuleSchema();
		$rules  = $schema->validate( $raw_rules, $env );
		if ( [] !== $schema->errors() ) {
			return new \WP_Error(
				'ccfo_invalid_rules',
				__( 'Some rules are not valid.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
				[
					'status' => 400,
					'errors' => $schema->errors(),
				]
			);
		}

		$revision = $current['revision'] + 1;
		update_option(
			self::OPTION,
			[
				'schema'   => RuleSchema::VERSION,
				'revision' => $revision,
				'rules'    => array_map( static fn ( Rule $r ): array => json_decode( (string) wp_json_encode( $r->to_array() ), true ), $rules ),
			],
			false
		);

		$this->index = null;

		/**
		 * Fires after the rule set is saved.
		 *
		 * @param int $revision New revision.
		 */
		do_action( 'ccfo_rules_saved', $revision );

		return [
			'revision' => $revision,
			'rules'    => $rules,
		];
	}

	/**
	 * Forget the request cache.
	 */
	public function flush(): void {
		$this->index = null;
	}
}
