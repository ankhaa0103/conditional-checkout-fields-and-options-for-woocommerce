<?php
/**
 * Base class: rules, cart and request mode helpers against the real WooCommerce.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * Fixture ids from tools/fixtures.php.
	 *
	 * @var array<string, mixed>
	 */
	protected array $fx;

	protected function setUp(): void {
		parent::setUp();
		$this->fx = (array) get_option( 'ccfo_test_fixtures' );

		wp_set_current_user( 0 );
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
			wc_load_cart();
		}
		WC()->session->set( 'chosen_shipping_methods', [] );
		WC()->session->set( 'chosen_payment_method', '' );
		WC()->session->set( 'ccfo_shipping_blocked', null );
		WC()->cart->empty_cart();
		WC()->customer->set_billing_country( 'MN' );
		WC()->customer->set_shipping_country( 'MN' );
		WC()->customer->set_is_vat_exempt( false );
		$this->reset_shipping_cache();
		$this->rules( [] );
	}

	protected function tearDown(): void {
		Plugin::instance()->contexts->force( null );
		$this->rules( [] );
		WC()->cart->empty_cart();
		parent::tearDown();
	}

	/**
	 * Store rules directly (validated on load like in production).
	 *
	 * @param list<array<string, mixed>> $rules Rules.
	 */
	protected function rules( array $rules ): void {
		$stored = get_option( 'ccfo_rules', [] );
		update_option(
			'ccfo_rules',
			[
				'schema'   => 1,
				'revision' => ( is_array( $stored ) ? (int) ( $stored['revision'] ?? 0 ) : 0 ) + 1,
				'rules'    => $rules,
			],
			false
		);
		Plugin::instance()->rules->flush();
	}

	/**
	 * A rule array.
	 *
	 * @param string                           $id     Id.
	 * @param string                           $type   Target type.
	 * @param string                           $key    Target key.
	 * @param string                           $action Action.
	 * @param list<list<array<string, mixed>>> $groups Groups.
	 * @param array<string, mixed>             $extra  Extra props.
	 * @return array<string, mixed>
	 */
	protected function rule( string $id, string $type, string $key, string $action, array $groups = [], array $extra = [] ): array {
		return array_merge(
			[
				'id'       => $id,
				'title'    => $id,
				'enabled'  => true,
				'priority' => 10,
				'target'   => [
					'type' => $type,
					'key'  => $key,
				],
				'action'   => $action,
				'groups'   => $groups,
			],
			$extra
		);
	}

	/**
	 * Use a request mode with posted form data.
	 *
	 * @param string               $mode   Mode.
	 * @param array<string, mixed> $posted Posted data.
	 */
	protected function mode( string $mode, array $posted = [] ): void {
		Plugin::instance()->contexts->force( $mode, $posted );
	}

	/**
	 * Add a fixture product.
	 *
	 * @param string $key light|heavy.
	 * @param int    $qty Quantity.
	 */
	protected function add( string $key, int $qty = 1 ): void {
		WC()->cart->add_to_cart( (int) $this->fx['products'][ $key ], $qty );
		WC()->cart->calculate_totals();
	}

	/**
	 * Rate ids offered for the first package.
	 *
	 * @return list<string>
	 */
	protected function rate_ids(): array {
		WC()->cart->calculate_shipping();
		$packages = WC()->shipping()->get_packages();
		return array_values( array_map( 'strval', array_keys( $packages[0]['rates'] ?? [] ) ) );
	}

	/**
	 * Drop WooCommerce's session shipping cache.
	 */
	protected function reset_shipping_cache(): void {
		foreach ( array_keys( (array) WC()->session->get_session_data() ) as $key ) {
			if ( str_starts_with( (string) $key, 'shipping_for_package_' ) ) {
				WC()->session->set( (string) $key, null );
			}
		}
	}
}
