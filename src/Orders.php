<?php
/**
 * Records applied rules on the order.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO;

/**
 * Stores `_ccfo_applied_rules` through the order CRUD API (HPOS safe).
 */
final class Orders {

	public const META_KEY = '_ccfo_applied_rules';

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
		add_action( 'woocommerce_checkout_create_order', [ $this, 'store' ], 100 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'store' ], 100 );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_admin' ] );
	}

	/**
	 * Store the rules applied in this request.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function store( \WC_Order $order ): void {
		$applied = $this->plugin->log->applied();
		// Rates are cached in the session, so shipping decisions come from there.
		$applied = array_merge( $applied, ( new Classic\Shipping( $this->plugin ) )->applied() );
		if ( [] === $applied ) {
			$order->delete_meta_data( self::META_KEY );
			return;
		}
		ksort( $applied );
		$order->update_meta_data( self::META_KEY, $applied );
	}

	/**
	 * Show applied rules on the admin order screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function render_admin( \WC_Order $order ): void {
		$applied = $order->get_meta( self::META_KEY );
		if ( ! is_array( $applied ) || [] === $applied ) {
			return;
		}

		echo '<div class="form-field form-field-wide ccfo-applied-rules"><h3>' . esc_html__( 'Checkout rules applied', 'conditional-checkout-fields-and-options-for-woocommerce' ) . '</h3><ul>';
		foreach ( $applied as $target => $rule_ids ) {
			echo '<li><code>' . esc_html( (string) $target ) . '</code> &larr; ' . esc_html( implode( ', ', array_map( 'strval', (array) $rule_ids ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
