<?php
/**
 * SPEC 2.4, 2.6, §6 and lifecycle checks.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Blocks\Integration;
use CCFO\Blocks\StoreApi;
use CCFO\Engine\ContextFactory;
use CCFO\Orders;
use CCFO\Plugin;

/**
 * Taxes on with a 10% MN rate for the tax tests.
 */
final class SpecExtrasTest extends IntegrationTestCase {

	/**
	 * Tax rate id.
	 *
	 * @var int
	 */
	private int $rate = 0;

	/**
	 * Original options.
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
		$this->rate = \WC_Tax::_insert_tax_rate(
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
		parent::setUp();
		WC()->session->set( 'ccfo_vat_exempt', null );
	}

	protected function tearDown(): void {
		parent::tearDown();
		\WC_Tax::_delete_tax_rate( $this->rate );
		foreach ( $this->options as $name => $value ) {
			update_option( $name, $value );
		}
		WC()->customer->set_is_vat_exempt( false );
	}

	/**
	 * Cart tax after recalculation in a mode.
	 *
	 * @param string               $mode   Mode.
	 * @param array<string, mixed> $posted Posted data.
	 */
	private function tax_in( string $mode, array $posted = [] ): float {
		$this->mode( $mode, $posted );
		WC()->cart->calculate_totals();
		return (float) WC()->cart->get_total_tax();
	}

