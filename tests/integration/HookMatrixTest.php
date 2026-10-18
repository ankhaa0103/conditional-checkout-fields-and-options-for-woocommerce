<?php
/**
 * Every target × action × matched/unmatched through the real WooCommerce hooks,
 * and the server-side refusals (SPEC 1.4, 1.5, 1.10, 2.2, 2.3).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use CCFO\Classic\Assets;
use CCFO\Engine\ContextFactory;
use CCFO\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The condition is "customer is a guest": is_true matches, is_false does not.
 */
final class HookMatrixTest extends IntegrationTestCase {

	/**
	 * Condition group that matches or not.
	 *
	 * @param bool $matched Whether it matches for a guest.
	 * @return list<list<array<string, mixed>>>
	 */
	private static function when( bool $matched ): array {
		return [
			[
				[
					'source'   => 'customer.is_guest',
					'operator' => $matched ? 'is_true' : 'is_false',
				],
			],
		];
	}

	/**
	 * Fresh checkout fields.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function fields(): array {
		$checkout = WC()->checkout();
		( new \ReflectionProperty( $checkout, 'fields' ) )->setValue( $checkout, null );
		return $checkout->get_checkout_fields();
	}

	/**
	 * Field cases: action, matched, field (with its original required state), mode.
	 *
	 * @return iterable<string, array{string, bool, string, string}>
	 */
	public static function field_cases(): iterable {
		foreach ( [ 'show', 'hide', 'require' ] as $action ) {
			foreach ( [ true, false ] as $matched ) {
				// billing_first_name is required, billing_vat is optional.
				foreach ( [ 'billing_first_name', 'billing_vat' ] as $field ) {
					foreach ( [ ContextFactory::RENDER, ContextFactory::SUBMIT ] as $mode ) {
						yield sprintf( '%s %s %s %s', $action, $matched ? 'matched' : 'unmatched', $field, $mode ) => [ $action, $matched, $field, $mode ];
					}
				}
			}
		}
	}

	/**
	 * SPEC §3 for fields, through woocommerce_checkout_fields.
	 *
	 * @param string $action  Action.
	 * @param bool   $matched Condition matches.
	 * @param string $field   Field key.
	 * @param string $mode    Request mode.
	 */
	#[DataProvider( 'field_cases' )]
	public function test_field_actions( string $action, bool $matched, string $field, string $mode ): void {
		$original = 'billing_first_name' === $field;
		$this->rules( [ $this->rule( 'f', 'field', $field, $action, self::when( $matched ) ) ] );
		$this->mode( $mode, [ $field => 'x' ] );
		$definition = $this->fields()['billing'][ $field ];

		$visible = match ( $action ) {
			'show'    => $matched,
			'hide'    => ! $matched,
			'require' => true,
		};
		$required = $visible && ( ( 'require' === $action && $matched ) || $original );

		$this->assertSame( $required, (bool) $definition['required'], 'required' );
		if ( ContextFactory::RENDER === $mode ) {
			$this->assertSame( ! $visible, in_array( 'ccfo-hidden', (array) ( $definition['class'] ?? [] ), true ), 'hidden class' );
		} else {
			$this->assertSame( ! $visible, ! empty( $definition['hidden'] ), 'hidden flag' );
			$posted = apply_filters( 'woocommerce_checkout_posted_data', [ $field => 'x' ] );
			$this->assertSame( $visible ? 'x' : '', $posted[ $field ], 'hidden value cleared' );
		}
	}

	/**
	 * Method cases.
	 *
	 * @return iterable<string, array{string, bool}>
	 */
	public static function method_cases(): iterable {
		foreach ( [ 'enable', 'disable' ] as $action ) {
			foreach ( [ true, false ] as $matched ) {
				yield "$action " . ( $matched ? 'matched' : 'unmatched' ) => [ $action, $matched ];
			}
		}
	}

