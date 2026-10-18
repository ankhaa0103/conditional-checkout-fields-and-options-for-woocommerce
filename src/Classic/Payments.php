<?php
/**
 * Payment gateway availability (classic checkout and Store API).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Classic;

use CCFO\Engine\ContextFactory;
use CCFO\Engine\Resolver;
use CCFO\Plugin;
use CCFO\Rules\Definitions;
use CCFO\Settings;

/**
 * Filters `woocommerce_available_payment_gateways` (WC_Payment_Gateways::get_available_payment_gateways()).
 *
 * The same filter feeds the classic payment list, classic submit validation,
 * the Store API cart `payment_methods` and Store API checkout validation.
 */
final class Payments {

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
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ], 100 );
		add_filter( 'woocommerce_no_available_payment_methods_message', [ $this, 'blocked_message' ], 100 );
	}

	/**
	 * Remove gateways disabled by rules.
	 *
	 * @param mixed $gateways Gateways keyed by id.
	 * @return mixed
	 */
	public function filter_gateways( mixed $gateways ): mixed {
		if ( ! is_array( $gateways ) || [] === $gateways || ! $this->applies() ) {
			return $gateways;
		}

		$index = $this->plugin->rules->index();
		if ( ! $index->has( Definitions::TARGET_PAYMENT ) ) {
			return $gateways;
		}

		$context = $this->plugin->contexts->make();
		$removed = [];
		$this->plugin->log->forget( 'payment:' );

		foreach ( array_keys( $gateways ) as $id ) {
			$rules = $index->for_target( Definitions::TARGET_PAYMENT, (string) $id );
			if ( [] === $rules ) {
				continue;
			}
			$decision = Resolver::toggle( $rules, $context );
			$this->plugin->log->record( 'payment:' . $id, $decision );
			if ( ! $decision->active ) {
				unset( $gateways[ $id ] );
				array_push( $removed, ...$decision->applied );
			}
		}

		if ( [] === $gateways ) {
			$this->plugin->log->block( 'payment', $removed );
		} else {
			$this->plugin->log->unblock( 'payment' );
		}

		return $gateways;
	}

	/**
	 * Customer message when rules removed every gateway.
	 *
	 * @param mixed $message Default message.
	 * @return mixed
	 */
	public function blocked_message( mixed $message ): mixed {
		return $this->plugin->log->is_blocked( 'payment' ) ? esc_html( Settings::get( 'payment_blocked_message' ) ) : $message;
	}

	/**
	 * Whether payment rules apply to this request: checkout page, checkout AJAX,
	 * Store API, or a page with the Checkout Block (its cart data is preloaded
	 * while the page renders); not the order-pay page, My Account or wp-admin.
	 */
	private function applies(): bool {
		$mode = $this->plugin->contexts->mode();
		if ( ContextFactory::STORE_API === $mode || ContextFactory::SUBMIT === $mode || ContextFactory::AJAX === $mode ) {
			return true;
		}
		if ( is_admin() || is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return false;
		}
		return is_checkout() || has_block( 'woocommerce/checkout' );
	}
}
