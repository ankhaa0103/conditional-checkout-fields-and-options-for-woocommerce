<?php
/**
 * Classic checkout submit validation.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Classic;

defined( 'ABSPATH' ) || exit;

use CCFO\Plugin;
use CCFO\Settings;

/**
 * Replaces WooCommerce's generic errors with our explanation when rules removed
 * every payment or shipping method. Field and method checks themselves are done
 * by WooCommerce against our filtered fields and methods.
 */
final class Validation {

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
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 100, 2 );
	}

	/**
	 * Explain blocked checkouts.
	 *
	 * @param array<string, mixed> $data   Posted data.
	 * @param \WP_Error            $errors Errors.
	 */
	public function validate( array $data, \WP_Error $errors ): void {
		unset( $data );

		if ( $this->plugin->log->is_blocked( 'payment' ) && WC()->cart && WC()->cart->needs_payment() ) {
			$errors->remove( 'payment' );
			$errors->add( 'ccfo_payment_blocked', esc_html( Settings::get( 'payment_blocked_message' ) ) );
		}

		$shipping = new Shipping( $this->plugin );
		if ( WC()->cart && WC()->cart->needs_shipping() && $shipping->is_blocked() ) {
			$errors->remove( 'shipping' );
			$errors->add( 'ccfo_shipping_blocked', esc_html( Settings::get( 'shipping_blocked_message' ) ) );
		}
	}
}