	public function test_classic_and_block_totals_are_equal_with_tax_rules(): void {
		// SPEC 2.4 / A12: same tax in the classic checkout and the Store API.
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
								'source'   => 'shipping.method',
								'operator' => 'any_in',
								'value'    => [ 'local_pickup' ],
							],
						],
					]
				),
			]
		);
		$this->add( 'light' );

		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['flat'] ] );
		$classic = $this->tax_in( ContextFactory::AJAX );
		$block   = $this->tax_in( ContextFactory::STORE_API );
		$this->assertEqualsWithDelta( $classic, $block, 0.001 );
		$this->assertGreaterThan( 0, $classic );

		WC()->session->set( 'chosen_shipping_methods', [ $this->fx['rates']['pickup'] ] );
		$this->assertEqualsWithDelta( 0.0, $this->tax_in( ContextFactory::AJAX ), 0.001 );
		$this->assertEqualsWithDelta( 0.0, $this->tax_in( ContextFactory::STORE_API ), 0.001 );

		// SPEC 2.4: the order records which rule applied.
		$order = new \WC_Order();
		( new Orders( Plugin::instance() ) )->store( $order );
		$this->assertSame( [ 'a12' ], $order->get_meta( Orders::META_KEY )['tax:vat_exempt'] );
	}

	public function test_block_payment_method_sync_feeds_tax_rules(): void {
		$this->rules(
			[
				$this->rule(
					'pm',
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
			]
		);
		$this->add( 'light' );
		$store_api = new StoreApi( Plugin::instance() );

		$store_api->update_from_client( [ 'payment_method' => 'bacs' ] );
		$this->assertSame( 'bacs', WC()->session->get( 'chosen_payment_method' ) );
		$this->assertEqualsWithDelta( 0.0, $this->tax_in( ContextFactory::STORE_API ), 0.001 );

		$store_api->update_from_client( [ 'payment_method' => 'cod' ] );
		$this->assertGreaterThan( 0, $this->tax_in( ContextFactory::STORE_API ) );

		// Unknown gateways are ignored.
		$store_api->update_from_client( [ 'payment_method' => 'not-a-gateway' ] );
		$this->assertSame( 'cod', WC()->session->get( 'chosen_payment_method' ) );

		// The block script is told to sync only when rules read the payment method.
		$this->assertTrue( ( new Integration( Plugin::instance() ) )->get_script_data()['syncPaymentMethod'] );
		$this->rules( [ $this->rule( 'x', 'payment', 'cod', 'disable' ) ] );
		$this->assertFalse( ( new Integration( Plugin::instance() ) )->get_script_data()['syncPaymentMethod'] );
	}

	public function test_tax_display_rules_never_touch_tax_data(): void {
		// SPEC §6: display rules change only what the page shows.
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		$this->add( 'light' );
		$this->mode( ContextFactory::AJAX );
		WC()->cart->calculate_totals();
		$before = [ WC()->cart->get_tax_totals(), WC()->cart->get_total( 'edit' ), WC()->cart->get_total_tax() ];

		$this->rules( [ $this->rule( 'n', 'tax_display', 'checkout', 'hide_tax_note' ) ] );
		WC()->cart->calculate_totals();
		$after = [ WC()->cart->get_tax_totals(), WC()->cart->get_total( 'edit' ), WC()->cart->get_total_tax() ];

		$this->assertEquals( $before, $after );
		foreach ( [ 'woocommerce_cart_tax_totals', 'woocommerce_cart_totals_taxes_total_html', 'woocommerce_get_order_item_totals', 'woocommerce_cart_totals_order_total_html' ] as $hook ) {
			$this->assertSame( [], self::plugin_callbacks( $hook ), "$hook has no plugin callback" );
		}
		// The checker itself finds our callbacks on hooks we do use.
		$this->assertNotSame( [], self::plugin_callbacks( 'woocommerce_available_payment_gateways' ) );
	}

	/**
	 * Classes of CCFO callbacks on a hook.
	 *
	 * @param string $hook Hook.
	 * @return list<string>
	 */
	private static function plugin_callbacks( string $hook ): array {
		global $wp_filter;
		$out = [];
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $out;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$class    = is_array( $function ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) : ( $function instanceof \Closure ? ( new \ReflectionFunction( $function ) )->getClosureScopeClass()?->getName() ?? '' : (string) $function );
				if ( str_starts_with( $class, 'CCFO\\' ) ) {
					$out[] = $class;
				}
			}
		}
		return $out;
	}

	public function test_hooks_and_priorities_follow_the_architecture(): void {
		// ARCHITECTURE §5/§6/§9: hook names, and priorities after theme field editors (Woodmart uses 99999).
		$p      = Plugin::instance();
		$expect = [
			'woocommerce_checkout_fields'                 => PHP_INT_MAX - 10,
			'woocommerce_checkout_posted_data'            => PHP_INT_MAX - 10,
			'woocommerce_cart_shipping_packages'          => PHP_INT_MAX - 10,
			'woocommerce_available_payment_gateways'      => 100,
			'woocommerce_package_rates'                   => 100,
			'woocommerce_after_checkout_validation'       => 100,
			'woocommerce_before_calculate_totals'         => 100,
			'woocommerce_cart_totals_get_item_tax_rates'  => 100,
			'woocommerce_checkout_create_order_line_item' => 100,
			'woocommerce_checkout_create_order'           => 100,
			'woocommerce_store_api_checkout_update_order_from_request' => 20,
			'woocommerce_update_order_review_fragments'   => 10,
		];
		foreach ( $expect as $hook => $priority ) {
			global $wp_filter;
			$found = [];
			foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $prio => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && str_starts_with( get_class( $callback['function'][0] ), 'CCFO\\' ) ) {
						$found[] = $prio;
					}
				}
			}
			$this->assertContains( $priority, $found, $hook );
		}
		$this->assertGreaterThan( 99999, PHP_INT_MAX - 10 );
		unset( $p );
	}

	public function test_plugin_never_calculates_totals_itself(): void {
		// SPEC 2.6: no call to set totals in the source code.
		$code = '';
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( CCFO_DIR . 'src' ) ) as $file ) {
			if ( $file->isFile() ) {
				$code .= file_get_contents( $file->getPathname() );
			}
		}
		foreach ( [ 'set_total(', 'set_total_tax(', 'set_cart_contents_total(', 'set_shipping_total(', 'set_subtotal(' ] as $call ) {
			$this->assertStringNotContainsString( $call, $code, $call );
		}
	}

	public function test_admin_order_screen_lists_applied_rules(): void {
		$order = new \WC_Order();
		$order->update_meta_data( Orders::META_KEY, [ 'payment:cod' => [ 'r1' ] ] );
		ob_start();
		( new Orders( Plugin::instance() ) )->render_admin( $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Checkout rules applied', $html );
		$this->assertStringContainsString( 'payment:cod', $html );
	}

	public function test_uninstall_removes_plugin_options_only(): void {
		$names  = [ 'ccfo_rules', 'ccfo_settings', 'ccfo_blocked_log', 'ccfo_field_snapshot' ];
		$backup = [];
		foreach ( $names as $name ) {
			$backup[ $name ] = get_option( $name );
			update_option( $name, [ 'x' => 1 ], false );
		}
		$fixtures = get_option( 'ccfo_test_fixtures' );
		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'conditional-checkout-fields-and-options-for-woocommerce/conditional-checkout-fields-and-options-for-woocommerce.php' );
			}
			include CCFO_DIR . 'uninstall.php';
			foreach ( $names as $name ) {
				$this->assertFalse( get_option( $name ), $name );
			}
			$this->assertSame( $fixtures, get_option( 'ccfo_test_fixtures' ), 'other options untouched' );
		} finally {
			foreach ( $backup as $name => $value ) {
				if ( false !== $value ) {
					update_option( $name, $value, false );
				}
			}
		}
	}
}
