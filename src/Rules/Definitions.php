<?php
/**
 * Targets, actions, sources and operators allowed in rules.
 *
 * This is the single source of truth for what a valid rule looks like. The
 * admin UI and the JS evaluator receive the same definitions via export().
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Rules;

/**
 * Static rule vocabulary.
 */
final class Definitions {

	public const TARGET_FIELD       = 'field';
	public const TARGET_PAYMENT     = 'payment';
	public const TARGET_SHIPPING    = 'shipping';
	public const TARGET_TAX_DISPLAY = 'tax_display';
	public const TARGET_TAX_CALC    = 'tax_calc';

	public const TYPE_STRING = 'string';
	public const TYPE_NUMBER = 'number';
	public const TYPE_BOOL   = 'bool';
	public const TYPE_LIST   = 'list';
	public const TYPE_FIELD  = 'field';

	/**
	 * Actions allowed per target type.
	 *
	 * @var array<string, list<string>>
	 */
	public const ACTIONS = [
		self::TARGET_FIELD       => [ 'show', 'hide', 'require' ],
		self::TARGET_PAYMENT     => [ 'enable', 'disable' ],
		self::TARGET_SHIPPING    => [ 'enable', 'disable' ],
		self::TARGET_TAX_DISPLAY => [ 'hide_tax_note' ],
		self::TARGET_TAX_CALC    => [ 'set_vat_exempt', 'set_tax_class' ],
	];

	/**
	 * Fixed target keys for targets that do not point at a specific item.
	 *
	 * @var array<string, string>
	 */
	public const FIXED_KEYS = [
		self::TARGET_TAX_DISPLAY => 'checkout',
		self::TARGET_TAX_CALC    => 'cart',
	];

