<?php
/**
 * Tax calculation rules (SPEC 2.4): VAT exemption and tax class switch.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tax;

use CCFO\Engine\Decision;
use CCFO\Engine\Resolver;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Works only through WooCommerce's own tax calculation:
 *
 * - set_vat_exempt: WC_Customer::set_is_vat_exempt() before totals are calculated.
 *   Only an exemption set by us is removed again, never one set by other code.
 * - set_tax_class:  `woocommerce_cart_totals_get_item_tax_rates` uses the rates of the
 *   chosen existing tax class, and order line items get the same class so later
 *   admin recalculation gives the same taxes.
 *
 * Totals are never calculated here.
 */
final class TaxRules {

	public const SESSION_KEY = 'ccfo_vat_exempt';

	/**
	 * Tax class chosen for this calculation, null when no rule applies.
	 *
	 * @var string|null
	 */
	private ?string $tax_class = null;

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
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'before_totals' ], 100 );
		add_filter( 'woocommerce_cart_totals_get_item_tax_rates', [ $this, 'item_tax_rates' ], 100, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'line_item_tax_class' ], 100 );
	}

	/**
	 * Decide the tax rules before WooCommerce calculates totals.
	 */
	public function before_totals(): void {
		$this->tax_class = null;

		$rules = $this->plugin->rules->index()->of_type( Definitions::TARGET_TAX_CALC );
		if ( [] === $rules || ! wc_tax_enabled() ) {
			$this->release_exemption();
			return;
		}

		$context = $this->plugin->contexts->make();

		$exempt = Resolver::flag( $rules, 'set_vat_exempt', $context );
		$this->plugin->log->record( 'tax:vat_exempt', $exempt );
		if ( $exempt->active ) {
			$this->apply_exemption();
		} else {
			$this->release_exemption();
		}

		$rule = Resolver::pick( $rules, 'set_tax_class', $context );
		if ( null !== $rule ) {
			$this->tax_class = (string) ( $rule->args['tax_class'] ?? '' );
			$this->plugin->log->record( 'tax:tax_class', new Decision( true, false, [ $rule->id ], null, true ) );
		}
	}

	/**
	 * Use the rates of the chosen tax class.
	 *
	 * @param mixed $rates Rates.
	 * @param mixed $item  Cart totals item.
	 * @param mixed $cart  Cart.
	 * @return mixed
	 */
	public function item_tax_rates( mixed $rates, mixed $item, mixed $cart ): mixed {
		unset( $item );
		if ( null === $this->tax_class || ! $cart instanceof \WC_Cart ) {
			return $rates;
		}
		return \WC_Tax::get_rates( $this->tax_class, $cart->get_customer() );
	}

	/**
	 * Store the switched tax class on order line items.
	 *
	 * @param mixed $item Order item.
	 */
	public function line_item_tax_class( mixed $item ): void {
		if ( null !== $this->tax_class && $item instanceof \WC_Order_Item_Product ) {
			$item->set_tax_class( $this->tax_class );
		}
	}

	/**
	 * The tax class chosen by rules for the last calculation.
	 */
	public function tax_class(): ?string {
		return $this->tax_class;
	}

	/**
	 * Exempt the customer and remember that we did.
	 */
	private function apply_exemption(): void {
		$customer = WC()->customer;
		if ( ! $customer || ! WC()->session ) {
			return;
		}
		if ( ! $customer->get_is_vat_exempt() ) {
			$customer->set_is_vat_exempt( true );
			WC()->session->set( self::SESSION_KEY, true );
		}
	}

	/**
	 * Remove an exemption only if we set it.
	 */
	private function release_exemption(): void {
		$customer = WC()->customer;
		if ( ! $customer || ! WC()->session || ! WC()->session->get( self::SESSION_KEY ) ) {
			return;
		}
		$customer->set_is_vat_exempt( false );
		WC()->session->set( self::SESSION_KEY, null );
	}
}
