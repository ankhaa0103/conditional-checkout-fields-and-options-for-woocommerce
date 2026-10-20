<?php
/**
 * SPEC §13 (1.1): order rules through the real WooCommerce hooks and the Store API.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use CCFO\Blocks\StoreApi;
use CCFO\Engine\ContextFactory;
use CCFO\Order\Restrictions;
use CCFO\Plugin;
use CCFO\Settings;

/**
 * Light ×1 = 300 000 unless a test says otherwise.
 */
final class OrderRestrictionsTest extends IntegrationTestCase {

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * Groups that match or not for a 300 000 cart.
	 *
	 * @param bool $matched Matched.
	 * @return list<list<array<string, mixed>>>
	 */
	private static function when( bool $matched ): array {
		return [
			[
				[
					'source'   => 'cart.subtotal',
					'operator' => 'lt',
					'value'    => $matched ? 400000 : 200000,
				],
			],
		];
	}

	/**
	 * An order rule.
	 *
	 * @param string                           $id      Id.
	 * @param string                           $action  Action.
	 * @param list<list<array<string, mixed>>> $groups  Groups.
	 * @param string                           $message Message.
	 * @param array<string, mixed>             $extra   Extra props.
	 * @return array<string, mixed>
	 */
	private function order_rule( string $id, string $action, array $groups, string $message = '', array $extra = [] ): array {
		return $this->rule( $id, 'order', 'order', $action, $groups, array_merge( [ 'args' => [ 'message' => $message ] ], $extra ) );
	}

	/**
	 * Output of an action.
	 *
	 * @param string $hook Action.
	 */
	private static function render( string $hook ): string {
		ob_start();
		do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing WooCommerce's own actions in a test.
		return (string) ob_get_clean();
	}

	/**
	 * Run classic checkout validation like WC_Checkout::process_checkout().
	 *
	 * @param string $payment Payment method.
	 * @return list<string> Error codes.
	 */
	private function classic_validate( string $payment = 'bacs' ): array {
		$this->mode( ContextFactory::SUBMIT, [ 'payment_method' => $payment ] );
		$data     = [
			'payment_method'            => $payment,
			'ship_to_different_address' => false,
			'terms'                     => 1,
		];
		$errors   = new \WP_Error();
		$validate = new \ReflectionMethod( WC()->checkout(), 'validate_checkout' );
		$validate->invokeArgs( WC()->checkout(), [ &$data, &$errors ] );
		$this->last_errors = $errors;
		return $errors->get_error_codes();
	}

	/**
	 * Errors of the last classic validation.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $last_errors = null;

	/**
	 * Store API: data under extensions.ccfo of the cart response.
	 *
	 * @return array<string, mixed>
	 */
	private function store_api_cart(): array {
		$this->mode( ContextFactory::STORE_API );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		return $data['extensions']['ccfo'];
	}

	/**
	 * Store API order validation; the error code or null.
	 *
	 * @param array<string, mixed> $params Request params.
	 */
	private function store_api_validate( array $params = [] ): ?string {
		$this->mode( ContextFactory::STORE_API );
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		try {
			( new StoreApi( Plugin::instance() ) )->validate_order( new \WC_Order(), $request );
			return null;
		} catch ( RouteException $e ) {
			$this->last_route_message = $e->getMessage();
			return $e->getErrorCode();
		}
	}

	/**
	 * Message of the last RouteException.
	 *
	 * @var string
	 */
	private string $last_route_message = '';

	/**
	 * SPEC §3 / §13: every action × matched × place.
	 *
	 * @return iterable<string, array{0: string, 1: bool}>
	 */
	public static function cases(): iterable {
		foreach ( [ 'block_checkout', 'block_order' ] as $action ) {
			foreach ( [ true, false ] as $matched ) {
				yield "$action " . ( $matched ? 'matched' : 'not matched' ) => [ $action, $matched ];
			}
		}
	}

