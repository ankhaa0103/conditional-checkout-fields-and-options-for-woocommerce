<?php
/**
 * SPEC §4 / §13 (1.1): amount sources in every request mode, checked against WooCommerce.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Engine\ContextFactory;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Cart: light ×1 (300 000) in a child of the fixture category, heavy ×2 (100 000) in the fixture category.
 */
final class AmountSourcesTest extends IntegrationTestCase {

	private const MODES = [ ContextFactory::RENDER, ContextFactory::AJAX, ContextFactory::SUBMIT, ContextFactory::STORE_API ];

	/**
	 * Child category of the fixture category.
	 *
	 * @var int
	 */
	private int $child = 0;

	/**
	 * Things to delete after the test.
	 *
	 * @var list<\WC_Data>
	 */
	private array $cleanup = [];

	/**
	 * Tax rate id created by a test.
	 *
	 * @var int
	 */
	private int $tax_rate = 0;

	/**
	 * Original woocommerce_calc_taxes value.
	 *
	 * @var mixed
	 */
	private mixed $calc_taxes = null;

	protected function setUp(): void {
		parent::setUp();
		$this->child = (int) wp_insert_term( 'CCFO Amount Child ' . uniqid(), 'product_cat', [ 'parent' => $this->parent_category() ] )['term_id'];
		$light       = wc_get_product( $this->fx['products']['light'] );
		$light->set_category_ids( [ $this->child ] );
		$light->save();
		$this->calc_taxes = get_option( 'woocommerce_calc_taxes' );
	}

	protected function tearDown(): void {
		WC()->cart->remove_coupons();
		WC()->cart->empty_cart();
		foreach ( array_reverse( $this->cleanup ) as $object ) {
			$object->delete( true );
		}
		$light = wc_get_product( $this->fx['products']['light'] );
		$light->set_category_ids( [] );
		$light->save();
		wp_delete_term( $this->child, 'product_cat' );
		if ( $this->tax_rate ) {
			\WC_Tax::_delete_tax_rate( $this->tax_rate );
		}
		update_option( 'woocommerce_calc_taxes', $this->calc_taxes );
		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * Fixture category (the heavy product's category).
	 */
	private function parent_category(): int {
		return (int) $this->fx['terms'][0]['id'];
	}

	/**
	 * Source value in a mode.
	 *
	 * @param string $source Source.
	 * @param string $mode   Mode.
	 */
	private function value( string $source, string $mode = ContextFactory::AJAX ): mixed {
		$this->mode( $mode );
		return Plugin::instance()->contexts->make()->get( $source );
	}

	/**
	 * Fill the cart with the free shipping rate chosen.
	 */
	private function cart(): void {
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['free'] ] );
		$this->add( 'light', 1 );
		$this->add( 'heavy', 2 );
	}

	/**
	 * Enable taxes with a 10 % rate for MN.
	 */
	private function taxes(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		$this->tax_rate = \WC_Tax::_insert_tax_rate(
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
	}

	/**
	 * A 10 % coupon applied to the cart.
	 *
	 * @param string $code Code.
	 */
	private function coupon( string $code ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$this->cleanup[] = $coupon;
		$this->assertTrue( WC()->cart->apply_coupon( $code ), 'coupon applies' );
		WC()->cart->calculate_totals();
	}

	public function test_amounts_equal_woocommerce_in_every_mode(): void {
		$this->taxes();
		$this->cart();
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['flat'] ] );
		$this->coupon( 'ccfo-amount-' . strtolower( uniqid() ) );
		$cart = WC()->cart;

