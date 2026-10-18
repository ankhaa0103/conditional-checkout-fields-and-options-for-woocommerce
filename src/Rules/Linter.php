<?php
/**
 * Explains rule problems to the admin (SPEC 1.11).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

/**
 * Pure checks over a rule set and a description of the store.
 *
 * Environment keys (all optional):
 * - fields:          list<string>  checkout field keys
 * - block_fields:    list<string>  fields the Checkout Block has
 * - gateways:        list<string>  enabled gateway ids
 * - shipping:        list<string>  method ids and "method:instance" ids
 * - tax_enabled:     bool
 * - tax_inclusive:   bool          prices shown including tax in cart/checkout
 * - block_checkout:  bool          checkout page uses the Checkout Block
 */
final class Linter {

	private const CRITICAL_FIELDS = [ 'billing_email', 'billing_country' ];

	/**
	 * Findings.
	 *
	 * @var list<array{rule: string, level: string, code: string, message: string}>
	 */
	private array $findings = [];

	/**
	 * Check rules.
	 *
	 * @param list<Rule>           $rules Rules.
	 * @param array<string, mixed> $env   Store description.
	 * @return list<array{rule: string, level: string, code: string, message: string}>
	 */
	public function lint( array $rules, array $env ): array {
		$this->findings = [];
		$enabled        = array_values( array_filter( $rules, static fn ( Rule $r ): bool => $r->enabled ) );

		foreach ( $enabled as $rule ) {
			$this->check_target_exists( $rule, $env );
			$this->check_effect( $rule, $env );
			$this->check_block_support( $rule, $env );
		}

		$this->check_conflicts( $enabled );
		$this->check_all_removed( $enabled, $env, Definitions::TARGET_PAYMENT, 'gateways' );
		$this->check_all_removed( $enabled, $env, Definitions::TARGET_SHIPPING, 'shipping' );

		return $this->findings;
	}

	/**
	 * The target must exist in the store.
	 *
	 * @param Rule                 $rule Rule.
	 * @param array<string, mixed> $env  Environment.
	 */
	private function check_target_exists( Rule $rule, array $env ): void {
		$lists = [
			Definitions::TARGET_FIELD    => 'fields',
			Definitions::TARGET_PAYMENT  => 'gateways',
			Definitions::TARGET_SHIPPING => 'shipping',
		];
		$list  = $lists[ $rule->target_type ] ?? null;
		if ( null === $list || ! isset( $env[ $list ] ) || ! is_array( $env[ $list ] ) ) {
			return;
		}
		if ( ! in_array( $rule->target_key, $env[ $list ], true ) ) {
			$this->add(
				$rule,
				'warning',
				'missing_target',
				/* translators: %s: field key, gateway id or shipping method id */
				sprintf( __( '"%s" is not active in the store now, so this rule does nothing.', 'conditional-checkout-fields-and-options-for-woocommerce' ), $rule->target_key )
			);
		}

		foreach ( $rule->sources() as $source ) {
			if ( Definitions::is_field_source( $source ) && isset( $env['fields'] ) && is_array( $env['fields'] ) && ! in_array( Definitions::field_key( $source ), $env['fields'], true ) ) {
				$this->add(
					$rule,
					'warning',
					'missing_source_field',
					/* translators: %s: field key */
					sprintf( __( 'Condition field "%s" is not on the checkout now; its value is always empty.', 'conditional-checkout-fields-and-options-for-woocommerce' ), Definitions::field_key( $source ) )
				);
			}
		}
	}

