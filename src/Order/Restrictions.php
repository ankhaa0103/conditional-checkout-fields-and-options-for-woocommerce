<?php
/**
 * Order rules: block checkout or block placing the order (SPEC §13).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Order;

defined( 'ABSPATH' ) || exit;

use CCFO\Engine\Context;
use CCFO\Engine\Evaluator;
use CCFO\Plugin;
use CCFO\Rules\Definitions;
use CCFO\Rules\Rule;
use CCFO\Settings;

/**
 * Two stages:
 *
 * - cart:     `block_checkout` rules. The cart's "Proceed to checkout" button and the
 *             mini cart's "Checkout" button are replaced by a disabled button and the
 *             rule messages.
 * - checkout: `block_checkout` and `block_order` rules. The "Place order" button is
 *             disabled and the messages are shown above it; the order is refused on
 *             submit (classic checkout here, Store API in Blocks\StoreApi).
 *
 * Any matching rule blocks; priority only orders the messages. The buttons are a
 * convenience: the server refuses the order whatever the browser sends.
 */
final class Restrictions {

	public const STAGE_CART     = 'cart';
	public const STAGE_CHECKOUT = 'checkout';

	/**
	 * Callbacks removed while a blocked button area is printed, to put back afterwards.
	 *
	 * @var array<string, list<array{0: string, 1: int}>>
	 */
	private array $removed = [];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Cart page: the "Proceed to checkout" area (express payment buttons and the button).
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'cart_area_start' ], 1 );
		add_action( 'woocommerce_proceed_to_checkout', [ $this, 'cart_area_end' ], PHP_INT_MAX );
		// Mini cart (widget and theme mini carts that use the WooCommerce template).
		add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'mini_cart_start' ], 1 );
		add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'mini_cart_end' ], PHP_INT_MAX );
		// Classic checkout: express payment buttons, messages and the "Place order" button.
		add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'checkout_express_start' ], 1 );
		add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'checkout_express_end' ], PHP_INT_MAX );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'checkout_messages' ], 1 );
		add_filter( 'woocommerce_order_button_html', [ $this, 'order_button' ], 100 );
		// Classic checkout submit.
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 100, 2 );
		// Button and message styles on every page: the mini cart can be anywhere.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ], 30 );
	}

	/**
	 * Enqueue the plugin styles when order rules exist.
	 */
	public function enqueue_style(): void {
		if ( $this->has_rules() ) {
			wp_enqueue_style( 'ccfo-checkout', Plugin::url( 'assets/css/checkout.css' ), [], CCFO_VERSION );
		}
	}

	/**
	 * Actions that block a stage.
	 *
	 * @param string $stage Stage.
	 * @return list<string>
	 */
	public static function actions( string $stage ): array {
		return self::STAGE_CART === $stage ? [ 'block_checkout' ] : [ 'block_checkout', 'block_order' ];
	}

	/**
	 * Whether any enabled order rule exists.
	 */
	public function has_rules(): bool {
		return $this->plugin->rules->index()->has( Definitions::TARGET_ORDER );
	}

	/**
	 * Decide a stage.
	 *
	 * @param string       $stage   Stage.
	 * @param Context|null $context Context; a new one for the current request when null.
	 * @return array{blocked: bool, rules: list<string>, messages: list<string>}
	 */
	public function decide( string $stage, ?Context $context = null ): array {
		$rules = $this->plugin->rules->index()->for_target( Definitions::TARGET_ORDER, Definitions::FIXED_KEYS[ Definitions::TARGET_ORDER ] );
		if ( [] === $rules ) {
			return self::outcome( [], [] );
		}

		$context ??= $this->plugin->contexts->make();
		$actions   = self::actions( $stage );
		$ids       = [];
		$messages  = [];

		foreach ( self::ordered( $rules ) as $rule ) {
			if ( ! in_array( $rule->action, $actions, true ) ) {
				continue;
			}
			$group = Evaluator::matching_group( $rule, $context );
			if ( null === $group ) {
				continue;
			}
			$ids[]      = $rule->id;
			$messages[] = Messages::fill( self::message_text( $rule ), $group, $context, [ self::class, 'format' ] );
		}

		$outcome = self::outcome( $ids, $messages );
		if ( $outcome['blocked'] && [] === $outcome['messages'] ) {
			$outcome['messages'] = [ Settings::get( 'order_blocked_message' ) ];
		}
		return $outcome;
	}

	/**
	 * Cart: replace the checkout button area when checkout is blocked.
	 */
	public function cart_area_start(): void {
		$decision = $this->has_rules() ? $this->decide( self::STAGE_CART ) : null;
		if ( null === $decision || ! $decision['blocked'] ) {
			return;
		}

		$this->remove_callbacks( 'woocommerce_proceed_to_checkout', [ 'wc_get_pay_buttons', 'woocommerce_button_proceed_to_checkout' ] );

		echo self::messages_html( $decision['messages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in messages_html().
		printf(
			'<button type="button" class="checkout-button button alt wc-forward ccfo-checkout-disabled" disabled aria-disabled="true">%s</button>',
			esc_html__( 'Proceed to checkout', 'woocommerce' ) // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Same label WooCommerce prints.
		);
	}

	/**
	 * Cart: put removed callbacks back.
	 */
	public function cart_area_end(): void {
		$this->restore_callbacks( 'woocommerce_proceed_to_checkout' );
	}

	/**
	 * Mini cart: replace the "Checkout" button when checkout is blocked.
	 */
	public function mini_cart_start(): void {
		$decision = $this->has_rules() ? $this->decide( self::STAGE_CART ) : null;
		if ( null === $decision || ! $decision['blocked'] ) {
			return;
		}

		$this->remove_callbacks( 'woocommerce_widget_shopping_cart_buttons', [ 'woocommerce_widget_shopping_cart_proceed_to_checkout' ] );

		// The view cart button stays; the messages follow the buttons.
		add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'mini_cart_disabled_button' ], 20 );
	}

	/**
	 * Mini cart: disabled checkout button and messages.
	 */
	public function mini_cart_disabled_button(): void {
		$decision = $this->decide( self::STAGE_CART );
		printf(
			'<button type="button" class="button checkout wc-forward ccfo-checkout-disabled" disabled aria-disabled="true">%s</button>',
			esc_html__( 'Checkout', 'woocommerce' ) // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Same label WooCommerce prints.
		);
		echo self::messages_html( $decision['messages'], 'ccfo-order-blocked--mini' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in messages_html().
	}

	/**
	 * Mini cart: put removed callbacks back.
	 */
	public function mini_cart_end(): void {
		remove_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'mini_cart_disabled_button' ], 20 );
		$this->restore_callbacks( 'woocommerce_widget_shopping_cart_buttons' );
	}

	/**
	 * Classic checkout page: no express payment buttons when the order is blocked.
	 */
	public function checkout_express_start(): void {
		if ( $this->has_rules() && $this->decide( self::STAGE_CHECKOUT )['blocked'] ) {
			$this->remove_callbacks( 'woocommerce_checkout_before_customer_details', [ 'wc_get_pay_buttons' ] );
		}
	}

	/**
	 * Classic checkout page: put removed callbacks back.
	 */
	public function checkout_express_end(): void {
		$this->restore_callbacks( 'woocommerce_checkout_before_customer_details' );
	}

	/**
	 * Classic checkout: messages above the "Place order" button. The payment
	 * section is rendered again on every `update_checkout`, so they stay current.
	 */
	public function checkout_messages(): void {
		if ( ! $this->has_rules() ) {
			return;
		}
		$decision = $this->decide( self::STAGE_CHECKOUT );
		if ( $decision['blocked'] ) {
			echo self::messages_html( $decision['messages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in messages_html().
		}
	}

	/**
	 * Classic checkout: disable the "Place order" button.
	 *
	 * @param mixed $html Button HTML.
	 * @return mixed
	 */
	public function order_button( mixed $html ): mixed {
		if ( ! is_string( $html ) || ! $this->has_rules() || ! $this->decide( self::STAGE_CHECKOUT )['blocked'] ) {
			return $html;
		}
		return (string) preg_replace( '/<button\b/i', '<button disabled aria-disabled="true" data-ccfo-blocked="1"', $html, 1 );
	}

	/**
	 * Classic checkout submit: refuse a blocked order.
	 *
	 * @param array<string, mixed> $data   Posted data.
	 * @param \WP_Error            $errors Errors.
	 */
	public function validate_checkout( array $data, \WP_Error $errors ): void {
		unset( $data );
		if ( ! $this->has_rules() ) {
			return;
		}
		$decision = $this->decide( self::STAGE_CHECKOUT );
		if ( ! $decision['blocked'] ) {
			return;
		}
		foreach ( $decision['messages'] as $message ) {
			$errors->add( 'ccfo_order_blocked', esc_html( $message ) );
		}
		self::log( $decision['rules'], 'classic checkout' );
	}

	/**
	 * Record a refused order in the WooCommerce log.
	 *
	 * @param list<string> $rule_ids Rules.
	 * @param string       $where    Checkout type.
	 */
	public static function log( array $rule_ids, string $where ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'Order refused in the %s by rules %s.', $where, implode( ', ', $rule_ids ) ),
				[ 'source' => 'ccfo' ]
			);
		}
	}

	/**
	 * Number formatter for message placeholders: prices for money sources, the
	 * weight unit for the cart weight, plain numbers otherwise.
	 *
	 * @param string $source Source.
	 * @param float  $value  Value.
	 */
	public static function format( string $source, float $value ): string {
		if ( Definitions::is_money_source( $source ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' );
		}
		$text = wc_format_localized_decimal( wc_format_decimal( $value, false, true ) );
		if ( 'cart.weight' === $source ) {
			$text .= ' ' . get_option( 'woocommerce_weight_unit' );
		}
		return $text;
	}

	/**
	 * Message HTML.
	 *
	 * @param list<string> $messages Messages.
	 * @param string       $modifier Extra class.
	 */
	public static function messages_html( array $messages, string $modifier = '' ): string {
		$html = '';
		foreach ( $messages as $message ) {
			$html .= sprintf(
				'<div class="woocommerce-info ccfo-order-blocked %s" role="status">%s</div>',
				esc_attr( $modifier ),
				esc_html( $message )
			);
		}
		return $html;
	}

	/**
	 * Customer message of a rule, or the default one.
	 *
	 * @param Rule $rule Rule.
	 */
	private static function message_text( Rule $rule ): string {
		$message = isset( $rule->args['message'] ) && is_string( $rule->args['message'] ) ? trim( $rule->args['message'] ) : '';
		return '' !== $message ? $message : Settings::get( 'order_blocked_message' );
	}

	/**
	 * Outcome array.
	 *
	 * @param list<string> $ids      Rule ids.
	 * @param list<string> $messages Messages.
	 * @return array{blocked: bool, rules: list<string>, messages: list<string>}
	 */
	private static function outcome( array $ids, array $messages ): array {
		return [
			'blocked'  => [] !== $ids,
			'rules'    => $ids,
			'messages' => array_values( array_unique( array_filter( $messages, static fn ( string $m ): bool => '' !== trim( $m ) ) ) ),
		];
	}

	/**
	 * Enabled rules by priority, keeping list order on ties.
	 *
	 * @param list<Rule> $rules Rules.
	 * @return list<Rule>
	 */
	private static function ordered( array $rules ): array {
		$order = array_flip( array_map( static fn ( Rule $r ): string => $r->id, $rules ) );
		usort(
			$rules,
			static fn ( Rule $a, Rule $b ): int => [ $a->priority, $order[ $a->id ] ] <=> [ $b->priority, $order[ $b->id ] ]
		);
		return $rules;
	}

	/**
	 * Remove callbacks from an action for the rest of this run of it, at whatever
	 * priority they are registered.
	 *
	 * @param string       $hook      Action.
	 * @param list<string> $callbacks Function names.
	 */
	private function remove_callbacks( string $hook, array $callbacks ): void {
		foreach ( $callbacks as $callback ) {
			$priority = has_action( $hook, $callback );
			if ( is_int( $priority ) && remove_action( $hook, $callback, $priority ) ) {
				$this->removed[ $hook ][] = [ $callback, $priority ];
			}
		}
	}

	/**
	 * Put back callbacks removed from an action.
	 *
	 * @param string $hook Action.
	 */
	private function restore_callbacks( string $hook ): void {
		foreach ( $this->removed[ $hook ] ?? [] as [ $callback, $priority ] ) {
			add_action( $hook, $callback, $priority );
		}
		unset( $this->removed[ $hook ] );
	}
}
