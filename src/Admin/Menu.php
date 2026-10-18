<?php
/**
 * Admin page: WooCommerce → Checkout Rules.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Admin;

use CCFO\Plugin;

/**
 * Registers the page and loads the React app.
 */
final class Menu {

	public const SLUG = 'ccfo-checkout-rules';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 60 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( CCFO_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Add the submenu page.
	 */
	public function add_page(): void {
		$hook       = add_submenu_page(
			'woocommerce',
			__( 'Checkout Rules', 'conditional-checkout-fields-and-options-for-woocommerce' ),
			__( 'Checkout Rules', 'conditional-checkout-fields-and-options-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Page markup; the app mounts into it.
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Checkout Rules', 'conditional-checkout-fields-and-options-for-woocommerce' ) . '</h1><div id="ccfo-admin"><p>' . esc_html__( 'Loading…', 'conditional-checkout-fields-and-options-for-woocommerce' ) . '</p></div></div>';
	}

	/**
	 * Load the app on our page.
	 *
	 * @param string $hook Current page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		$asset = Plugin::asset( 'admin' );
		wp_enqueue_script( 'ccfo-admin', Plugin::url( 'build/admin.js' ), $asset['dependencies'], $asset['version'], [ 'in_footer' => true ] );
		wp_set_script_translations( 'ccfo-admin', 'conditional-checkout-fields-and-options-for-woocommerce', CCFO_DIR . 'languages' );
		wp_enqueue_style( 'wp-components' );
		if ( is_readable( CCFO_DIR . 'build/style-admin.css' ) ) {
			wp_enqueue_style( 'ccfo-admin', Plugin::url( 'build/style-admin.css' ), [ 'wp-components' ], $asset['version'] );
		}
	}

	/**
	 * "Rules" link on the plugins screen.
	 *
	 * @param mixed $links Links.
	 * @return mixed
	 */
	public function action_links( mixed $links ): mixed {
		if ( is_array( $links ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Rules', 'conditional-checkout-fields-and-options-for-woocommerce' ) . '</a>'
			);
		}
		return $links;
	}
}
