<?php
/**
 * Admin notice when rules blocked checkouts.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Admin;

use CCFO\Engine\RequestLog;

/**
 * Shows the latest "every method removed" event on WooCommerce screens until the
 * admin opens the rules page (which clears the log).
 */
final class Notices {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Render the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( ! str_contains( (string) $screen->id, 'woocommerce' ) && 'plugins' !== $screen->id && 'dashboard' !== $screen->id ) || str_contains( (string) $screen->id, Menu::SLUG ) ) {
			return;
		}

		$log = get_option( RequestLog::BLOCKED_OPTION, [] );
		if ( ! is_array( $log ) || [] === $log || ! is_array( $log[0] ) ) {
			return;
		}

		$last    = $log[0];
		$message = 'payment' === $last['type']
			/* translators: 1: rule ids, 2: time ago */
			? __( 'Checkout rules %1$s removed every payment method for a customer %2$s ago, so they could not order.', 'conditional-checkout-fields-and-options-for-woocommerce' )
			/* translators: 1: rule ids, 2: time ago */
			: __( 'Checkout rules %1$s removed every shipping method for a customer %2$s ago, so they could not order.', 'conditional-checkout-fields-and-options-for-woocommerce' );

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( $message, implode( ', ', (array) $last['rules'] ), human_time_diff( (int) $last['time'] ) ) ),
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ),
			esc_html__( 'Review the rules', 'conditional-checkout-fields-and-options-for-woocommerce' )
		);
	}
}