		$expected = [
			'cart.subtotal'          => (float) $cart->get_subtotal(),
			'cart.subtotal_incl_tax' => (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax(),
			'cart.contents_total'    => (float) $cart->get_cart_contents_total(),
			'cart.discount_total'    => (float) $cart->get_discount_total(),
			'cart.shipping_total'    => (float) $cart->get_shipping_total(),
			'cart.tax_total'         => (float) $cart->get_total_tax(),
			'cart.total'             => (float) $cart->get_total( 'edit' ),
		];
		// The fixture numbers, so a wrong getter cannot pass by accident.
		$this->assertEqualsWithDelta( 500000.0, $expected['cart.subtotal'], 0.01 );
		$this->assertEqualsWithDelta( 550000.0, $expected['cart.subtotal_incl_tax'], 0.01 );
		$this->assertEqualsWithDelta( 50000.0, $expected['cart.discount_total'], 0.01 );
		$this->assertEqualsWithDelta( 450000.0, $expected['cart.contents_total'], 0.01 );
		$this->assertEqualsWithDelta( 5000.0, $expected['cart.shipping_total'], 0.01 );
		$this->assertEqualsWithDelta( 45500.0, $expected['cart.tax_total'], 0.01 );
		$this->assertEqualsWithDelta( 500500.0, $expected['cart.total'], 0.01 );

		foreach ( self::MODES as $mode ) {
			foreach ( $expected as $source => $value ) {
				$this->assertEqualsWithDelta( $value, $this->value( $source, $mode ), 0.001, "$source in $mode" );
			}
			$this->assertEqualsWithDelta( 300000.0, $this->value( 'cart.max_item_price', $mode ), 0.001, $mode );
			$this->assertEqualsWithDelta( 100000.0, $this->value( 'cart.min_item_price', $mode ), 0.001, $mode );
			$this->assertSame( $cart->get_applied_coupons(), $this->value( 'cart.coupons', $mode ), $mode );
		}
	}

	public function test_category_subtotal_counts_sub_categories_and_ignores_discounts(): void {
		$this->cart();
		$parent = 'cart.category_subtotal:' . $this->parent_category();
		$child  = 'cart.category_subtotal:' . $this->child;

		foreach ( self::MODES as $mode ) {
			$this->assertEqualsWithDelta( 500000.0, $this->value( $parent, $mode ), 0.001, "parent $mode" );
			$this->assertEqualsWithDelta( 300000.0, $this->value( $child, $mode ), 0.001, "child $mode" );
			$this->assertSame( 0.0, $this->value( 'cart.category_subtotal:999999999', $mode ), "unknown $mode" );
		}

		// Before discounts, like cart.subtotal.
		$this->coupon( 'ccfo-cat-' . strtolower( uniqid() ) );
		$this->assertEqualsWithDelta( 500000.0, $this->value( $parent ), 0.001 );
		$this->assertEqualsWithDelta( 450000.0, $this->value( 'cart.contents_total' ), 0.01 );
	}

	public function test_category_subtotal_of_variations_uses_the_parent_categories(): void {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( [ 'S', 'M' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'CCFO Variable' );
		$parent->set_category_ids( [ $this->child ] );
		$parent->set_attributes( [ $attribute ] );
		$parent->set_status( 'publish' );
		$parent->save();
		$this->cleanup[] = $parent;

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '70000' );
		$variation->set_status( 'publish' );
		$variation->save();
		$this->cleanup[] = $variation;

		$this->assertNotFalse( WC()->cart->add_to_cart( $parent->get_id(), 3, $variation->get_id(), [ 'attribute_size' => 'S' ] ) );
		WC()->cart->calculate_totals();

		$this->assertEqualsWithDelta( 210000.0, $this->value( 'cart.category_subtotal:' . $this->child ), 0.001 );
		$this->assertEqualsWithDelta( 210000.0, $this->value( 'cart.category_subtotal:' . $this->parent_category() ), 0.001 );
		$this->assertEqualsWithDelta( 70000.0, $this->value( 'cart.max_item_price' ), 0.001 );
		$this->assertContains( (string) $variation->get_id(), $this->value( 'cart.products' ) );
	}

