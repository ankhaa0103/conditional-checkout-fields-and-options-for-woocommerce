<?php
/**
 * Validation and normalisation of raw rule data.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Turns untrusted arrays (option value, REST body, import file) into Rule objects.
 *
 * Pure PHP apart from the translation functions, so it is unit tested without WordPress.
 */
final class RuleSchema {

	public const VERSION = 1;

	private const MAX_RULES      = 500;
	private const MAX_GROUPS     = 20;
	private const MAX_CONDITIONS = 20;
	private const MAX_LIST       = 500;
	private const MAX_MESSAGE    = 500;

	/**
	 * Validation errors of the last validate() call.
	 *
	 * @var list<array{rule: int, id: string, path: string, message: string}>
	 */
	private array $errors = [];

	/**
	 * Validate a list of raw rules.
	 *
	 * Invalid rules are left out of the result and reported in errors().
	 *
	 * @param mixed                $raw_rules List of raw rule arrays.
	 * @param array<string, mixed> $env       Optional environment, e.g. ['tax_classes' => ['', 'reduced-rate']].
	 * @return list<Rule>
	 */
	public function validate( mixed $raw_rules, array $env = [] ): array {
		$this->errors = [];

		if ( ! is_array( $raw_rules ) || ! array_is_list( $raw_rules ) ) {
			$this->error( -1, '', 'rules', __( 'Rules must be a list.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return [];
		}

		if ( count( $raw_rules ) > self::MAX_RULES ) {
			/* translators: %d: maximum number of rules */
			$this->error( -1, '', 'rules', sprintf( __( 'No more than %d rules are allowed.', 'conditional-checkout-fields-and-options-for-woocommerce' ), self::MAX_RULES ) );
			return [];
		}

		$rules = [];
		$ids   = [];
		foreach ( $raw_rules as $index => $raw ) {
			$rule = $this->validate_rule( $index, $raw, $env );
			if ( null === $rule ) {
				continue;
			}
			if ( isset( $ids[ $rule->id ] ) ) {
				$this->error( $index, $rule->id, 'id', __( 'Rule id is used more than once.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
				continue;
			}
			$ids[ $rule->id ] = true;
			$rules[]          = $rule;
		}

		return $rules;
	}

	/**
	 * Errors of the last validate() call.
	 *
	 * @return list<array{rule: int, id: string, path: string, message: string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Validate one rule.
	 *
	 * @param int                  $index Position in the list.
	 * @param mixed                $raw   Raw rule.
	 * @param array<string, mixed> $env   Environment.
	 */
	private function validate_rule( int $index, mixed $raw, array $env ): ?Rule {
		if ( ! is_array( $raw ) ) {
			$this->error( $index, '', '', __( 'Rule must be an object.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$id = isset( $raw['id'] ) && is_string( $raw['id'] ) ? $raw['id'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $id ) ) {
			$this->error( $index, $id, 'id', __( 'Rule id must be 1-64 letters, digits, "-" or "_".', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$errors_before = count( $this->errors );

		$title    = isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? $this->clean_text( (string) $raw['title'] ) : '';
		$enabled  = ! isset( $raw['enabled'] ) || true === $raw['enabled'];
		$priority = $raw['priority'] ?? 10;
		if ( ! is_int( $priority ) || $priority < 0 || $priority > 1000 ) {
			$this->error( $index, $id, 'priority', __( 'Priority must be a whole number from 0 to 1000.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			$priority = 10;
		}

		$target      = isset( $raw['target'] ) && is_array( $raw['target'] ) ? $raw['target'] : [];
		$target_type = isset( $target['type'] ) && is_string( $target['type'] ) ? $target['type'] : '';
		if ( ! isset( Definitions::ACTIONS[ $target_type ] ) ) {
			$this->error( $index, $id, 'target.type', __( 'Unknown target type.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$target_key = $this->validate_target_key( $index, $id, $target_type, $target['key'] ?? null );

		$action = isset( $raw['action'] ) && is_string( $raw['action'] ) ? $raw['action'] : '';
		if ( ! in_array( $action, Definitions::ACTIONS[ $target_type ], true ) ) {
			$this->error( $index, $id, 'action', __( 'This action is not allowed for the target.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
		}

		$args   = $this->validate_args( $index, $id, $action, $raw['args'] ?? [], $env );
		$groups = $this->validate_groups( $index, $id, $target_type, $target_key, $raw['groups'] ?? [] );

		if ( count( $this->errors ) > $errors_before ) {
			return null;
		}

		return new Rule( $id, $title, $enabled, $priority, $target_type, $target_key, $action, $args, $groups );
	}

	/**
	 * Validate the target key.
	 *
	 * @param int    $index       Rule index.
	 * @param string $id          Rule id.
	 * @param string $target_type Target type.
	 * @param mixed  $key         Raw key.
	 */
	private function validate_target_key( int $index, string $id, string $target_type, mixed $key ): string {
		if ( isset( Definitions::FIXED_KEYS[ $target_type ] ) ) {
			return Definitions::FIXED_KEYS[ $target_type ];
		}

		$patterns = [
			Definitions::TARGET_FIELD    => '/^[A-Za-z0-9_\-\[\]]{1,100}$/',
			Definitions::TARGET_PAYMENT  => '/^[A-Za-z0-9_\-]{1,100}$/',
			Definitions::TARGET_SHIPPING => '/^[A-Za-z0-9_\-]{1,100}(:\d{1,10})?$/',
		];

		if ( ! is_string( $key ) || 1 !== preg_match( $patterns[ $target_type ], $key ) ) {
			$this->error( $index, $id, 'target.key', __( 'Target key is missing or invalid.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return '';
		}

		return $key;
	}

	/**
	 * Validate action arguments.
	 *
	 * @param int                  $index  Rule index.
	 * @param string               $id     Rule id.
	 * @param string               $action Action.
	 * @param mixed                $args   Raw args.
	 * @param array<string, mixed> $env    Environment.
	 * @return array<string, mixed>
	 */
	private function validate_args( int $index, string $id, string $action, mixed $args, array $env ): array {
		if ( in_array( $action, Definitions::ACTIONS[ Definitions::TARGET_ORDER ], true ) ) {
			return $this->validate_message( $index, $id, $args );
		}
		if ( 'set_tax_class' !== $action ) {
			return [];
		}

		$tax_class = is_array( $args ) && isset( $args['tax_class'] ) && is_string( $args['tax_class'] ) ? $args['tax_class'] : null;
		if ( null === $tax_class || 1 !== preg_match( '/^[a-z0-9\-]{0,200}$/', $tax_class ) ) {
			$this->error( $index, $id, 'args.tax_class', __( 'Choose a tax class.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return [];
		}

		if ( isset( $env['tax_classes'] ) && is_array( $env['tax_classes'] ) && ! in_array( $tax_class, $env['tax_classes'], true ) ) {
			$this->error( $index, $id, 'args.tax_class', __( 'The tax class does not exist. Only existing WooCommerce tax classes can be used.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return [];
		}

		return [ 'tax_class' => $tax_class ];
	}

	/**
	 * Validate the customer message of an order rule. An empty message means
	 * "use the default message from the settings".
	 *
	 * @param int    $index Rule index.
	 * @param string $id    Rule id.
	 * @param mixed  $args  Raw args.
	 * @return array<string, mixed>
	 */
	private function validate_message( int $index, string $id, mixed $args ): array {
		$message = is_array( $args ) ? ( $args['message'] ?? '' ) : '';
		if ( ! is_string( $message ) ) {
			$this->error( $index, $id, 'args.message', __( 'The message must be text.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return [];
		}
		if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
			/* translators: %d: maximum number of characters */
			$this->error( $index, $id, 'args.message', sprintf( __( 'The message can have up to %d characters.', 'conditional-checkout-fields-and-options-for-woocommerce' ), self::MAX_MESSAGE ) );
			return [];
		}
		return [ 'message' => $this->clean_text( $message, self::MAX_MESSAGE ) ];
	}

	/**
	 * Validate condition groups.
	 *
	 * @param int    $index       Rule index.
	 * @param string $id          Rule id.
	 * @param string $target_type Target type.
	 * @param string $target_key  Target key.
	 * @param mixed  $raw_groups  Raw groups.
	 * @return list<list<array{source: string, operator: string, value?: mixed}>>
	 */
	private function validate_groups( int $index, string $id, string $target_type, string $target_key, mixed $raw_groups ): array {
		if ( ! is_array( $raw_groups ) || ! array_is_list( $raw_groups ) || count( $raw_groups ) > self::MAX_GROUPS ) {
			/* translators: %d: maximum number of groups */
			$this->error( $index, $id, 'groups', sprintf( __( 'Conditions must be a list of up to %d groups.', 'conditional-checkout-fields-and-options-for-woocommerce' ), self::MAX_GROUPS ) );
			return [];
		}

		$groups = [];
		foreach ( $raw_groups as $g => $raw_group ) {
			if ( ! is_array( $raw_group ) || ! array_is_list( $raw_group ) || [] === $raw_group || count( $raw_group ) > self::MAX_CONDITIONS ) {
				/* translators: %d: maximum number of conditions */
				$this->error( $index, $id, "groups.$g", sprintf( __( 'Each group needs 1 to %d conditions.', 'conditional-checkout-fields-and-options-for-woocommerce' ), self::MAX_CONDITIONS ) );
				continue;
			}

			$group = [];
			foreach ( $raw_group as $c => $raw_condition ) {
				$condition = $this->validate_condition( $index, $id, "groups.$g.$c", $target_type, $target_key, $raw_condition );
				if ( null !== $condition ) {
					$group[] = $condition;
				}
			}
			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Validate one condition.
	 *
	 * @param int    $index       Rule index.
	 * @param string $id          Rule id.
	 * @param string $path        Error path.
	 * @param string $target_type Target type.
	 * @param string $target_key  Target key.
	 * @param mixed  $raw         Raw condition.
	 * @return array{source: string, operator: string, value?: mixed}|null
	 */
	private function validate_condition( int $index, string $id, string $path, string $target_type, string $target_key, mixed $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			$this->error( $index, $id, $path, __( 'Condition must be an object.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$source     = isset( $raw['source'] ) && is_string( $raw['source'] ) ? $raw['source'] : '';
		$definition = Definitions::source_definition( $source );
		if ( null === $definition ) {
			// Fields and categories are chosen in a second step in the admin.
			if ( str_starts_with( $source, 'field:' ) ) {
				$message = __( 'Choose a checkout field.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			} elseif ( str_starts_with( $source, 'cart.category_subtotal' ) ) {
				$message = __( 'Choose a product category.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			} else {
				$message = __( 'Unknown condition source.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			}
			$this->error( $index, $id, "$path.source", $message );
			return null;
		}

		if ( ! in_array( $target_type, $definition['targets'], true ) ) {
			$this->error( $index, $id, "$path.source", __( 'This condition cannot be used for this target because it would depend on its own result.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		if ( Definitions::TARGET_FIELD === $target_type && Definitions::is_field_source( $source ) && Definitions::field_key( $source ) === $target_key ) {
			$this->error( $index, $id, "$path.source", __( 'A field rule cannot depend on the same field.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$operator = isset( $raw['operator'] ) && is_string( $raw['operator'] ) ? $raw['operator'] : '';
		if ( ! in_array( $operator, Definitions::OPERATORS[ $definition['type'] ], true ) ) {
			$this->error( $index, $id, "$path.operator", __( 'This operator is not allowed for the condition.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
			return null;
		}

		$condition = [
			'source'   => $source,
			'operator' => $operator,
		];

		$shape = Definitions::OPERATOR_VALUE[ $operator ];
		if ( 'none' === $shape ) {
			return $condition;
		}

		$value = $this->normalise_value( $shape, $raw['value'] ?? null );
		if ( null === $value ) {
			$this->error( $index, $id, "$path.value", $this->value_message( $shape ) );
			return null;
		}

		// Product and term conditions store ids.
		$id_sources = [ 'cart.products', 'cart.categories', 'cart.tags', 'cart.shipping_classes' ];
		if ( in_array( $source, $id_sources, true ) && is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( 1 !== preg_match( '/^[1-9]\d{0,19}$/', (string) $item ) ) {
					$this->error( $index, $id, "$path.value", __( 'Choose products or terms from the list.', 'conditional-checkout-fields-and-options-for-woocommerce' ) );
					return null;
				}
			}
		}

		$condition['value'] = $value;
		return $condition;
	}

	/**
	 * Normalise a condition value to its operator shape.
	 *
	 * @param string $shape Value shape.
	 * @param mixed  $value Raw value.
	 * @return string|int|float|list<string>|list<int|float>|null Null when invalid.
	 */
	private function normalise_value( string $shape, mixed $value ): string|int|float|array|null {
		switch ( $shape ) {
			case 'scalar':
				if ( ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) && '' !== trim( (string) $value ) ) {
					return $this->clean_text( (string) $value );
				}
				return null;

			case 'number':
				return $this->to_number( $value );

			case 'list':
				if ( ! is_array( $value ) || [] === $value || count( $value ) > self::MAX_LIST ) {
					return null;
				}
				$list = [];
				foreach ( $value as $item ) {
					if ( ! ( is_string( $item ) || is_int( $item ) ) || '' === trim( (string) $item ) ) {
						return null;
					}
					$list[] = $this->clean_text( (string) $item );
				}
				return array_values( array_unique( $list ) );

			case 'range':
				if ( ! is_array( $value ) || 2 !== count( $value ) || ! array_is_list( $value ) ) {
					return null;
				}
				$min = $this->to_number( $value[0] );
				$max = $this->to_number( $value[1] );
				if ( null === $min || null === $max || $min > $max ) {
					return null;
				}
				return [ $min, $max ];
		}

		return null;
	}

	/**
	 * Strict number conversion.
	 *
	 * @param mixed $value Raw value.
	 */
	private function to_number( mixed $value ): int|float|null {
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? $value : null;
		}
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
			return trim( $value ) + 0;
		}
		return null;
	}

	/**
	 * Message for an invalid value.
	 *
	 * @param string $shape Value shape.
	 */
	private function value_message( string $shape ): string {
		switch ( $shape ) {
			case 'number':
				return __( 'Enter a number.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			case 'list':
				return __( 'Choose at least one value.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			case 'range':
				return __( 'Enter a minimum and a maximum number.', 'conditional-checkout-fields-and-options-for-woocommerce' );
			default:
				return __( 'Enter a value.', 'conditional-checkout-fields-and-options-for-woocommerce' );
		}
	}

	/**
	 * Remove markup and control characters from user text.
	 *
	 * @param string $text   Raw text.
	 * @param int    $length Maximum length.
	 */
	private function clean_text( string $text, int $length = 200 ): string {
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $text );
		return mb_substr( trim( $text ), 0, $length );
	}

	/**
	 * Record an error.
	 *
	 * @param int    $rule    Rule index, -1 for the whole list.
	 * @param string $id      Rule id.
	 * @param string $path    Path inside the rule.
	 * @param string $message Translated message.
	 */
	private function error( int $rule, string $id, string $path, string $message ): void {
		$this->errors[] = [
			'rule'    => $rule,
			'id'      => $id,
			'path'    => $path,
			'message' => $message,
		];
	}
}
