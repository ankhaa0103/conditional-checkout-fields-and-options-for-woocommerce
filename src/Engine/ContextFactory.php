<?php
/**
 * Builds rule contexts from the current WooCommerce request.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

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
				$posted['payment_method'] = wc_clean( wp_unslash( $_POST['payment_method'] ) );
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
				return WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';

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

		switch ( $source ) {
			case 'cart.subtotal':
				return (float) $cart->get_subtotal();
			case 'cart.total':
				return (float) $cart->get_total( 'edit' );
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
					// A product in a sub-category also counts as being in the parent categories.
					foreach ( wc_get_product_term_ids( $parent_id, 'product_cat' ) as $term_id ) {
						$ids[] = $term_id;
						array_push( $ids, ...get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
					}
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
}
