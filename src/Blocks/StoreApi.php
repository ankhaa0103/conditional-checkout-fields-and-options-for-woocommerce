<?php
/**
 * Checkout Block (Store API) server side.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Blocks;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use CCFO\Engine\Resolver;
use CCFO\Order\Restrictions;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Payment and shipping rules already apply to the Store API through the shared
 * WooCommerce filters (see Classic\Payments, Classic\Shipping). This class adds:
 *
 * - `require` field rules, enforced when the order is placed, for the fields the
 *   Checkout Block has (core address fields, email, order note);
 * - the chosen payment method pushed by the block script, so tax rules that read
 *   it are calculated on the server;
 * - `extensions.ccfo` cart data with the tax note decision and the order rule
 *   messages (SPEC §13), shown by build/blocks.js in the Cart and Checkout Blocks;
 * - order rules enforced when the order is placed.
 *
 * Show/hide of fields in the Checkout Block is not possible through official APIs
 * and is reported as "classic only" in the admin.
 */
final class StoreApi {

	public const NAMESPACE = 'ccfo';

	/**
	 * Address keys the Checkout Block sends.
	 */
	public const ADDRESS_KEYS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

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
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_order' ], 20, 2 );
		Plugin::when_blocks_loaded( [ $this, 'register_extensions' ] );
	}

	/**
	 * Register Store API endpoint data and the update callback.
	 */
	public function register_extensions(): void {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			woocommerce_store_api_register_endpoint_data(
				[
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => [ $this, 'cart_data' ],
					'schema_callback' => [ $this, 'cart_schema' ],
					'schema_type'     => ARRAY_A,
				]
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::NAMESPACE,
					'callback'  => [ $this, 'update_from_client' ],
				]
			);
		}
	}

	/**
	 * Data added to the cart response under `extensions.ccfo`.
	 *
	 * @return array<string, mixed>
	 */
	public function cart_data(): array {
		$context = $this->plugin->contexts->make();
		$rules   = $this->plugin->rules->index()->for_target( Definitions::TARGET_TAX_DISPLAY, Definitions::FIXED_KEYS[ Definitions::TARGET_TAX_DISPLAY ] );

		$restrictions = $this->plugin->restrictions;
		$has_orders   = $restrictions->has_rules();
		$cart         = $has_orders ? $restrictions->decide( Restrictions::STAGE_CART, $context ) : null;
		$checkout     = $has_orders ? $restrictions->decide( Restrictions::STAGE_CHECKOUT, $context ) : null;

		return [
			'taxNoteHidden'        => [] !== $rules && Resolver::flag( $rules, 'hide_tax_note', $context )->active,
			'checkoutBlocked'      => null !== $cart && $cart['blocked'] ? $cart['messages'] : [],
			'orderBlocked'         => null !== $checkout && $checkout['blocked'] ? $checkout['messages'] : [],
			'orderBlockedByMethod' => $has_orders ? $this->order_blocked_by_method() : (object) [],
			'paymentMethod'        => (string) $context->get( 'payment.method' ),
		];
	}

	/**
	 * Messages of the order stage for each payment method the customer can choose,
	 * when order rules read the payment method.
	 *
	 * The block script shows the messages of the selected method at once. Sending
	 * the selection to the server first (`extensionCartUpdate`) would not be needed
	 * for these rules, and its cart response can overwrite address input that
	 * WooCommerce has not sent yet.
	 *
	 * @return object Map of gateway id to messages ({} when not needed).
	 */
	private function order_blocked_by_method(): object {
		$sources = $this->plugin->rules->index()->sources( [ Definitions::TARGET_ORDER ] );
		if ( ! in_array( 'payment.method', $sources, true ) || ! WC()->payment_gateways() ) {
			return (object) [];
		}

		$out = [];
		foreach ( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) as $id ) {
			$decision            = $this->plugin->restrictions->decide( Restrictions::STAGE_CHECKOUT, $this->plugin->contexts->make( [ 'payment.method' => (string) $id ] ) );
			$out[ (string) $id ] = $decision['blocked'] ? $decision['messages'] : [];
		}
		return (object) $out;
	}

	/**
	 * Schema of the cart data.
	 *
	 * @return array<string, mixed>
	 */
	public function cart_schema(): array {
		$messages = static fn ( string $description ): array => [
			'description' => $description,
			'type'        => 'array',
			'items'       => [ 'type' => 'string' ],
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
		];
		return [
			'taxNoteHidden'        => [
				'description' => __( 'Whether a checkout rule hides the "includes tax" note.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
				'type'        => 'boolean',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'checkoutBlocked'      => $messages( __( 'Messages of the rules that block going to the checkout; empty when checkout is allowed.', 'conditional-checkout-fields-and-options-for-woocommerce' ) ),
			'orderBlocked'         => $messages( __( 'Messages of the rules that block placing the order; empty when the order can be placed.', 'conditional-checkout-fields-and-options-for-woocommerce' ) ),
			'paymentMethod'        => [
				'description' => __( 'The payment method the server used for rules that read it.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'orderBlockedByMethod' => [
				'description'          => __( 'The same messages for each payment method, when order rules read the payment method.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
				'type'                 => 'object',
				'additionalProperties' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'context'              => [ 'view', 'edit' ],
				'readonly'             => true,
			],
		];
	}

	/**
	 * Store the payment method chosen in the block, for rules that read it.
	 *
	 * Called by `extensionCartUpdate`; the Store API recalculates totals afterwards.
	 *
	 * @param mixed $data Client data.
	 */
	public function update_from_client( mixed $data ): void {
		if ( ! is_array( $data ) || ! WC()->session ) {
			return;
		}
		if ( isset( $data['payment_method'] ) && is_string( $data['payment_method'] ) ) {
			$method   = sanitize_key( $data['payment_method'] );
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( '' === $method || isset( $gateways[ $method ] ) ) {
				WC()->session->set( 'chosen_payment_method', $method );
			}
		}
	}

	/**
	 * Enforce order rules and `require` field rules when the Checkout Block places the order.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @throws RouteException When an order rule blocks the order or a required field is empty.
	 */
	public function validate_order( \WC_Order $order, \WP_REST_Request $request ): void {
		unset( $order );
		// The same action runs for draft updates (PUT/PATCH) while the customer fills in
		// the form; only placing the order (POST) is checked, as WooCommerce does for the
		// missing payment method.
		if ( 'POST' !== $request->get_method() ) {
			return;
		}
		$index        = $this->plugin->rules->index();
		$restrictions = $this->plugin->restrictions;
		if ( ! $index->has( Definitions::TARGET_FIELD ) && ! $restrictions->has_rules() ) {
			return;
		}

		$values  = self::request_values( $request );
		$context = $this->plugin->contexts->make( $values );

		if ( $restrictions->has_rules() ) {
			$decision = $restrictions->decide( Restrictions::STAGE_CHECKOUT, $context );
			if ( $decision['blocked'] ) {
				Restrictions::log( $decision['rules'], 'Checkout Block' );
				throw new RouteException( 'ccfo_order_blocked', esc_html( implode( ' ', $decision['messages'] ) ), 400 );
			}
		}

		foreach ( $index->keys( Definitions::TARGET_FIELD ) as $key ) {
			if ( ! array_key_exists( 'field:' . $key, $values ) ) {
				continue; // Not a field the Checkout Block has.
			}

			$decision = Resolver::field( $index->for_target( Definitions::TARGET_FIELD, $key ), false, $context );
			$this->plugin->log->record( 'field:' . $key, $decision );

			if ( $decision->active && $decision->required && '' === trim( (string) $values[ 'field:' . $key ] ) ) {
				throw new RouteException(
					'ccfo_required_field',
					sprintf(
						/* translators: %s: field name */
						esc_html__( '%s is a required field.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
						esc_html( self::field_label( $key ) )
					),
					400
				);
			}
		}
	}

	/**
	 * Source values taken from a Store API checkout request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function request_values( \WP_REST_Request $request ): array {
		$values = [];
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			$address = $request->get_param( $type . '_address' );
			if ( ! is_array( $address ) ) {
				continue;
			}
			$keys = 'billing' === $type ? array_merge( self::ADDRESS_KEYS, [ 'email' ] ) : self::ADDRESS_KEYS;
			foreach ( $keys as $key ) {
				$values[ "field:{$type}_{$key}" ] = isset( $address[ $key ] ) && is_scalar( $address[ $key ] ) ? (string) $address[ $key ] : '';
			}
			foreach ( [ 'country', 'state', 'postcode' ] as $key ) {
				$values[ "$type.$key" ] = $values[ "field:{$type}_{$key}" ];
			}
		}

		$note = $request->get_param( 'customer_note' );
		if ( null !== $note ) {
			$values['field:order_comments'] = is_scalar( $note ) ? (string) $note : '';
		}

		$payment = $request->get_param( 'payment_method' );
		if ( is_string( $payment ) ) {
			$values['payment.method'] = $payment;
		}

		return $values;
	}

	/**
	 * Human label of a block field.
	 *
	 * @param string $key Field key.
	 */
	private static function field_label( string $key ): string {
		$fields = WC()->countries ? WC()->countries->get_default_address_fields() : [];
		$short  = (string) preg_replace( '/^(billing|shipping)_/', '', $key );
		$label  = isset( $fields[ $short ]['label'] ) && is_string( $fields[ $short ]['label'] ) ? $fields[ $short ]['label'] : $key;

		if ( 'order_comments' === $key ) {
			$label = __( 'Order notes', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Same label WooCommerce prints.
		} elseif ( 'billing_email' === $key ) {
			$label = __( 'Email address', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Same label WooCommerce prints.
		}

		return wp_strip_all_tags( $label );
	}
}
