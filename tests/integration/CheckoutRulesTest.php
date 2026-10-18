<?php
/**
 * Rules against the real WooCommerce: fields, payment, shipping, orders, Store API.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Engine\ContextFactory;
use CCFO\Orders;
use CCFO\Plugin;

/**
 * SPEC §11 scenarios at the PHP level.
 */
final class CheckoutRulesTest extends IntegrationTestCase {

	/**
	 * Fresh checkout fields (WC_Checkout caches them per instance).
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function checkout_fields(): array {
		$checkout = WC()->checkout();
		$property = new \ReflectionProperty( $checkout, 'fields' );
		$property->setValue( $checkout, null );
		return $checkout->get_checkout_fields();
	}

	/**
	 * Available gateway ids.
	 *
	 * @return list<string>
	 */
	private function gateway_ids(): array {
		return array_values( array_map( 'strval', array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) ) );
	}

	public function test_a1_a3_show_rule_hides_field_on_submit_and_clears_value(): void {
		$this->rules(
			[
				$this->rule(
					'a1',
					'field',
					'billing_company',
					'show',
					[
						[
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'DE',
							],
						],
					]
				),
				$this->rule(
					'req',
					'field',
					'billing_company',
					'require',
					[
						[
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'DE',
							],
						],
					]
				),
			]
		);

		$this->mode(
			ContextFactory::SUBMIT,
			[
				'billing_country' => 'MN',
				'billing_company' => 'Acme',
			]
		);
		$fields = $this->checkout_fields();
		$this->assertTrue( $fields['billing']['billing_company']['hidden'] );
		$this->assertFalse( $fields['billing']['billing_company']['required'] );

		$data = apply_filters( 'woocommerce_checkout_posted_data', [ 'billing_company' => 'Acme' ] );
		$this->assertSame( '', $data['billing_company'] );

		$this->mode(
			ContextFactory::SUBMIT,
			[
				'billing_country' => 'DE',
				'billing_company' => '',
			]
		);
		$fields = $this->checkout_fields();
		$this->assertArrayNotHasKey( 'hidden', $fields['billing']['billing_company'] );
		$this->assertTrue( $fields['billing']['billing_company']['required'] );
	}

	public function test_render_mode_marks_hidden_fields_with_a_class(): void {
		$this->rules(
			[
				$this->rule(
					'h',
					'field',
					'billing_vat',
					'hide',
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
		$this->mode( ContextFactory::RENDER );
		$fields = $this->checkout_fields();

		$this->assertContains( 'ccfo-hidden', $fields['billing']['billing_vat']['class'] );
		$this->assertFalse( Plugin::instance()->fields->originals()['billing_vat']['required'] );
	}

	public function test_a2_require_third_party_field_when_company_filled(): void {
		$this->rules(
			[
				$this->rule(
					'a2',
					'field',
					'billing_vat',
					'require',
					[
						[
							[
								'source'   => 'field:billing_company',
								'operator' => 'not_empty',
							],
						],
					]
				),
			]
		);

		$this->mode( ContextFactory::SUBMIT, [ 'billing_company' => 'Acme' ] );
		$this->assertTrue( $this->checkout_fields()['billing']['billing_vat']['required'] );

		$this->mode( ContextFactory::SUBMIT, [ 'billing_company' => '' ] );
		$this->assertFalse( $this->checkout_fields()['billing']['billing_vat']['required'] );
	}

	public function test_a4_cod_removed_above_total(): void {
		$this->rules(
			[
				$this->rule(
					'a4',
					'payment',
					'cod',
					'disable',
					[
						[
							[
								'source'   => 'cart.total',
								'operator' => 'gt',
								'value'    => 500000,
							],
						],
					]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );

		$this->add( 'light' );
		$this->assertContains( 'cod', $this->gateway_ids() );

		$this->add( 'light', 1 );
		$this->assertNotContains( 'cod', $this->gateway_ids() );
		$this->assertContains( 'bacs', $this->gateway_ids() );
	}

	public function test_payment_rules_do_not_apply_outside_checkout(): void {
		$this->rules( [ $this->rule( 'all', 'payment', 'cod', 'disable' ) ] );
		$this->mode( ContextFactory::RENDER );
		// Not on the checkout page (CLI request): the rule must not touch the list.
		$this->assertContains( 'cod', $this->gateway_ids() );
	}

	public function test_a7_all_payment_methods_removed_is_reported(): void {
		$this->rules(
			[
				$this->rule( 'p1', 'payment', 'cod', 'disable' ),
				$this->rule( 'p2', 'payment', 'bacs', 'disable' ),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );

		$this->assertSame( [], $this->gateway_ids() );
		$this->assertTrue( Plugin::instance()->log->is_blocked( 'payment' ) );
		$this->assertStringContainsString( 'No payment method', (string) apply_filters( 'woocommerce_no_available_payment_methods_message', 'default' ) );

		$log = get_option( 'ccfo_blocked_log' );
		$this->assertSame( 'payment', $log[0]['type'] );
		$this->assertEqualsCanonicalizing( [ 'p1', 'p2' ], $log[0]['rules'] );
		delete_option( 'ccfo_blocked_log' );
	}

	public function test_a9_enable_rule_limits_method_to_country(): void {
		$this->rules(
			[
				$this->rule(
					'a9',
					'payment',
					'bacs',
					'enable',
					[
						[
							[
								'source'   => 'billing.country',
								'operator' => 'in',
								'value'    => [ 'MN' ],
							],
						],
					]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );

		$this->assertContains( 'bacs', $this->gateway_ids() );
		WC()->customer->set_billing_country( 'DE' );
		$this->assertNotContains( 'bacs', $this->gateway_ids() );
	}

	public function test_a5_free_shipping_removed_for_heavy_category(): void {
		$this->rules(
			[
				$this->rule(
					'a5',
					'shipping',
					'free_shipping',
					'disable',
					[
						[
							[
								'source'   => 'cart.categories',
								'operator' => 'any_in',
								'value'    => [ (string) $this->fx['terms'][0]['id'] ],
							],
						],
					]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );

		$this->add( 'light' );
		$this->assertContains( $this->fx['rates']['free'], $this->rate_ids() );

		$this->add( 'heavy' );
		$rates = $this->rate_ids();
		$this->assertNotContains( $this->fx['rates']['free'], $rates );
		$this->assertContains( $this->fx['rates']['flat'], $rates );
	}

	public function test_shipping_cache_is_refreshed_when_rule_input_changes(): void {
		// Only the posted field changes: cart, address and user stay the same, so
		// WooCommerce's own package hash is identical. Our hash must force a recalculation.
		$this->rules(
			[
				$this->rule(
					'vat',
					'shipping',
					$this->fx['rates']['flat'],
					'disable',
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

		$this->mode( ContextFactory::AJAX, [ 'billing_vat' => '' ] );
		$this->assertContains( $this->fx['rates']['flat'], $this->rate_ids() );

		$this->mode( ContextFactory::AJAX, [ 'billing_vat' => 'DE123' ] );
		$this->assertNotContains( $this->fx['rates']['flat'], $this->rate_ids() );

		$this->mode( ContextFactory::AJAX, [ 'billing_vat' => '' ] );
		$this->assertContains( $this->fx['rates']['flat'], $this->rate_ids() );
	}

	public function test_a6_role_based_rate(): void {
		$this->rules(
			[
				$this->rule(
					'a6',
					'shipping',
					$this->fx['rates']['flat'],
					'disable',
					[
						[
							[
								'source'   => 'customer.role',
								'operator' => 'any_in',
								'value'    => [ 'ccfo_wholesale' ],
							],
						],
					]
				),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );
		$this->assertContains( $this->fx['rates']['flat'], $this->rate_ids() );

		wp_set_current_user( (int) $this->fx['users']['wholesale'] );
		$this->assertNotContains( $this->fx['rates']['flat'], $this->rate_ids() );
	}

	public function test_all_rates_removed_shows_message_and_is_remembered(): void {
		$this->rules(
			[
				$this->rule(
					'ship',
					'shipping',
					'flat_rate',
					'enable',
					[
						[
							[
								'source'   => 'cart.qty',
								'operator' => 'gt',
								'value'    => 100,
							],
						],
					]
				),
				$this->rule( 'ship2', 'shipping', 'free_shipping', 'disable' ),
				$this->rule( 'ship3', 'shipping', 'local_pickup', 'disable' ),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );

		$this->assertSame( [], $this->rate_ids() );
		$this->assertStringContainsString( 'No shipping method', (string) apply_filters( 'woocommerce_no_shipping_available_html', 'default' ) );

		// A later request reads cached rates; the blocked state must survive.
		$shipping = new \CCFO\Classic\Shipping( Plugin::instance() );
		Plugin::instance()->log->unblock( 'shipping' );
		$this->assertTrue( $shipping->is_blocked() );
		delete_option( 'ccfo_blocked_log' );
	}

	public function test_order_records_applied_rules(): void {
		$this->rules(
			[
				$this->rule( 'pay', 'payment', 'cod', 'disable' ),
				$this->rule( 'shp', 'shipping', 'free_shipping', 'disable' ),
			]
		);
		$this->mode( ContextFactory::AJAX );
		$this->add( 'light' );
		$this->gateway_ids();
		$this->rate_ids();

		$order = new \WC_Order();
		( new Orders( Plugin::instance() ) )->store( $order );
		$meta = $order->get_meta( Orders::META_KEY );

		$this->assertSame( [ 'pay' ], $meta['payment:cod'] );
		$this->assertSame( [ 'shp' ], $meta[ 'shipping:' . $this->fx['rates']['free'] ] );
	}

	public function test_store_api_cart_uses_the_same_payment_rules(): void {
		$this->rules( [ $this->rule( 'sa', 'payment', 'cod', 'disable' ) ] );
		$this->add( 'light' );
		$this->mode( ContextFactory::STORE_API );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( 'cod', $data['payment_methods'] );
		$this->assertContains( 'bacs', $data['payment_methods'] );
		$extensions = json_decode( (string) wp_json_encode( $data['extensions'] ), true );
		$this->assertArrayHasKey( 'ccfo', $extensions );
		$this->assertFalse( $extensions['ccfo']['taxNoteHidden'] );
	}

	public function test_server_rules_that_read_form_values_are_sent_to_the_browser(): void {
		// Regression 0.1.1: a payment rule on a field value needs the classic script to refresh the checkout.
		$this->rules(
			[
				$this->rule(
					'bacs',
					'payment',
					'bacs',
					'enable',
					[
						[
							[
								'source'   => 'field:billing_company',
								'operator' => 'not_empty',
							],
						],
						[
							[
								'source'   => 'cart.total',
								'operator' => 'gt',
								'value'    => 1,
							],
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'MN',
							],
						],
					]
				),
				$this->rule(
					'tax',
					'tax_calc',
					'cart',
					'set_vat_exempt',
					[
						[
							[
								'source'   => 'payment.method',
								'operator' => 'equals',
								'value'    => 'bacs',
							],
						],
					]
				),
				$this->rule(
					'ship',
					'shipping',
					'flat_rate',
					'disable',
					[
						[
							[
								'source'   => 'field:billing_company',
								'operator' => 'not_empty',
							],
						],
					]
				),
			]
		);

		$triggers = ( new \CCFO\Classic\Assets( Plugin::instance() ) )->update_triggers();

		$this->assertSame(
			[
				[
					'source'   => 'field:billing_company',
					'operator' => 'not_empty',
				],
				[
					'source'   => 'billing.country',
					'operator' => 'equals',
					'value'    => 'MN',
				],
			],
			$triggers['conditions']
		);
		$this->assertTrue( $triggers['payment'] );
	}

	public function test_store_api_require_rule_is_enforced(): void {
		$this->rules(
			[
				$this->rule(
					'req',
					'field',
					'billing_company',
					'require',
					[
						[
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'DE',
							],
						],
					]
				),
			]
		);
		$store_api = new \CCFO\Blocks\StoreApi( Plugin::instance() );
		$this->mode( ContextFactory::STORE_API );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param(
			'billing_address',
			[
				'country' => 'DE',
				'company' => '',
			]
		);

		try {
			$store_api->validate_order( new \WC_Order(), $request );
			$this->fail( 'Expected a RouteException.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'ccfo_required_field', $e->getErrorCode() );
		}

		$request->set_param(
			'billing_address',
			[
				'country' => 'MN',
				'company' => '',
			]
		);
		$store_api->validate_order( new \WC_Order(), $request );
		$this->addToAssertionCount( 1 );
	}
}