	public function test_prices_entered_with_tax_are_used_without_tax(): void {
		$this->taxes();
		$include = get_option( 'woocommerce_prices_include_tax' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		try {
			$this->add( 'light' );
			// 300 000 including 10 % → 272 727.27 excluding tax, rounded to the store's
			// price decimals like cart.subtotal.
			$expected = round( 300000 / 1.1, wc_get_price_decimals() );
			$this->assertEqualsWithDelta( $expected, $this->value( 'cart.category_subtotal:' . $this->parent_category() ), 0.001 );
			$this->assertEqualsWithDelta( (float) WC()->cart->get_subtotal(), $this->value( 'cart.category_subtotal:' . $this->parent_category() ), 0.001 );
			$this->assertEqualsWithDelta( $expected, $this->value( 'cart.max_item_price' ), 0.001 );
			$this->assertEqualsWithDelta( 300000.0, $this->value( 'cart.subtotal_incl_tax' ), 0.01 );
		} finally {
			update_option( 'woocommerce_prices_include_tax', $include );
		}
	}

	public function test_empty_cart_values(): void {
		foreach ( [ 'cart.subtotal_incl_tax', 'cart.contents_total', 'cart.discount_total', 'cart.shipping_total', 'cart.tax_total', 'cart.max_item_price', 'cart.min_item_price', 'cart.category_subtotal:' . $this->parent_category() ] as $source ) {
			$this->assertSame( 0.0, $this->value( $source ), $source );
		}
		$this->assertSame( [], $this->value( 'cart.coupons' ) );
	}

	public function test_every_number_source_is_a_float_and_every_list_a_list(): void {
		$this->cart();
		foreach ( Definitions::sources() as $source => $definition ) {
			$value = $this->value( $source );
			if ( Definitions::is_money_source( $source ) ) {
				$this->assertIsFloat( $value, $source );
			}
			if ( Definitions::TYPE_LIST === $definition['type'] ) {
				$this->assertIsArray( $value, $source );
				$this->assertTrue( array_is_list( $value ), $source );
			}
		}
	}

	public function test_tax_rules_read_the_category_subtotal_of_the_cart_being_calculated(): void {
		// Tax calculation rules run before WooCommerce calculates totals; the category
		// subtotal comes from product prices, so the rule sees the new quantity at once.
		$this->taxes();
		$this->rules(
			[
				$this->rule(
					'exempt_heavy',
					'tax_calc',
					'cart',
					'set_vat_exempt',
					[
						[
							[
								'source'   => 'cart.category_subtotal:' . $this->parent_category(),
								'operator' => 'gte',
								'value'    => 300000,
							],
						],
					]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );

		$this->add( 'heavy', 2 );
		$this->assertGreaterThan( 0, (float) WC()->cart->get_total_tax(), '200 000: taxed' );

		WC()->cart->set_quantity( (string) array_key_first( WC()->cart->get_cart() ), 3 );
		$this->assertSame( 0.0, (float) WC()->cart->get_total_tax(), '300 000: exempt in the same calculation' );
		WC()->customer->set_is_vat_exempt( false );
	}

	public function test_shipping_rules_read_amounts_after_discounts(): void {
		// cart.contents_total is set before shipping is calculated in the same run.
		$this->rules(
			[
				$this->rule(
					'no_free_under',
					'shipping',
					'free_shipping',
					'disable',
					[
						[
							[
								'source'   => 'cart.contents_total',
								'operator' => 'lt',
								'value'    => 460000,
							],
						],
					]
				),
			]
		);
		$this->cart();
		$this->mode( ContextFactory::AJAX );
		$this->reset_shipping_cache();
		$this->assertContains( $this->fx['rates']['free'], $this->rate_ids(), '500 000 after discounts' );

		$this->coupon( 'ccfo-ship-' . strtolower( uniqid() ) );
		$this->assertNotContains( $this->fx['rates']['free'], $this->rate_ids(), '450 000 after discounts: cache refreshed' );
	}
}
