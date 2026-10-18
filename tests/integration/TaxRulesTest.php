<?php
/**
 * Tax calculation and display rules against WooCommerce's tax engine.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Engine\ContextFactory;
use CCFO\Plugin;

/**
 * Turns taxes on for each test and restores the store afterwards.
 */
final class TaxRulesTest extends IntegrationTestCase {

	/**
	 * Created tax rate ids.
	 *
	 * @var list<int>
	 */
	private array $rate_ids = [];

	/**
	 * Original option values.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		foreach ( [ 'woocommerce_calc_taxes', 'woocommerce_tax_display_cart', 'woocommerce_prices_include_tax' ] as $name ) {
			$this->options[ $name ] = get_option( $name );
		}
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		\WC_Tax::create_tax_class( 'CCFO Zero', 'ccfo-zero' );
		$this->rate_ids[] = \WC_Tax::_insert_tax_rate(
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
		$this->rate_ids[] = \WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'MN',
				'tax_rate'          => '0.0000',
				'tax_rate_name'     => 'Zero',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => 'ccfo-zero',
			]
		);

		parent::setUp();
		WC()->session->set( 'ccfo_vat_exempt', null );
	}

	protected function tearDown(): void {
		parent::tearDown();
		foreach ( $this->rate_ids as $id ) {
			\WC_Tax::_delete_tax_rate( $id );
		}
		\WC_Tax::delete_tax_class_by( 'slug', 'ccfo-zero' );
		foreach ( $this->options as $name => $value ) {
			update_option( $name, $value );
		}
		WC()->customer->set_is_vat_exempt( false );
	}

	/**
	 * Cart tax after recalculation.
	 */
	private function tax(): float {
		WC()->cart->calculate_totals();
		return (float) WC()->cart->get_total_tax();
	}

	public function test_store_tax_is_calculated_without_rules(): void {
		$this->add( 'light' );
		$this->assertEqualsWithDelta( 30000.0, $this->tax(), 0.01 );
	}

	public function test_a12_vat_exempt_follows_the_rule_and_releases_only_its_own_exemption(): void {
		$this->rules(
			[
				$this->rule(
					'a12',
					'tax_calc',
					'cart',
					'set_vat_exempt',
					[
						[
							[
								'source'   => 'field:billing_vat',
								'operator' => 'not_empty',
							],
						],
					]
				),
			]
		);
		$this->add( 'light' );

		$this->mode( ContextFactory::AJAX, [ 'billing_vat' => 'MN123' ] );
		$this->assertEqualsWithDelta( 0.0, $this->tax(), 0.01 );
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );

		$this->mode( ContextFactory::AJAX, [ 'billing_vat' => '' ] );
		$this->assertEqualsWithDelta( 30000.0, $this->tax(), 0.01 );
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );

		// An exemption set by other code is left alone.
		WC()->customer->set_is_vat_exempt( true );
		$this->assertEqualsWithDelta( 0.0, $this->tax(), 0.01 );
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
	}

	public function test_tax_class_switch_uses_existing_class_and_marks_line_items(): void {
		$this->rules(
			[
				$this->rule(
					'tc',
					'tax_calc',
					'cart',
					'set_tax_class',
					[
						[
							[
								'source'   => 'customer.role',
								'operator' => 'any_in',
								'value'    => [ 'ccfo_wholesale' ],
							],
						],
					],
					[ 'args' => [ 'tax_class' => 'ccfo-zero' ] ]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );
		$this->assertEqualsWithDelta( 30000.0, $this->tax(), 0.01 );

		wp_set_current_user( (int) $this->fx['users']['wholesale'] );
		$this->assertEqualsWithDelta( 0.0, $this->tax(), 0.01 );

		$item = new \WC_Order_Item_Product();
		do_action( 'woocommerce_checkout_create_order_line_item', $item, '', [], new \WC_Order() );
		$this->assertSame( 'ccfo-zero', $item->get_tax_class() );
	}

	public function test_tax_rules_do_nothing_when_taxes_are_off(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->rules( [ $this->rule( 'ex', 'tax_calc', 'cart', 'set_vat_exempt' ) ] );
		$this->add( 'light' );
		$this->tax();
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
	}

	public function test_a11_tax_note_flag(): void {
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		$this->rules(
			[
				$this->rule(
					'a11',
					'tax_display',
					'checkout',
					'hide_tax_note',
					[
						[
							[
								'source'   => 'customer.is_guest',
								'operator' => 'is_true',
							],
						],
					]
				),
			]
		);
		$this->add( 'light' );
		$total_before = WC()->cart->get_total( 'edit' );

		$assets = new \CCFO\Classic\Assets( Plugin::instance() );
		$this->assertTrue( $assets->tax_note_hidden( Plugin::instance()->contexts->make() ) );
		$this->assertSame( $total_before, WC()->cart->get_total( 'edit' ) );
		$this->assertEqualsWithDelta( 30000.0, $this->tax(), 0.01 );

		wp_set_current_user( (int) $this->fx['users']['customer'] );
		$this->assertFalse( $assets->tax_note_hidden( Plugin::instance()->contexts->make() ) );
	}
}
