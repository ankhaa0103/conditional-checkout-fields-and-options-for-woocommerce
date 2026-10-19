<?php
/**
 * SPEC 1.7: every §4 source gives the right value in every request mode.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Engine\ContextFactory;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Real cart, customer and session values through ContextFactory.
 */
final class SourcesTest extends IntegrationTestCase {

	/**
	 * Terms created for the test.
	 *
	 * @var array<string, int>
	 */
	private array $terms = [];

	protected function setUp(): void {
		parent::setUp();

		$parent      = (int) $this->fx['terms'][0]['id'];
		$child       = wp_insert_term( 'CCFO Child ' . uniqid(), 'product_cat', [ 'parent' => $parent ] );
		$tag         = wp_insert_term( 'ccfo-tag-' . uniqid(), 'product_tag' );
		$class       = wp_insert_term( 'ccfo-class-' . uniqid(), 'product_shipping_class' );
		$this->terms = [
			'child' => (int) $child['term_id'],
			'tag'   => (int) $tag['term_id'],
			'class' => (int) $class['term_id'],
		];
		$light       = wc_get_product( $this->fx['products']['light'] );
		$light->set_category_ids( [ $this->terms['child'] ] );
		$light->set_tag_ids( [ $this->terms['tag'] ] );
		$light->save();
		$heavy = wc_get_product( $this->fx['products']['heavy'] );
		$heavy->set_shipping_class_id( $this->terms['class'] );
		$heavy->save();
	}

	protected function tearDown(): void {
		$light = wc_get_product( $this->fx['products']['light'] );
		$light->set_category_ids( [] );
		$light->set_tag_ids( [] );
		$light->save();
		$heavy = wc_get_product( $this->fx['products']['heavy'] );
		$heavy->set_shipping_class_id( 0 );
		$heavy->save();
		wp_delete_term( $this->terms['child'], 'product_cat' );
		wp_delete_term( $this->terms['tag'], 'product_tag' );
		wp_delete_term( $this->terms['class'], 'product_shipping_class' );
		WC()->customer->set_billing_company( '' );
		WC()->customer->set_billing_state( '' );
		WC()->customer->set_billing_postcode( '' );
		WC()->customer->set_shipping_state( '' );
		WC()->customer->set_shipping_postcode( '' );
		parent::tearDown();
	}