	/**
	 * SPEC §3 for payment gateways.
	 *
	 * @param string $action  Action.
	 * @param bool   $matched Condition matches.
	 */
	#[DataProvider( 'method_cases' )]
	public function test_payment_actions( string $action, bool $matched ): void {
		$this->rules( [ $this->rule( 'p', 'payment', 'cod', $action, self::when( $matched ) ) ] );
		$this->add( 'light' );
		$expected = 'enable' === $action ? $matched : ! $matched;

		foreach ( [ ContextFactory::AJAX, ContextFactory::SUBMIT, ContextFactory::STORE_API ] as $mode ) {
			$this->mode( $mode );
			$ids = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
			$this->assertSame( $expected, in_array( 'cod', $ids, true ), $mode );
			$this->assertContains( 'bacs', $ids, 'other gateways untouched' );
		}
	}

	/**
	 * SPEC §3 for shipping, on the method id and on the zone instance id.
	 *
	 * @param string $action  Action.
	 * @param bool   $matched Condition matches.
	 */
	#[DataProvider( 'method_cases' )]
	public function test_shipping_actions( string $action, bool $matched ): void {
		$expected = 'enable' === $action ? $matched : ! $matched;
		$this->add( 'light' );
		foreach ( [ 'flat_rate', $this->fx['rates']['flat'] ] as $key ) {
			$this->rules( [ $this->rule( 's', 'shipping', $key, $action, self::when( $matched ) ) ] );
			foreach ( [ ContextFactory::AJAX, ContextFactory::STORE_API ] as $mode ) {
				$this->mode( $mode );
				$this->reset_shipping_cache();
				$rates = $this->rate_ids();
				$this->assertSame( $expected, in_array( $this->fx['rates']['flat'], $rates, true ), "$key $mode" );
				$this->assertContains( $this->fx['rates']['free'], $rates, 'other rates untouched' );
			}
		}
	}

	/**
	 * SPEC §3 for the tax note, in every place the decision is used.
	 *
	 * @param bool $matched Condition matches.
	 */
	#[DataProvider( 'bool_cases' )]
	public function test_tax_display_action( bool $matched ): void {
		$this->rules( [ $this->rule( 't', 'tax_display', 'checkout', 'hide_tax_note', self::when( $matched ) ) ] );
		$this->add( 'light' );
		$this->mode( ContextFactory::AJAX );

		$assets = new Assets( Plugin::instance() );
		$this->assertSame( $matched, $assets->tax_note_hidden( Plugin::instance()->contexts->make() ) );
		$this->assertStringContainsString( '"taxNoteHidden":' . ( $matched ? 'true' : 'false' ), $assets->context_script() );
		$this->assertSame( $matched, ( new \CCFO\Blocks\StoreApi( Plugin::instance() ) )->cart_data()['taxNoteHidden'] );
	}

	/**
	 * Matched / unmatched.
	 *
	 * @return iterable<string, array{bool}>
	 */
	public static function bool_cases(): iterable {
		yield 'matched' => [ true ];
		yield 'unmatched' => [ false ];
	}

