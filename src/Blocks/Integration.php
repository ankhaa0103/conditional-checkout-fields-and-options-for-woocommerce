<?php
/**
 * Checkout Block script integration.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Blocks;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Loads build/blocks.js in the Cart and Checkout Blocks. The script toggles the
 * tax note class from `extensions.ccfo`, shows the messages of order rules and
 * holds back the checkout / place order buttons while they block (SPEC §13), and,
 * when rules read the payment method, sends the selected method to the server
 * with `extensionCartUpdate`.
 */
final class Integration implements IntegrationInterface {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Hook into the Checkout Block registry.
	 */
	public function register(): void {
		$register = function ( $registry ): void {
			if ( is_object( $registry ) && method_exists( $registry, 'register' ) && ( ! method_exists( $registry, 'is_registered' ) || ! $registry->is_registered( $this->get_name() ) ) ) {
				$registry->register( $this );
			}
		};
		add_action( 'woocommerce_blocks_checkout_block_registration', $register );
		add_action( 'woocommerce_blocks_cart_block_registration', $register );
	}

	/**
	 * Integration name.
	 */
	public function get_name(): string {
		return 'ccfo';
	}

	/**
	 * Register the script.
	 */
	public function initialize(): void {
		$asset = Plugin::asset( 'blocks' );
		wp_register_script( 'ccfo-blocks-checkout', Plugin::url( 'build/blocks.js' ), $asset['dependencies'], $asset['version'], true );
		wp_register_style( 'ccfo-checkout', Plugin::url( 'assets/css/checkout.css' ), [], CCFO_VERSION );
		wp_enqueue_style( 'ccfo-checkout' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return list<string>
	 */
	public function get_script_handles(): array {
		return [ 'ccfo-blocks-checkout' ];
	}

	/**
	 * Editor script handles.
	 *
	 * @return list<string>
	 */
	public function get_editor_script_handles(): array {
		return [];
	}

	/**
	 * Data for the script (`getSetting( 'ccfo_data' )`).
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		// Order rules do not need the sync: the cart data has their messages per payment method.
		$sources = $this->plugin->rules->index()->sources(
			[ Definitions::TARGET_TAX_CALC, Definitions::TARGET_TAX_DISPLAY, Definitions::TARGET_FIELD ]
		);
		return [
			'syncPaymentMethod' => in_array( 'payment.method', $sources, true ),
		];
	}
}
