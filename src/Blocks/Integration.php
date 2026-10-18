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
 * Loads build/blocks.js in the Checkout Block. The script toggles the tax note
 * class from `extensions.ccfo` and, when rules read the payment method, sends the
 * selected method to the server with `extensionCartUpdate`.
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
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $registry ): void {
				if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
					$registry->register( $this );
				}
			}
		);
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
		$sources = $this->plugin->rules->index()->sources(
			[ Definitions::TARGET_TAX_CALC, Definitions::TARGET_TAX_DISPLAY, Definitions::TARGET_FIELD ]
		);
		return [
			'syncPaymentMethod' => in_array( 'payment.method', $sources, true ),
		];
	}
}