	public function test_classic_submit_refuses_a_removed_gateway_and_rate(): void {
		$this->rules(
			[
				$this->rule( 'p', 'payment', 'cod', 'disable' ),
				$this->rule( 's', 'shipping', $this->fx['rates']['flat'], 'disable' ),
			]
		);
		$this->add( 'light' );
		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['flat'] ] );
		$this->mode( ContextFactory::SUBMIT, [ 'payment_method' => 'cod' ] );
		WC()->cart->calculate_shipping();

		$data     = [
			'payment_method'            => 'cod',
			'ship_to_different_address' => false,
			'terms'                     => 1,
		];
		$errors   = new \WP_Error();
		$validate = new \ReflectionMethod( WC()->checkout(), 'validate_checkout' );
		$validate->invokeArgs( WC()->checkout(), [ &$data, &$errors ] );

		$this->assertContains( 'payment', $errors->get_error_codes(), 'forced removed gateway is refused' );

		// WooCommerce replaces a chosen rate that is not offered by an offered one
		// (wc_get_chosen_shipping_method_for_package), so the removed rate cannot reach the order.
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods' );
		$this->assertNotContains( $this->fx['rates']['flat'], $chosen );
		$this->assertArrayHasKey( $chosen[0], WC()->shipping()->get_packages()[0]['rates'] );
		$this->assertNotContains( 'shipping', $errors->get_error_codes() );
	}

	public function test_classic_submit_stops_when_every_method_is_removed(): void {
		$this->rules(
			[
				$this->rule( 'p1', 'payment', 'cod', 'disable' ),
				$this->rule( 'p2', 'payment', 'bacs', 'disable' ),
				$this->rule( 's1', 'shipping', 'flat_rate', 'disable' ),
				$this->rule( 's2', 'shipping', 'free_shipping', 'disable' ),
				$this->rule( 's3', 'shipping', 'local_pickup', 'disable' ),
			]
		);
		$this->add( 'light' );
		$this->mode( ContextFactory::SUBMIT, [ 'payment_method' => 'cod' ] );
		WC()->cart->calculate_shipping();

		$data     = [
			'payment_method'            => 'cod',
			'ship_to_different_address' => false,
			'terms'                     => 1,
		];
		$errors   = new \WP_Error();
		$validate = new \ReflectionMethod( WC()->checkout(), 'validate_checkout' );
		$validate->invokeArgs( WC()->checkout(), [ &$data, &$errors ] );

		// SPEC §8.3: our explanation replaces WooCommerce's generic errors.
		$codes = $errors->get_error_codes();
		$this->assertContains( 'ccfo_payment_blocked', $codes );
		$this->assertContains( 'ccfo_shipping_blocked', $codes );
		$this->assertNotContains( 'payment', $codes );
		$this->assertNotContains( 'shipping', $codes );
		delete_option( 'ccfo_blocked_log' );
	}

	public function test_store_api_refuses_a_removed_gateway_and_rate(): void {
		$this->rules(
			[
				$this->rule( 'p', 'payment', 'cod', 'disable' ),
				$this->rule( 's', 'shipping', $this->fx['rates']['flat'], 'disable' ),
			]
		);
		$this->add( 'light' );
		$this->mode( ContextFactory::STORE_API );
		$this->rate_ids();

		$route   = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class )->get( 'checkout' );
		$method  = new \ReflectionMethod( $route, 'get_request_payment_method' );
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'payment_method', 'cod' );
		try {
			$method->invoke( $route, $request );
			$this->fail( 'cod must be refused' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'woocommerce_rest_checkout_payment_method_disabled', $e->getErrorCode() );
		}
		$request->set_param( 'payment_method', 'bacs' );
		$this->assertSame( 'bacs', $method->invoke( $route, $request )->id );

		$orders = new \Automattic\WooCommerce\StoreApi\Utilities\OrderController();
		try {
			$orders->validate_selected_shipping_methods( true, [ $this->fx['rates']['flat'] ] );
			$this->fail( 'removed rate must be refused' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'woocommerce_rest_invalid_shipping_option', $e->getErrorCode() );
		}
		$orders->validate_selected_shipping_methods( true, [ $this->fx['rates']['free'] ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_store_api_cart_response_lists_the_same_rates_as_classic(): void {
		$this->rules( [ $this->rule( 's', 'shipping', 'free_shipping', 'disable', self::when( true ) ) ] );
		$this->add( 'light' );

		$this->mode( ContextFactory::AJAX );
		$classic = $this->rate_ids();

		$this->mode( ContextFactory::STORE_API );
		$this->reset_shipping_cache();
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		$block    = array_column( $data['shipping_rates'][0]['shipping_rates'], 'rate_id' );

		$this->assertEqualsCanonicalizing( $classic, $block );
		$this->assertNotContains( $this->fx['rates']['free'], $block );
	}
}