	/**
	 * Operators allowed per value type.
	 *
	 * @var array<string, list<string>>
	 */
	public const OPERATORS = [
		self::TYPE_STRING => [ 'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty' ],
		self::TYPE_NUMBER => [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between' ],
		self::TYPE_BOOL   => [ 'is_true', 'is_false' ],
		self::TYPE_LIST   => [ 'any_in', 'all_in', 'none_in', 'only_in', 'empty', 'not_empty' ],
		self::TYPE_FIELD  => [ 'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty', 'checked', 'unchecked', 'gt', 'gte', 'lt', 'lte' ],
	];

	/**
	 * Shape of the value each operator expects.
	 *
	 * - none:   no value.
	 * - scalar: a string.
	 * - number: a number.
	 * - list:   a non-empty list of strings/ints.
	 * - range:  [min, max] numbers.
	 *
	 * @var array<string, string>
	 */
	public const OPERATOR_VALUE = [
		'equals'       => 'scalar',
		'not_equals'   => 'scalar',
		'contains'     => 'scalar',
		'not_contains' => 'scalar',
		'in'           => 'list',
		'not_in'       => 'list',
		'empty'        => 'none',
		'not_empty'    => 'none',
		'checked'      => 'none',
		'unchecked'    => 'none',
		'is_true'      => 'none',
		'is_false'     => 'none',
		'eq'           => 'number',
		'neq'          => 'number',
		'gt'           => 'number',
		'gte'          => 'number',
		'lt'           => 'number',
		'lte'          => 'number',
		'between'      => 'range',
		'any_in'       => 'list',
		'all_in'       => 'list',
		'none_in'      => 'list',
		'only_in'      => 'list',
	];

	private const ALL_TARGETS = [ self::TARGET_FIELD, self::TARGET_PAYMENT, self::TARGET_SHIPPING, self::TARGET_TAX_DISPLAY, self::TARGET_TAX_CALC ];

	/**
	 * Sources (SPEC §4). `client` = the browser can read the value itself.
	 *
	 * The target lists exclude circular dependencies: a payment rule cannot
	 * depend on the chosen payment method, a shipping rule cannot depend on the
	 * chosen shipping or payment method or on the total that includes shipping.
	 *
	 * @return array<string, array{type: string, targets: list<string>, client: bool}>
	 */
	public static function sources(): array {
		$all          = self::ALL_TARGETS;
		$not_shipping = [ self::TARGET_FIELD, self::TARGET_PAYMENT ];

		return [
			'cart.subtotal'         => self::source( self::TYPE_NUMBER, $all ),
			'cart.total'            => self::source( self::TYPE_NUMBER, $not_shipping ),
			'cart.item_count'       => self::source( self::TYPE_NUMBER, $all ),
			'cart.qty'              => self::source( self::TYPE_NUMBER, $all ),
			'cart.weight'           => self::source( self::TYPE_NUMBER, $all ),
			'cart.products'         => self::source( self::TYPE_LIST, $all ),
			'cart.categories'       => self::source( self::TYPE_LIST, $all ),
			'cart.tags'             => self::source( self::TYPE_LIST, $all ),
			'cart.shipping_classes' => self::source( self::TYPE_LIST, $all ),
			'payment.method'        => self::source( self::TYPE_STRING, [ self::TARGET_FIELD, self::TARGET_TAX_DISPLAY, self::TARGET_TAX_CALC ], true ),
			'shipping.method'       => self::source( self::TYPE_LIST, [ self::TARGET_FIELD, self::TARGET_PAYMENT, self::TARGET_TAX_DISPLAY, self::TARGET_TAX_CALC ], true ),
			'customer.is_guest'     => self::source( self::TYPE_BOOL, $all ),
			'customer.role'         => self::source( self::TYPE_LIST, $all ),
			'billing.country'       => self::source( self::TYPE_STRING, $all, true ),
			'billing.state'         => self::source( self::TYPE_STRING, $all, true ),
			'billing.postcode'      => self::source( self::TYPE_STRING, $all, true ),
			'shipping.country'      => self::source( self::TYPE_STRING, $all, true ),
			'shipping.state'        => self::source( self::TYPE_STRING, $all, true ),
			'shipping.postcode'     => self::source( self::TYPE_STRING, $all, true ),
			'tax.vat_exempt'        => self::source( self::TYPE_BOOL, $not_shipping ),
			'tax.has_tax'           => self::source( self::TYPE_BOOL, $not_shipping ),
		];
	}

	/**
	 * Look up a source definition, including dynamic `field:<key>` sources.
	 *
	 * @param string $source Source id.
	 * @return array{type: string, targets: list<string>, client: bool}|null
	 */
	public static function source_definition( string $source ): ?array {
		if ( self::is_field_source( $source ) ) {
			return self::source( self::TYPE_FIELD, self::ALL_TARGETS, true );
		}

		return self::sources()[ $source ] ?? null;
	}

	/**
	 * Whether the source reads a checkout field value.
	 *
	 * @param string $source Source id.
	 */
	public static function is_field_source( string $source ): bool {
		return 1 === preg_match( '/^field:[A-Za-z0-9_\-\[\]]+$/', $source );
	}

	/**
	 * Field key of a `field:<key>` source.
	 *
	 * @param string $source Source id.
	 */
	public static function field_key( string $source ): string {
		return substr( $source, 6 );
	}

	/**
	 * Definitions for the admin UI and the JS evaluator.
	 *
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		return [
			'actions'        => self::ACTIONS,
			'fixedKeys'      => self::FIXED_KEYS,
			'operators'      => self::OPERATORS,
			'operatorValues' => self::OPERATOR_VALUE,
			'sources'        => self::sources(),
			'fieldSource'    => self::source( self::TYPE_FIELD, self::ALL_TARGETS, true ),
		];
	}

	/**
	 * Build a source definition.
	 *
	 * @param string       $type    Value type.
	 * @param list<string> $targets Allowed targets.
	 * @param bool         $client  Readable in the browser.
	 * @return array{type: string, targets: list<string>, client: bool}
	 */
	private static function source( string $type, array $targets, bool $client = false ): array {
		return [
			'type'    => $type,
			'targets' => $targets,
			'client'  => $client,
		];
	}
}
