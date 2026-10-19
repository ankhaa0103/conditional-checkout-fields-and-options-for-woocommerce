<?php
/**
 * Classic checkout scripts, styles and live context.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Classic;

defined( 'ABSPATH' ) || exit;

use CCFO\Engine\Context;
use CCFO\Engine\Resolver;
use CCFO\Plugin;
use CCFO\Rules\Definitions;
use CCFO\Rules\Rule;
use CCFO\Settings;

/**
 * Sends field and tax display rules to the browser together with the server-only
 * values they need, and refreshes those values on every `update_checkout`.
 */
final class Assets {

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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_action( 'wp_footer', [ $this, 'print_context' ] );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'fragments' ] );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Whether this is the classic (shortcode) checkout page.
	 */
	public function is_classic_checkout(): bool {
		return function_exists( 'is_checkout' )
			&& is_checkout()
			&& ! is_wc_endpoint_url( 'order-pay' )
			&& ! is_wc_endpoint_url( 'order-received' )
			&& ! has_block( 'woocommerce/checkout' );
	}

	/**
	 * Enqueue the checkout script with its rules.
	 */
	public function enqueue(): void {
		wp_register_style( 'ccfo-checkout', Plugin::url( 'assets/css/checkout.css' ), [], CCFO_VERSION );

		if ( ! $this->is_classic_checkout() ) {
			return;
		}

		wp_enqueue_style( 'ccfo-checkout' );

		// Runs our field filter so the original required states are known, and keeps
		// the admin field catalog in step with what the checkout page really shows.
		WC()->checkout()->get_checkout_fields();
		\CCFO\Admin\StoreInfo::save_snapshot( $this->plugin->fields->originals() );

		// Load the script for field/tax display rules, and also when payment, shipping or
		// tax rules read form values: WooCommerce only refreshes the checkout for address
		// fields, so the script must ask for a refresh when such a value changes.
		$rules    = $this->client_rules();
		$triggers = $this->update_triggers();
		if ( [] === $rules && [] === $triggers['conditions'] && ! $triggers['payment'] ) {
			return;
		}

		$asset = Plugin::asset( 'classic' );
		wp_enqueue_script(
			'ccfo-classic-checkout',
			Plugin::url( 'build/classic.js' ),
			array_merge( $asset['dependencies'], [ 'jquery', 'wc-checkout' ] ),
			$asset['version'],
			[ 'in_footer' => true ]
		);

		$required = [];
		foreach ( $this->plugin->fields->originals() as $key => $field ) {
			$required[ $key ] = $field['required'];
		}

		wp_localize_script(
			'ccfo-classic-checkout',
			'ccfoCheckout',
			[
				'rules'        => array_map( static fn ( Rule $r ): array => $r->to_array(), $rules ),
				'sources'      => Definitions::sources(),
				'required'     => $required,
				'triggers'     => $triggers,
				'updateDelay'  => 1000 * (int) Settings::get( 'live_update_delay' ),
				'optionalText' => __( 'optional', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Same label WooCommerce prints.
			]
		);
	}

	/**
	 * Print the initial live context.
	 */
	public function print_context(): void {
		if ( $this->is_classic_checkout() && [] !== $this->client_rules() ) {
			echo $this->context_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a script tag, encoded with wp_json_encode and JSON_HEX_TAG.
		}
	}

	/**
	 * Refresh the live context after `update_checkout`.
	 *
	 * @param mixed $fragments Fragments.
	 * @return mixed
	 */
	public function fragments( mixed $fragments ): mixed {
		if ( is_array( $fragments ) && [] !== $this->client_rules() ) {
			$fragments['script.ccfo-ctx'] = $this->context_script();
		}
		return $fragments;
	}

	/**
	 * Initial tax note state.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( mixed $classes ): mixed {
		if ( is_array( $classes ) && $this->is_classic_checkout() && $this->tax_note_hidden( $this->plugin->contexts->make() ) ) {
			$classes[] = 'ccfo-hide-tax-note';
		}
		return $classes;
	}

	/**
	 * Script tag with server-side values used by client rules.
	 */
	public function context_script(): string {
		$context = $this->plugin->contexts->make();
		$data    = [
			'facts'         => $context->snapshot( $this->server_sources() ),
			'taxNoteHidden' => $this->tax_note_hidden( $context ),
		];
		return '<script type="application/json" class="ccfo-ctx">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}

	/**
	 * Whether a tax display rule hides the "includes tax" note.
	 *
	 * @param Context $context Context.
	 */
	public function tax_note_hidden( Context $context ): bool {
		$rules = $this->plugin->rules->index()->for_target( Definitions::TARGET_TAX_DISPLAY, Definitions::FIXED_KEYS[ Definitions::TARGET_TAX_DISPLAY ] );
		return [] !== $rules && Resolver::flag( $rules, 'hide_tax_note', $context )->active;
	}

	/**
	 * Rules the browser evaluates.
	 *
	 * @return list<Rule>
	 */
	private function client_rules(): array {
		$index = $this->plugin->rules->index();
		return array_merge( $index->of_type( Definitions::TARGET_FIELD ), $index->of_type( Definitions::TARGET_TAX_DISPLAY ) );
	}

	/**
	 * Sources of client rules that only the server can compute.
	 *
	 * @return list<string>
	 */
	private function server_sources(): array {
		$sources = $this->plugin->rules->index()->sources( [ Definitions::TARGET_FIELD, Definitions::TARGET_TAX_DISPLAY ] );
		return array_values(
			array_filter(
				$sources,
				static fn ( string $s ): bool => ! ( Definitions::source_definition( $s )['client'] ?? false )
			)
		);
	}

	/**
	 * Conditions of server-side rules (payment, shipping, tax calculation, order) that read
	 * form values. The browser evaluates them and refreshes the checkout when one of
	 * the results changes, so the server applies the rules to the new values.
	 *
	 * Shipping method changes already refresh the checkout in WooCommerce; payment
	 * method changes are covered by the `payment` flag.
	 *
	 * @return array{conditions: list<array<string, mixed>>, payment: bool}
	 */
	public function update_triggers(): array {
		$index      = $this->plugin->rules->index();
		$conditions = [];
		$payment    = false;

		foreach ( [ Definitions::TARGET_PAYMENT, Definitions::TARGET_SHIPPING, Definitions::TARGET_TAX_CALC, Definitions::TARGET_ORDER ] as $type ) {
			foreach ( $index->of_type( $type ) as $rule ) {
				foreach ( $rule->groups as $group ) {
					foreach ( $group as $condition ) {
						$source = $condition['source'];
						if ( 'payment.method' === $source ) {
							$payment = true;
							continue;
						}
						$definition = Definitions::source_definition( $source );
						if ( 'shipping.method' === $source || ! ( $definition['client'] ?? false ) ) {
							continue;
						}
						$conditions[ (string) wp_json_encode( $condition ) ] = $condition;
					}
				}
			}
		}

		return [
			'conditions' => array_values( $conditions ),
			'payment'    => $payment,
		];
	}
}