	/**
	 * Fill the cart: light ×1 (300 000, 1 kg), heavy ×2 (100 000, 20 kg).
	 */
	private function cart(): void {
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['free'] ] );
		$this->add( 'light', 1 );
		$this->add( 'heavy', 2 );
		WC()->cart->calculate_totals();
	}

	/**
	 * A source value in a mode.
	 *
	 * @param string               $source Source.
	 * @param string               $mode   Mode.
	 * @param array<string, mixed> $posted Posted data.
	 */
	private function value( string $source, string $mode, array $posted = [] ): mixed {
		$this->mode( $mode, $posted );
		return Plugin::instance()->contexts->make()->get( $source );
	}

	public function test_every_spec_source_is_resolvable(): void {
		$this->cart();
		foreach ( array_keys( Definitions::sources() ) as $source ) {
			$this->assertNotNull( $this->value( $source, ContextFactory::AJAX ), "$source has a value" );
		}
	}

	public function test_cart_sources(): void {
		$this->cart();
		foreach ( [ ContextFactory::RENDER, ContextFactory::AJAX, ContextFactory::SUBMIT, ContextFactory::STORE_API ] as $mode ) {
			$this->assertEqualsWithDelta( 500000.0, $this->value( 'cart.subtotal', $mode ), 0.001, $mode );
			$this->assertEqualsWithDelta( 500000.0, $this->value( 'cart.total', $mode ), 0.001, $mode );
			$this->assertSame( 2, $this->value( 'cart.item_count', $mode ) );
			$this->assertSame( 3, $this->value( 'cart.qty', $mode ) );
			$this->assertEqualsWithDelta( 41.0, $this->value( 'cart.weight', $mode ), 0.001 );

			$products = $this->value( 'cart.products', $mode );
			$this->assertEqualsCanonicalizing( [ (string) $this->fx['products']['light'], (string) $this->fx['products']['heavy'] ], $products );

			$categories = $this->value( 'cart.categories', $mode );
			$this->assertContains( (string) $this->terms['child'], $categories );
			// A sub-category also counts as its parent (heavy's own category and the child's parent are the same).
			$this->assertContains( (string) $this->fx['terms'][0]['id'], $categories );

			$this->assertSame( [ (string) $this->terms['tag'] ], $this->value( 'cart.tags', $mode ) );
			$this->assertSame( [ (string) $this->terms['class'] ], $this->value( 'cart.shipping_classes', $mode ) );
		}
	}

	public function test_parent_category_counts_for_products_in_a_child_category(): void {
		// Only the light product (child category) in the cart.
		$this->add( 'light' );
		$this->assertContains( (string) $this->fx['terms'][0]['id'], $this->value( 'cart.categories', ContextFactory::AJAX ) );
	}

	public function test_empty_cart_values(): void {
		foreach ( [ 'cart.products', 'cart.categories', 'cart.tags', 'cart.shipping_classes' ] as $source ) {
			$this->assertSame( [], $this->value( $source, ContextFactory::AJAX ), $source );
		}
		$this->assertSame( 0, $this->value( 'cart.qty', ContextFactory::AJAX ) );
	}

	public function test_field_values_per_mode(): void {
		WC()->customer->set_billing_company( 'Saved Co' );
		$this->assertSame( 'Posted Co', $this->value( 'field:billing_company', ContextFactory::SUBMIT, [ 'billing_company' => 'Posted Co' ] ) );
		$this->assertSame( 'Ajax Co', $this->value( 'field:billing_company', ContextFactory::AJAX, [ 'billing_company' => 'Ajax Co' ] ) );
		// A field missing from the posted form is empty (unchecked box, hidden input), not the saved value.
		$this->assertSame( '', $this->value( 'field:billing_company', ContextFactory::SUBMIT, [] ) );
		$this->assertSame( 'Saved Co', $this->value( 'field:billing_company', ContextFactory::RENDER ) );
		$this->assertSame( 'Saved Co', $this->value( 'field:billing_company', ContextFactory::STORE_API ) );
	}

	public function test_address_values_per_mode(): void {
		WC()->customer->set_billing_country( 'DE' );
		WC()->customer->set_billing_state( 'BE' );
		WC()->customer->set_billing_postcode( '10115' );
		WC()->customer->set_shipping_country( 'FR' );
		WC()->customer->set_shipping_state( '' );
		WC()->customer->set_shipping_postcode( '75001' );

		foreach ( [ ContextFactory::RENDER, ContextFactory::AJAX, ContextFactory::STORE_API ] as $mode ) {
			$this->assertSame( 'DE', $this->value( 'billing.country', $mode ), $mode );
			$this->assertSame( 'BE', $this->value( 'billing.state', $mode ) );
			$this->assertSame( '10115', $this->value( 'billing.postcode', $mode ) );
			$this->assertSame( 'FR', $this->value( 'shipping.country', $mode ) );
			$this->assertSame( '75001', $this->value( 'shipping.postcode', $mode ) );
		}

		$posted = [
			'billing_country'   => 'MN',
			'billing_state'     => 'UB',
			'billing_postcode'  => '14200',
			'shipping_country'  => 'JP',
			'shipping_state'    => 'JP13',
			'shipping_postcode' => '100-0001',
		];
		// Submit without "ship to a different address": shipping = billing.
		$this->assertSame( 'MN', $this->value( 'billing.country', ContextFactory::SUBMIT, $posted ) );
		$this->assertSame( 'MN', $this->value( 'shipping.country', ContextFactory::SUBMIT, $posted ) );
		$this->assertSame( 'UB', $this->value( 'shipping.state', ContextFactory::SUBMIT, $posted ) );
		$this->assertSame( '14200', $this->value( 'shipping.postcode', ContextFactory::SUBMIT, $posted ) );

		$posted['ship_to_different_address'] = '1';
		$this->assertSame( 'JP', $this->value( 'shipping.country', ContextFactory::SUBMIT, $posted ) );
		$this->assertSame( 'JP13', $this->value( 'shipping.state', ContextFactory::SUBMIT, $posted ) );
		$this->assertSame( '100-0001', $this->value( 'shipping.postcode', ContextFactory::SUBMIT, $posted ) );
	}

	public function test_chosen_methods_per_mode(): void {
		WC()->session->set( 'chosen_payment_method', 'bacs' );
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['flat'] ] );

		$this->assertSame( 'bacs', $this->value( 'payment.method', ContextFactory::RENDER ) );
		$this->assertSame( 'bacs', $this->value( 'payment.method', ContextFactory::STORE_API ) );
		$this->assertSame( 'cod', $this->value( 'payment.method', ContextFactory::AJAX, [ 'payment_method' => 'cod' ] ) );
		$this->assertSame( 'cod', $this->value( 'payment.method', ContextFactory::SUBMIT, [ 'payment_method' => 'cod' ] ) );

		$flat = $this->fx['rates']['flat'];
		$this->assertSame( [ $flat, 'flat_rate' ], $this->value( 'shipping.method', ContextFactory::RENDER ) );
		$this->assertSame( [ $flat, 'flat_rate' ], $this->value( 'shipping.method', ContextFactory::AJAX ) );
		$pickup = $this->fx['rates']['pickup'];
		$this->assertSame( [ $pickup, 'local_pickup' ], $this->value( 'shipping.method', ContextFactory::SUBMIT, [ 'shipping_method' => [ $pickup ] ] ) );

		// Nothing chosen yet: the Checkout Block selects the first available method, and
		// the Store API assumes the same (1.1); the classic page load keeps "nothing".
		WC()->session->set( 'chosen_payment_method', '' );
		$this->add( 'light' );
		$this->mode( ContextFactory::STORE_API );
		$first = (string) array_key_first( WC()->payment_gateways()->get_available_payment_gateways() );
		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $this->value( 'payment.method', ContextFactory::STORE_API ) );
		$this->assertSame( '', $this->value( 'payment.method', ContextFactory::RENDER ) );
		$this->mode( ContextFactory::STORE_API );
		$cart = json_decode( (string) wp_json_encode( rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data() ), true );
		$this->assertSame( $first, $cart['extensions']['ccfo']['paymentMethod'] );
		WC()->session->set( 'chosen_payment_method', 'bacs' );
		$this->assertSame( 'bacs', $this->value( 'payment.method', ContextFactory::STORE_API ) );
	}

	public function test_customer_values(): void {
		$this->assertTrue( $this->value( 'customer.is_guest', ContextFactory::AJAX ) );
		$this->assertSame( [], $this->value( 'customer.role', ContextFactory::AJAX ) );

		wp_set_current_user( (int) $this->fx['users']['wholesale'] );
		$this->assertFalse( $this->value( 'customer.is_guest', ContextFactory::AJAX ) );
		$this->assertSame( [ 'ccfo_wholesale' ], $this->value( 'customer.role', ContextFactory::AJAX ) );
	}

	public function test_tax_values(): void {
		$this->add( 'light' );
		$this->assertFalse( $this->value( 'tax.vat_exempt', ContextFactory::AJAX ) );
		$this->assertFalse( $this->value( 'tax.has_tax', ContextFactory::AJAX ) );

		WC()->customer->set_is_vat_exempt( true );
		$this->assertTrue( $this->value( 'tax.vat_exempt', ContextFactory::AJAX ) );
		WC()->customer->set_is_vat_exempt( false );

		$calc = get_option( 'woocommerce_calc_taxes' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		$rate = \WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'MN',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			]
		);
		try {
			WC()->cart->calculate_totals();
			$this->assertTrue( $this->value( 'tax.has_tax', ContextFactory::AJAX ) );
		} finally {
			\WC_Tax::_delete_tax_rate( $rate );
			update_option( 'woocommerce_calc_taxes', $calc );
		}
	}

	public function test_request_mode_detection(): void {
		Plugin::instance()->contexts->force( null );
		$factory = Plugin::instance()->contexts;
		$get     = $_GET;
		$uri     = $_SERVER['REQUEST_URI'];
		try {
			$_GET = [ 'wc-ajax' => 'checkout' ];
			$this->assertSame( ContextFactory::SUBMIT, $factory->mode() );
			$_GET = [ 'wc-ajax' => 'update_order_review' ];
			$this->assertSame( ContextFactory::AJAX, $factory->mode() );
			$_GET                   = [];
			$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
			$this->assertSame( ContextFactory::STORE_API, $factory->mode() );
			$_SERVER['REQUEST_URI'] = '/checkout/';
			$this->assertSame( ContextFactory::RENDER, $factory->mode() );
		} finally {
			$_GET                   = $get;
			$_SERVER['REQUEST_URI'] = $uri;
		}
	}
}
