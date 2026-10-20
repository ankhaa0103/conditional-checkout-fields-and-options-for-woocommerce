<?php
/**
 * Plugin Name:          Conditional Checkout Fields and Options for WooCommerce
 * Plugin URI:           https://github.com/ankhaa0103/conditional-checkout-fields-and-options-for-woocommerce
 * Description:          Conditional rules for existing WooCommerce checkout fields, payment methods, shipping rates and tax display.
 * Version:              1.1.2
 * Requires at least:    6.6
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Ankhbayar Bayanterbish
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          conditional-checkout-fields-and-options-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.9.3
 * WC tested up to:      11.2
 *
 * @package CCFO
 */

defined( 'ABSPATH' ) || exit;

define( 'CCFO_VERSION', '1.1.2' );
define( 'CCFO_FILE', __FILE__ );
define( 'CCFO_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'CCFO\\' ) ) {
			return;
		}
		$file = CCFO_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, 5 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CCFO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CCFO_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			return;
		}
		if ( version_compare( WC_VERSION, '9.9.3', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Conditional Checkout Fields and Options for WooCommerce needs WooCommerce 9.9.3 or newer.', 'conditional-checkout-fields-and-options-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		\CCFO\Plugin::instance()->boot();
	}
);