	/**
	 * Rules that cannot have an effect, and risky ones.
	 *
	 * @param Rule                 $rule Rule.
	 * @param array<string, mixed> $env  Environment.
	 */
	private function check_effect( Rule $rule, array $env ): void {
		$tax_enabled = ! empty( $env['tax_enabled'] );

		if ( Definitions::TARGET_TAX_DISPLAY === $rule->target_type && ( ! $tax_enabled || empty( $env['tax_inclusive'] ) ) ) {
			$this->add( $rule, 'warning', 'no_tax_note', __( 'The "includes tax" note only shows when taxes are enabled and cart prices are displayed including tax, so this rule does nothing now.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
		}

		if ( Definitions::TARGET_TAX_CALC === $rule->target_type && ! $tax_enabled ) {
			$this->add( $rule, 'warning', 'taxes_disabled', __( 'Taxes are disabled in WooCommerce, so this rule does nothing.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
		}

		if ( Definitions::TARGET_FIELD === $rule->target_type && in_array( $rule->target_key, self::CRITICAL_FIELDS, true ) && in_array( $rule->action, [ 'show', 'hide' ], true ) ) {
			$this->add( $rule, 'warning', 'critical_field', __( 'Orders without this field may not be processed correctly (emails, taxes, shipping).', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
		}

		if ( [] === $rule->groups && in_array( $rule->action, [ 'hide', 'disable' ], true ) ) {
			$this->add( $rule, 'info', 'unconditional', __( 'This rule has no conditions and always applies.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
		}
	}

	/**
	 * What works in the Checkout Block.
	 *
	 * @param Rule                 $rule Rule.
	 * @param array<string, mixed> $env  Environment.
	 */
	private function check_block_support( Rule $rule, array $env ): void {
		if ( empty( $env['block_checkout'] ) || Definitions::TARGET_FIELD !== $rule->target_type ) {
			return;
		}

		$block_fields = isset( $env['block_fields'] ) && is_array( $env['block_fields'] ) ? $env['block_fields'] : [];
		if ( 'require' === $rule->action && in_array( $rule->target_key, $block_fields, true ) ) {
			$this->add( $rule, 'info', 'block_submit_only', __( 'Checkout Block: the field is checked when the order is placed, but it is not marked as required in the form.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return;
		}

		$this->add( $rule, 'warning', 'block_unsupported', __( 'Checkout Block: showing, hiding or requiring this field is not possible with the official WooCommerce APIs. The rule works in the classic checkout only.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
	}

	/**
	 * Opposite actions on the same target.
	 *
	 * @param list<Rule> $rules Enabled rules.
	 */
	private function check_conflicts( array $rules ): void {
		$restrictive = [ 'hide', 'disable' ];
		$permissive  = [ 'show', 'require', 'enable' ];
		$by_target   = [];

		foreach ( $rules as $rule ) {
			if ( in_array( $rule->target_type, [ Definitions::TARGET_FIELD, Definitions::TARGET_PAYMENT, Definitions::TARGET_SHIPPING ], true ) ) {
				// Shipping method rules also affect each of their instances.
				$key = Definitions::TARGET_SHIPPING === $rule->target_type ? explode( ':', $rule->target_key )[0] : $rule->target_key;
				$by_target[ $rule->target_type . ':' . $key ][] = $rule;
			}
		}

		foreach ( $by_target as $target_rules ) {
			foreach ( $target_rules as $a ) {
				foreach ( $target_rules as $b ) {
					if ( ! in_array( $a->action, $restrictive, true ) || ! in_array( $b->action, $permissive, true ) ) {
						continue;
					}
					if ( Definitions::TARGET_SHIPPING === $a->target_type && $a->target_key !== $b->target_key && str_contains( $a->target_key, ':' ) && str_contains( $b->target_key, ':' ) ) {
						continue; // Different instances of the same method.
					}

					$winner = $a->priority <= $b->priority ? $a : $b;
					$this->add( $a, 'warning', 'conflict', self::conflict_message( $b, $winner ) );
					$this->add( $b, 'warning', 'conflict', self::conflict_message( $a, $winner ) );
				}
			}
		}
	}

	/**
	 * Rules that can remove every payment gateway or every shipping method.
	 *
	 * @param list<Rule>           $rules    Enabled rules.
	 * @param array<string, mixed> $env      Environment.
	 * @param string               $type     Target type.
	 * @param string               $env_key  Environment list key.
	 */
	private function check_all_removed( array $rules, array $env, string $type, string $env_key ): void {
		$available = isset( $env[ $env_key ] ) && is_array( $env[ $env_key ] ) ? $env[ $env_key ] : [];
		if ( Definitions::TARGET_SHIPPING === $type ) {
			// Count instances only; method ids cover all their instances.
			$available = array_values( array_filter( $available, static fn ( $k ): bool => is_string( $k ) && str_contains( $k, ':' ) ) );
		}
		if ( [] === $available ) {
			return;
		}

		$targeted = [];
		$involved = [];
		foreach ( $rules as $rule ) {
			if ( $type !== $rule->target_type || ! in_array( $rule->action, [ 'disable', 'enable' ], true ) ) {
				continue;
			}
			$involved[] = $rule;
			foreach ( $available as $key ) {
				if ( $key === $rule->target_key || ( Definitions::TARGET_SHIPPING === $type && explode( ':', (string) $key )[0] === $rule->target_key ) ) {
					$targeted[ $key ] = true;
				}
			}
		}

		if ( count( $targeted ) < count( $available ) ) {
			return;
		}

		$message = Definitions::TARGET_PAYMENT === $type
			? __( 'Together with other rules, this rule can remove every payment method. Customers then see your "no payment method" message and cannot order.', 'conditional-checkout-fields-and-options-for-woocommerce' )
			: __( 'Together with other rules, this rule can remove every shipping method. Customers then see your "no shipping method" message and cannot order.', 'conditional-checkout-fields-and-options-for-woocommerce' );

		foreach ( $involved as $rule ) {
			$this->add( $rule, 'warning', 'can_remove_all', $message );
		}
	}

	/**
	 * Conflict explanation.
	 *
	 * @param Rule $other  The other rule.
	 * @param Rule $winner The rule that wins.
	 */
	private static function conflict_message( Rule $other, Rule $winner ): string {
		return sprintf(
			/* translators: 1: other rule title, 2: winning rule title */
			__( 'Conflicts with "%1$s" when both match. "%2$s" wins (lower priority number wins; on a tie the hiding/disabling rule wins).', 'conditional-checkout-fields-and-options-for-woocommerce' ),
			self::name( $other ),
			self::name( $winner )
		);
	}

	/**
	 * Display name of a rule.
	 *
	 * @param Rule $rule Rule.
	 */
	private static function name( Rule $rule ): string {
		return '' !== $rule->title ? $rule->title : $rule->id;
	}

	/**
	 * Add a finding once.
	 *
	 * @param Rule   $rule    Rule.
	 * @param string $level   error|warning|info.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private function add( Rule $rule, string $level, string $code, string $message ): void {
		$finding = [
			'rule'    => $rule->id,
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		];
		if ( ! in_array( $finding, $this->findings, true ) ) {
			$this->findings[] = $finding;
		}
	}
}
