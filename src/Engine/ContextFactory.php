<?php
/**
 * Builds rule contexts from the current WooCommerce request.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

defined( 'ABSPATH' ) || exit;

use CCFO\Rules\Definitions;

/**
 * Knows where each source value comes from in each kind of request.
 *
 * Modes:
 * - render:    checkout page load; values from the session customer and cart.
 * - ajax:      classic `update_order_review`; field values from the serialised form.
 * - submit:    classic `checkout`; field values from the posted form.
 * - store_api: Store API (Checkout Block); values from the session customer and cart.
 *
 * A new Context is made for each decision batch, so cart values are never stale.
 */
final class ContextFactory {

	public const RENDER    = 'render';
	public const AJAX      = 'ajax';
	public const SUBMIT    = 'submit';
	public const STORE_API = 'store_api';

	/**
	 * Parsed posted data per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $posted = null;

	/**
	 * Mode override for tests and the Store API integration.
	 *
	 * @var string|null
	 */
	private ?string $forced_mode = null;

	/**
	 * Current request mode.
	 */
	public function mode(): string {
		if ( null !== $this->forced_mode ) {
			return $this->forced_mode;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- Read-only detection; WooCommerce verifies the nonce.
		$wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
		$action  = wp_doing_ajax() && isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		// phpcs:enable

		if ( 'checkout' === $wc_ajax || 'woocommerce_checkout' === $action ) {
			return self::SUBMIT;
		}
		if ( 'update_order_review' === $wc_ajax || 'woocommerce_update_order_review' === $action ) {
			return self::AJAX;
		}
		if ( function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) {
			return self::STORE_API;
		}
		return self::RENDER;
	}

	/**
	 * Force a mode (null to detect again) and forget parsed input.
	 *
	 * @param string|null          $mode   Mode.
	 * @param array<string, mixed> $posted Posted data to use.
	 */
	public function force( ?string $mode, ?array $posted = null ): void {
		$this->forced_mode = $mode;
		$this->posted      = $posted;
	}

	/**
	 * Make a context for the current request.
	 *
	 * @param array<string, mixed> $overrides Known values, e.g. from a Store API request body.
	 */
	public function make( array $overrides = [] ): Context {
		$mode   = $this->mode();
		$posted = $this->posted( $mode );

		return new Context(
			$overrides,
			fn ( string $source ): mixed => $this->value( $source, $mode, $posted )
		);
	}

	/**
	 * Posted form data for the mode.
	 *
	 * @param string $mode Mode.
	 * @return array<string, mixed>
	 */
	public function posted( string $mode ): array {
		if ( null !== $this->posted ) {
			return $this->posted;
		}

		$posted = [];
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonces.
		if ( self::SUBMIT === $mode ) {
			$posted = wc_clean( wp_unslash( $_POST ) );
		} elseif ( self::AJAX === $mode ) {
			if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
				parse_str( wp_unslash( $_POST['post_data'] ), $posted ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
				$posted = wc_clean( $posted );
			}
			if ( isset( $_POST['payment_method'] ) ) {
				$posted['payment_method'] = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
			}
		}
		// phpcs:enable

		$this->posted = is_array( $posted ) ? $posted : [];
		return $this->posted;
	}

	/**
	 * Compute one source value.
	 *
	 * @param string               $source Source id.
	 * @param string               $mode   Mode.
	 * @param array<string, mixed> $posted Posted data.
	 */
	private function value( string $source, string $mode, array $posted ): mixed {
		$from_form = self::SUBMIT === $mode || self::AJAX === $mode;

		if ( Definitions::is_field_source( $source ) ) {
			$key = Definitions::field_key( $source );
			if ( $from_form ) {
				return $posted[ $key ] ?? '';
			}
			return WC()->checkout()->get_value( $key ) ?? '';
		}

		$cart     = WC()->cart;
		$customer = WC()->customer;

		switch ( $source ) {
			case 'billing.country':
			case 'billing.state':
			case 'billing.postcode':
				$prop = substr( $source, 8 );
				if ( self::SUBMIT === $mode ) {
					return $posted[ 'billing_' . $prop ] ?? '';
				}
				return $customer ? $customer->{"get_billing_$prop"}() : '';

			case 'shipping.country':
			case 'shipping.state':
			case 'shipping.postcode':
				$prop = substr( $source, 9 );
				if ( self::SUBMIT === $mode ) {
					$prefix = ! empty( $posted['ship_to_different_address'] ) ? 'shipping_' : 'billing_';
					return $posted[ $prefix . $prop ] ?? '';
				}
				return $customer ? $customer->{"get_shipping_$prop"}() : '';

			case 'payment.method':
				if ( $from_form ) {
					return $posted['payment_method'] ?? '';
				}
				$chosen = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
				if ( '' === $chosen && self::STORE_API === $mode ) {
					// Nothing chosen yet: the Checkout Block selects the first available
					// method, so the server assumes the same and the block need not send it.
					$chosen = (string) ( array_key_first( WC()->payment_gateways()->get_available_payment_gateways() ) ?? '' );
				}
				return $chosen;

			case 'shipping.method':
				$chosen = self::SUBMIT === $mode && isset( $posted['shipping_method'] )
					? (array) $posted['shipping_method']
					: ( WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [] );
				return self::expand_rate_ids( $chosen );

			case 'customer.is_guest':
				return ! is_user_logged_in();

			case 'customer.role':
				return is_user_logged_in() ? array_values( wp_get_current_user()->roles ) : [];

			case 'tax.vat_exempt':
				return $customer ? (bool) $customer->get_is_vat_exempt() : false;
		}

		if ( ! $cart ) {
			return null;
		}

		if ( Definitions::is_category_subtotal_source( $source ) ) {
			return self::category_subtotal( $cart, Definitions::category_id( $source ) );
		}

		switch ( $source ) {
			case 'cart.subtotal':
				return (float) $cart->get_subtotal();
			case 'cart.subtotal_incl_tax':
				return (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
			case 'cart.contents_total':
				return (float) $cart->get_cart_contents_total();
			case 'cart.discount_total':
				return (float) $cart->get_discount_total();
			case 'cart.shipping_total':
				return (float) $cart->get_shipping_total();
			case 'cart.tax_total':
				return (float) $cart->get_total_tax();
			case 'cart.total':
				return (float) $cart->get_total( 'edit' );
			case 'cart.max_item_price':
			case 'cart.min_item_price':
				return self::item_price( $cart, 'cart.max_item_price' === $source );
			case 'cart.coupons':
				return array_values( array_map( 'strval', $cart->get_applied_coupons() ) );
			case 'cart.item_count':
				return count( $cart->get_cart() );
			case 'cart.qty':
				return (int) $cart->get_cart_contents_count();
			case 'cart.weight':
				return (float) $cart->get_cart_contents_weight();
			case 'cart.products':
			case 'cart.categories':
			case 'cart.tags':
			case 'cart.shipping_classes':
				return self::cart_terms( $cart, substr( $source, 5 ) );
			case 'tax.has_tax':
				return (float) $cart->get_total_tax() > 0;
		}

		return null;
	}

	/**
	 * Chosen rate ids plus their method ids, e.g. ["flat_rate:3", "flat_rate"].
	 *
	 * @param array<mixed> $rate_ids Rate ids.
	 * @return list<string>
	 */
	public static function expand_rate_ids( array $rate_ids ): array {
		$out = [];
		foreach ( $rate_ids as $rate_id ) {
			if ( ! is_string( $rate_id ) || '' === $rate_id ) {
				continue;
			}
			$out[] = $rate_id;
			$out[] = explode( ':', $rate_id )[0];
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Product, category, tag or shipping class ids in the cart.
	 *
	 * Variations count with their own id and their parent's id; terms come from the parent.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @param string   $what products|categories|tags|shipping_classes.
	 * @return list<string>
	 */
	private static function cart_terms( \WC_Cart $cart, string $what ): array {
		$ids = [];
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

			switch ( $what ) {
				case 'products':
					$ids[] = $product->get_id();
					$ids[] = $parent_id;
					break;
				case 'categories':
					array_push( $ids, ...self::category_ids( $parent_id ) );
					break;
				case 'tags':
					array_push( $ids, ...wc_get_product_term_ids( $parent_id, 'product_tag' ) );
					break;
				case 'shipping_classes':
					$ids[] = $product->get_shipping_class_id();
					break;
			}
		}

		$ids = array_filter( array_map( 'intval', $ids ) );
		return array_values( array_unique( array_map( 'strval', $ids ) ) );
	}

	/**
	 * Categories of a product, including the parents of its categories: a product
	 * in a sub-category also counts as being in the parent categories.
	 *
	 * @param int $product_id Product id (the parent for variations).
	 * @return list<int>
	 */
	private static function category_ids( int $product_id ): array {
		$ids = [];
		foreach ( wc_get_product_term_ids( $product_id, 'product_cat' ) as $term_id ) {
			$ids[] = (int) $term_id;
			array_push( $ids, ...array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Subtotal of the cart items in a category (sub-categories included):
	 * product price excluding tax × quantity, before discounts, each line rounded
	 * to the store's price decimals like the cart subtotal.
	 *
	 * Computed from product prices rather than from the cart totals, so the value is
	 * current even before WooCommerce calculates totals (tax calculation rules).
	 *
	 * @param \WC_Cart $cart    Cart.
	 * @param int      $term_id Category id.
	 */
	private static function category_subtotal( \WC_Cart $cart, int $term_id ): float {
		$sum = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			if ( in_array( $term_id, self::category_ids( $parent_id ), true ) ) {
				$sum += round( (float) wc_get_price_excluding_tax( $product, [ 'qty' => (int) ( $item['quantity'] ?? 1 ) ] ), wc_get_price_decimals() );
			}
		}
		return $sum;
	}

	/**
	 * Highest or lowest unit price (excluding tax, rounded to the store's price
	 * decimals) of the products in the cart; 0 for an empty cart.
	 *
	 * @param \WC_Cart $cart    Cart.
	 * @param bool     $highest Highest (true) or lowest (false).
	 */
	private static function item_price( \WC_Cart $cart, bool $highest ): float {
		$prices = [];
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$prices[] = round( (float) wc_get_price_excluding_tax( $product ), wc_get_price_decimals() );
			}
		}
		if ( [] === $prices ) {
			return 0.0;
		}
		return $highest ? max( $prices ) : min( $prices );
	}
}