	/**
	 * Matrix.
	 *
	 * @dataProvider cases
	 * @param string $action  Action.
	 * @param bool   $matched Whether the conditions hold.
	 */
	public function test_action_matrix( string $action, bool $matched ): void {
		$this->rules( [ $this->order_rule( 'r', $action, self::when( $matched ), 'Rule says no.' ) ] );
		$this->add( 'light' );

		$blocks_cart     = $matched && 'block_checkout' === $action;
		$blocks_checkout = $matched;

		// Cart page: "Proceed to checkout" area.
		$this->mode( ContextFactory::RENDER );
		$cart = self::render( 'woocommerce_proceed_to_checkout' );
		$this->assertSame( $blocks_cart, str_contains( $cart, 'ccfo-checkout-disabled' ), 'cart button disabled' );
		// The totals carry the current messages as data; the visible message is at the page top.
		$this->assertStringContainsString( 'class="ccfo-order-notices-data"', $cart );
		$this->assertSame( $blocks_cart, str_contains( $cart, '&quot;Rule says no.&quot;' ), 'cart data' );
		$this->assertStringNotContainsString( 'woocommerce-info', $cart, 'no message next to the button' );
		$top = self::render( 'woocommerce_before_cart' );
		$this->assertStringContainsString( '<div class="ccfo-order-notices" aria-live="polite">', $top );
		$this->assertSame( $blocks_cart, str_contains( $top, '>Rule says no.</div>' ), 'cart message at the top' );
		$this->assertSame( ! $blocks_cart, str_contains( $cart, 'href="' . esc_url( wc_get_checkout_url() ) . '"' ), 'cart link to checkout' );
		// The removed WooCommerce callbacks are back for the next render.
		$this->assertSame( 20, has_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout' ) );
		$this->assertSame( 10, has_action( 'woocommerce_proceed_to_checkout', 'wc_get_pay_buttons' ) );

		// Mini cart.
		$mini = self::render( 'woocommerce_widget_shopping_cart_buttons' );
		$this->assertSame( $blocks_cart, str_contains( $mini, 'ccfo-checkout-disabled' ), 'mini cart button disabled' );
		$this->assertSame( $blocks_cart, str_contains( $mini, 'Rule says no.' ), 'mini cart message' );
		if ( $blocks_cart ) {
			$this->assertLessThan( strpos( $mini, 'ccfo-checkout-disabled' ), strpos( $mini, 'Rule says no.' ), 'mini cart message above the button' );
			$this->assertStringContainsString( '<span class="woocommerce-info ccfo-order-blocked ccfo-order-blocked--mini"', $mini );
		}
		$this->assertSame( ! $blocks_cart, str_contains( $mini, '<a href="' . esc_url( wc_get_checkout_url() ) . '" class="button checkout' ), 'mini cart checkout link' );
		$this->assertNotFalse( has_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout' ) );
		$this->assertFalse( has_action( 'woocommerce_widget_shopping_cart_buttons', [ Plugin::instance()->restrictions, 'mini_cart_disabled_button' ] ) );

		// Classic checkout, as refreshed by update_order_review.
		$this->mode( ContextFactory::AJAX, [ 'payment_method' => 'bacs' ] );
		$data = self::render( 'woocommerce_review_order_before_submit' );
		$this->assertSame( $blocks_checkout, str_contains( $data, '&quot;Rule says no.&quot;' ), 'checkout data' );
		$this->assertStringNotContainsString( 'woocommerce-info', $data, 'no message above Place order' );
		$top = self::render( 'woocommerce_before_checkout_form' );
		$this->assertSame( $blocks_checkout, str_contains( $top, '>Rule says no.</div>' ), 'checkout message at the top' );
		$button = apply_filters( 'woocommerce_order_button_html', '<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order">Place order</button>' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own filter.
		$this->assertSame( $blocks_checkout, str_contains( $button, '<button disabled aria-disabled="true"' ), 'place order disabled' );

		// Classic submit.
		$this->assertSame( $blocks_checkout, in_array( 'ccfo_order_blocked', $this->classic_validate(), true ), 'classic submit' );

		// Store API.
		$ccfo = $this->store_api_cart();
		$this->assertSame( $blocks_cart ? [ 'Rule says no.' ] : [], $ccfo['checkoutBlocked'], 'Store API cart stage' );
		$this->assertSame( $blocks_checkout ? [ 'Rule says no.' ] : [], $ccfo['orderBlocked'], 'Store API checkout stage' );
		$this->assertSame( $blocks_checkout ? 'ccfo_order_blocked' : null, $this->store_api_validate(), 'Store API checkout' );
	}

	public function test_messages_placeholders_order_and_default(): void {
		update_option(
			Settings::OPTION,
			[ 'order_blocked_message' => 'Default: {current} in cart.' ]
		);
		$this->rules(
			[
				$this->order_rule( 'second', 'block_order', self::when( true ), 'Add {remaining} more to reach {min}.', [ 'priority' => 20 ] ),
				$this->order_rule( 'first', 'block_order', self::when( true ), 'Total {current}.', [ 'priority' => 5 ] ),
				$this->order_rule( 'dup', 'block_order', self::when( true ), 'Total {current}.', [ 'priority' => 6 ] ),
				$this->order_rule( 'default', 'block_order', self::when( true ) ),
				$this->order_rule( 'off', 'block_order', self::when( true ), 'Disabled rule.', [ 'enabled' => false ] ),
				$this->order_rule( 'nomatch', 'block_order', self::when( false ), 'Not matching.' ),
			]
		);
		$this->add( 'light' );
		$this->mode( ContextFactory::AJAX );

		$decision = Plugin::instance()->restrictions->decide( Restrictions::STAGE_CHECKOUT );
		$price    = static fn ( float $n ): string => html_entity_decode( wp_strip_all_tags( wc_price( $n ) ), ENT_QUOTES, 'UTF-8' );

		$this->assertTrue( $decision['blocked'] );
		$this->assertSame( [ 'first', 'dup', 'default', 'second' ], $decision['rules'], 'priority order (5, 6, 10, 20), disabled and unmatched rules left out' );
		$this->assertSame(
			[
				'Total ' . $price( 300000 ) . '.',
				'Default: ' . $price( 300000 ) . ' in cart.',
				'Add ' . $price( 100000 ) . ' more to reach ' . $price( 400000 ) . '.',
			],
			$decision['messages'],
			'placeholders filled with store prices; duplicates shown once'
		);
		$this->assertSame( [], Plugin::instance()->restrictions->decide( Restrictions::STAGE_CART )['rules'], 'block_order does not touch the cart' );
	}

	public function test_number_formats(): void {
		$this->assertSame( html_entity_decode( wp_strip_all_tags( wc_price( 1234.5 ) ), ENT_QUOTES, 'UTF-8' ), Restrictions::format( 'cart.total', 1234.5 ) );
		$this->assertSame( html_entity_decode( wp_strip_all_tags( wc_price( 10 ) ), ENT_QUOTES, 'UTF-8' ), Restrictions::format( 'cart.category_subtotal:5', 10 ) );
		$this->assertSame( '3 ' . get_option( 'woocommerce_weight_unit' ), Restrictions::format( 'cart.weight', 3.0 ) );
		$this->assertSame( wc_format_localized_decimal( '2.5' ) . ' ' . get_option( 'woocommerce_weight_unit' ), Restrictions::format( 'cart.weight', 2.5 ) );
		$this->assertSame( '4', Restrictions::format( 'cart.qty', 4.0 ) );
		$this->assertSame( '7', Restrictions::format( 'field:billing_count', 7.0 ) );
	}

	public function test_a13_minimum_order_with_quantity_change(): void {
		$this->rules(
			[
				$this->order_rule(
					'min',
					'block_checkout',
					[
						[
							[
								'source'   => 'cart.subtotal',
								'operator' => 'lt',
								'value'    => 400000,
							],
						],
					],
					'Add {remaining} more.'
				),
			]
		);
		$this->add( 'light' );
		$this->mode( ContextFactory::RENDER );
		$this->assertTrue( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CART )['blocked'] );

		$this->add( 'heavy' );
		$this->assertFalse( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CART )['blocked'], '400 000 reaches the minimum' );
		$this->assertStringContainsString( 'href="' . esc_url( wc_get_checkout_url() ) . '"', self::render( 'woocommerce_proceed_to_checkout' ) );
	}

	public function test_a15_payment_method_and_total_combination(): void {
		$this->rules(
			[
				$this->order_rule(
					'cod_max',
					'block_order',
					[
						[
							[
								'source'   => 'payment.method',
								'operator' => 'equals',
								'value'    => 'cod',
							],
							[
								'source'   => 'cart.total',
								'operator' => 'gt',
								'value'    => 250000,
							],
						],
					],
					'Cash on delivery only up to {max}.'
				),
			]
		);
		$this->add( 'light' );

		$this->assertContains( 'ccfo_order_blocked', $this->classic_validate( 'cod' ) );
		$this->assertStringContainsString( 'Cash on delivery only up to', (string) $this->last_errors->get_error_message( 'ccfo_order_blocked' ) );
		$this->assertNotContains( 'ccfo_order_blocked', $this->classic_validate( 'bacs' ) );

		$this->assertSame( 'ccfo_order_blocked', $this->store_api_validate( [ 'payment_method' => 'cod' ] ) );
		$this->assertStringContainsString( 'Cash on delivery only up to', $this->last_route_message );
		$this->assertNull( $this->store_api_validate( [ 'payment_method' => 'bacs' ] ) );

		// The classic checkout refreshes when the payment method changes (rules read it).
		$this->assertTrue( ( new \CCFO\Classic\Assets( Plugin::instance() ) )->update_triggers()['payment'] );

		// The Checkout Block gets the messages for every method and needs no server round trip.
		$ccfo = $this->store_api_cart();
		$this->assertSame( [ 'cod', 'bacs' ], array_values( array_intersect( [ 'cod', 'bacs' ], array_keys( $ccfo['orderBlockedByMethod'] ) ) ) );
		$this->assertCount( 1, $ccfo['orderBlockedByMethod']['cod'] );
		$this->assertStringContainsString( 'Cash on delivery only up to', $ccfo['orderBlockedByMethod']['cod'][0] );
		$this->assertSame( [], $ccfo['orderBlockedByMethod']['bacs'] );
		$this->assertFalse( ( new \CCFO\Blocks\Integration( Plugin::instance() ) )->get_script_data()['syncPaymentMethod'] );
	}

	public function test_store_api_draft_updates_are_not_refused(): void {
		// Draft updates (PUT/PATCH, also sent as POST with X-HTTP-Method-Override) run the
		// same action while the customer fills in the form; only placing the order is refused.
		$this->rules(
			[
				$this->order_rule( 'r', 'block_order', self::when( true ), 'No.' ),
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
								'value'    => 'MN',
							],
						],
					]
				),
			]
		);
		$this->add( 'light' );
		$this->mode( ContextFactory::STORE_API );
		$store_api = new StoreApi( Plugin::instance() );
		foreach ( [ 'PUT', 'PATCH' ] as $method ) {
			$request = new \WP_REST_Request( $method, '/wc/store/v1/checkout' );
			$request->set_param( 'billing_address', [ 'country' => 'MN' ] );
			$store_api->validate_order( new \WC_Order(), $request );
		}
		$this->addToAssertionCount( 1 );
		$this->assertSame( 'ccfo_order_blocked', $this->store_api_validate( [ 'billing_address' => [ 'country' => 'MN' ] ] ) );
	}

	public function test_checkout_field_and_address_conditions_use_the_request(): void {
		$this->rules(
			[
				$this->order_rule(
					'company_min',
					'block_order',
					[
						[
							[
								'source'   => 'field:billing_company',
								'operator' => 'not_empty',
							],
							[
								'source'   => 'cart.subtotal',
								'operator' => 'lt',
								'value'    => 1000000,
							],
						],
						[
							[
								'source'   => 'billing.country',
								'operator' => 'equals',
								'value'    => 'DE',
							],
						],
					],
					'Companies order from 1 000 000.'
				),
			]
		);
		$this->add( 'light' );

		$this->mode( ContextFactory::AJAX, [ 'billing_company' => 'Acme' ] );
		$this->assertTrue( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CHECKOUT )['blocked'] );
		$this->mode( ContextFactory::AJAX, [ 'billing_company' => '' ] );
		$this->assertFalse( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CHECKOUT )['blocked'] );

		// Store API: address from the request.
		$this->assertSame( 'ccfo_order_blocked', $this->store_api_validate( [ 'billing_address' => [ 'country' => 'DE' ] ] ) );
		$this->assertNull( $this->store_api_validate( [ 'billing_address' => [ 'country' => 'MN' ] ] ) );

		// The classic script refreshes the checkout when these values change.
		$triggers = ( new \CCFO\Classic\Assets( Plugin::instance() ) )->update_triggers();
		$this->assertContains(
			[
				'source'   => 'field:billing_company',
				'operator' => 'not_empty',
			],
			$triggers['conditions']
		);
	}

	public function test_works_together_with_payment_and_shipping_rules(): void {
		$this->rules(
			[
				$this->rule( 'no_cod', 'payment', 'cod', 'disable' ),
				$this->rule( 'no_bacs', 'payment', 'bacs', 'disable' ),
				$this->rule( 'pickup_only', 'shipping', 'flat_rate', 'disable' ),
				$this->order_rule( 'min', 'block_checkout', self::when( true ), 'Minimum not reached.' ),
			]
		);
		$this->add( 'light' );
		WC()->cart->calculate_shipping();
		$codes = $this->classic_validate( 'cod' );

		// Each module reports its own reason.
		$this->assertContains( 'ccfo_order_blocked', $codes );
		$this->assertContains( 'ccfo_payment_blocked', $codes );
		$this->assertSame( [ 'Minimum not reached.' ], $this->last_errors->get_error_messages( 'ccfo_order_blocked' ) );
		delete_option( 'ccfo_blocked_log' );
	}

	public function test_shipping_method_and_category_subtotal_conditions(): void {
		$heavy = (int) $this->fx['terms'][0]['id'];
		$this->rules(
			[
				$this->order_rule(
					'pickup_heavy',
					'block_order',
					[
						[
							[
								'source'   => 'shipping.method',
								'operator' => 'any_in',
								'value'    => [ 'local_pickup' ],
							],
							[
								'source'   => 'cart.category_subtotal:' . $heavy,
								'operator' => 'gte',
								'value'    => 200000,
							],
						],
					],
					'Heavy goods over {max} cannot be picked up.'
				),
			]
		);
		$this->add( 'heavy', 2 );
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['pickup'] ] );
		$this->mode( ContextFactory::AJAX );
		$decision = Plugin::instance()->restrictions->decide( Restrictions::STAGE_CHECKOUT );
		$this->assertTrue( $decision['blocked'] );
		$this->assertStringContainsString( wp_strip_all_tags( html_entity_decode( wc_price( 200000 ), ENT_QUOTES, 'UTF-8' ) ), $decision['messages'][0] );

		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['flat'] ] );
		$this->assertFalse( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CHECKOUT )['blocked'] );
	}

	public function test_coupon_condition_blocks_and_releases(): void {
		$code   = 'ccfo-order-' . strtolower( uniqid() );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 50 );
		$coupon->save();
		try {
			$this->rules(
				[
					$this->order_rule(
						'coupon_min',
						'block_checkout',
						[
							[
								[
									'source'   => 'cart.coupons',
									'operator' => 'any_in',
									'value'    => [ strtoupper( $code ) ],
								],
								[
									'source'   => 'cart.contents_total',
									'operator' => 'lt',
									'value'    => 200000,
								],
							],
						],
						'With this coupon the minimum is {min}; add {remaining}.'
					),
				]
			);
			$this->add( 'light' );
			$this->mode( ContextFactory::RENDER );
			$this->assertFalse( Plugin::instance()->restrictions->decide( Restrictions::STAGE_CART )['blocked'] );

			WC()->cart->apply_coupon( $code );
			WC()->cart->calculate_totals();
			$decision = Plugin::instance()->restrictions->decide( Restrictions::STAGE_CART );
			$this->assertTrue( $decision['blocked'], '150 000 after the coupon' );
			$this->assertStringContainsString( html_entity_decode( wp_strip_all_tags( wc_price( 50000 ) ), ENT_QUOTES, 'UTF-8' ), $decision['messages'][0] );
		} finally {
			WC()->cart->remove_coupons();
			$coupon->delete( true );
		}
	}

	public function test_express_payment_buttons_are_held_back_while_blocked(): void {
		$this->rules( [ $this->order_rule( 'r', 'block_order', self::when( true ) ) ] );
		$this->add( 'light' );
		$this->mode( ContextFactory::RENDER );

		$seen = null;
		$spy  = static function () use ( &$seen ): void {
			$seen = has_action( 'woocommerce_checkout_before_customer_details', 'wc_get_pay_buttons' );
		};
		add_action( 'woocommerce_checkout_before_customer_details', $spy, 2 );
		self::render( 'woocommerce_checkout_before_customer_details' );
		remove_action( 'woocommerce_checkout_before_customer_details', $spy, 2 );

		$this->assertFalse( $seen, 'express buttons removed during the checkout render' );
		$this->assertSame( 30, has_action( 'woocommerce_checkout_before_customer_details', 'wc_get_pay_buttons' ), 'and put back afterwards' );
	}

	public function test_style_only_with_order_rules(): void {
		wp_dequeue_style( 'ccfo-checkout' );
		Plugin::instance()->restrictions->enqueue_style();
		$this->assertFalse( wp_style_is( 'ccfo-checkout', 'enqueued' ) );

		$this->rules( [ $this->order_rule( 'r', 'block_order', self::when( false ) ) ] );
		Plugin::instance()->restrictions->enqueue_style();
		$this->assertTrue( wp_style_is( 'ccfo-checkout', 'enqueued' ) );
		wp_dequeue_style( 'ccfo-checkout' );
	}

	public function test_no_order_rules_changes_nothing(): void {
		$this->rules( [ $this->rule( 'p', 'payment', 'cod', 'disable' ) ] );
		$this->add( 'light' );
		$this->mode( ContextFactory::RENDER );
		$html = '<button type="submit" id="place_order">Place order</button>';
		$this->assertSame( $html, apply_filters( 'woocommerce_order_button_html', $html ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own filter.
		$this->assertStringNotContainsString( 'ccfo-', self::render( 'woocommerce_review_order_before_submit' ) );
		$ccfo = $this->store_api_cart();
		$this->assertSame( [], $ccfo['checkoutBlocked'] );
		$this->assertSame( [], $ccfo['orderBlocked'] );
		$this->assertSame( [], $ccfo['orderBlockedByMethod'] );

		// Order rules that do not read the payment method: no per-method map.
		$this->rules( [ $this->order_rule( 'r', 'block_order', self::when( true ) ) ] );
		$this->assertSame( [], $this->store_api_cart()['orderBlockedByMethod'] );
	}

	public function test_store_api_checkout_end_to_end(): void {
		$this->rules(
			[
				$this->order_rule(
					'max',
					'block_order',
					[
						[
							[
								'source'   => 'cart.total',
								'operator' => 'gt',
								'value'    => 250000,
							],
						],
					],
					'Too much: {excess} over.'
				),
			]
		);
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		Plugin::instance()->contexts->force( null );

		$body = [
			'billing_address'  => [
				'first_name' => 'Test',
				'last_name'  => 'Buyer',
				'address_1'  => 'Peace Avenue 1',
				'city'       => 'Ulaanbaatar',
				'state'      => 'UB',
				'postcode'   => '14200',
				'country'    => 'MN',
				'email'      => 'buyer@example.test',
				'phone'      => '99112233',
			],
			'shipping_address' => [
				'first_name' => 'Test',
				'last_name'  => 'Buyer',
				'address_1'  => 'Peace Avenue 1',
				'city'       => 'Ulaanbaatar',
				'state'      => 'UB',
				'postcode'   => '14200',
				'country'    => 'MN',
				'phone'      => '99112233',
			],
			'payment_method'   => 'bacs',
		];

		$order_ids = [];
		try {
			$this->add( 'light' );
			$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $data ) );
			$this->assertSame( 'ccfo_order_blocked', $data['code'] );
			$this->assertStringContainsString( 'Too much:', $data['message'] );

			// Under the limit the order goes through.
			$this->rules(
				[
					$this->order_rule(
						'max',
						'block_order',
						[
							[
								[
									'source'   => 'cart.total',
									'operator' => 'gt',
									'value'    => 900000,
								],
							],
						]
					),
				]
			);
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			if ( isset( $data['order_id'] ) ) {
				$order_ids[] = (int) $data['order_id'];
			}
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		} finally {
			remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
			foreach ( $order_ids as $id ) {
				$order = wc_get_order( $id );
				if ( $order ) {
					$order->delete( true );
				}
			}
			// The draft order of the refused attempt.
			$draft = WC()->session ? (int) WC()->session->get( 'store_api_draft_order', 0 ) : 0;
			if ( $draft && wc_get_order( $draft ) ) {
				wc_get_order( $draft )->delete( true );
			}
		}
	}
}
