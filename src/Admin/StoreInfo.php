<?php
/**
 * What the admin UI and the linter need to know about the store.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Admin;

use CCFO\Blocks\StoreApi;
use CCFO\Plugin;

/**
 * Builds the catalog of fields, gateways, shipping methods and terms, and the
 * linter environment.
 */
final class StoreInfo {

	public const SNAPSHOT_OPTION = 'ccfo_field_snapshot';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Checkout fields as WooCommerce, themes and other plugins register them.
	 *
	 * Loaded live through WC_Checkout::get_checkout_fields(); fields that only
	 * appear on the real checkout page are kept from the last page snapshot.
	 *
	 * @return list<array{key: string, label: string, fieldset: string, required: bool, type: string, block: string}>
	 */
	public function fields(): array {
		$fields = [];

		if ( function_exists( 'wc_load_cart' ) && ! did_action( 'woocommerce_load_cart_from_session' ) ) {
			wc_load_cart();
		}
		if ( WC()->checkout() ) {
			WC()->checkout()->get_checkout_fields();
			$fields = $this->plugin->fields->originals();
		}

		$snapshot = get_option( self::SNAPSHOT_OPTION, [] );
		if ( is_array( $snapshot ) ) {
			$fields += $snapshot;
		}

		$block_fields = self::block_fields();
		$out          = [];
		foreach ( $fields as $key => $field ) {
			$out[] = [
				'key'      => (string) $key,
				'label'    => (string) ( $field['label'] ?? $key ),
				'fieldset' => (string) ( $field['fieldset'] ?? '' ),
				'required' => ! empty( $field['required'] ),
				'type'     => (string) ( $field['type'] ?? 'text' ),
				'block'    => in_array( (string) $key, $block_fields, true ) ? 'submit' : 'none',
			];
		}
		return $out;
	}

	/**
	 * Save the field definitions seen on the checkout page, for the catalog.
	 *
	 * @param array<string, array{fieldset: string, label: string, required: bool, type: string}> $originals Fields.
	 */
	public static function save_snapshot( array $originals ): void {
		if ( [] === $originals ) {
			return;
		}
		$current = get_option( self::SNAPSHOT_OPTION, [] );
		if ( $current !== $originals ) {
			update_option( self::SNAPSHOT_OPTION, $originals, false );
		}
	}

	/**
	 * Field keys the Checkout Block has.
	 *
	 * @return list<string>
	 */
	public static function block_fields(): array {
		$keys = [ 'billing_email', 'order_comments' ];
		foreach ( StoreApi::ADDRESS_KEYS as $key ) {
			$keys[] = 'billing_' . $key;
			$keys[] = 'shipping_' . $key;
		}
		return $keys;
	}

	/**
	 * Payment gateways.
	 *
	 * @return list<array{id: string, title: string, enabled: bool}>
	 */
	public function gateways(): array {
		$out = [];
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$title = $gateway->get_title();
			$out[] = [
				'id'      => (string) $id,
				'title'   => wp_strip_all_tags( '' !== $title ? $title : $gateway->get_method_title() ),
				'enabled' => 'yes' === $gateway->enabled,
			];
		}
		return $out;
	}

	/**
	 * Shipping methods and their zone instances.
	 *
	 * @return list<array{id: string, title: string, zone: string, enabled: bool}>
	 */
	public function shipping(): array {
		$out = [];
		foreach ( WC()->shipping()->get_shipping_methods() as $id => $method ) {
			$out[] = [
				'id'      => (string) $id,
				'title'   => wp_strip_all_tags( $method->get_method_title() ),
				'zone'    => '',
				'enabled' => true,
			];
		}

		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = [
			'zone_name'        => __( 'Locations not covered by your other zones', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own zone name.
			'shipping_methods' => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		];

		foreach ( $zones as $zone ) {
			foreach ( (array) $zone['shipping_methods'] as $method ) {
				if ( ! $method instanceof \WC_Shipping_Method ) {
					continue;
				}
				$out[] = [
					'id'      => $method->id . ':' . $method->get_instance_id(),
					'title'   => wp_strip_all_tags( $method->get_title() ),
					'zone'    => wp_strip_all_tags( (string) $zone['zone_name'] ),
					'enabled' => 'yes' === $method->enabled,
				];
			}
		}
		return $out;
	}

	/**
	 * Full catalog for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function catalog(): array {
		$roles = [];
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[] = [
				'id'    => (string) $slug,
				'label' => translate_user_role( $name ),
			];
		}

		$tax_classes = [
			[
				'id'    => '',
				'label' => __( 'Standard', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own tax class name.
			],
		];
		foreach ( \WC_Tax::get_tax_rate_classes() as $class ) {
			$tax_classes[] = [
				'id'    => (string) $class->slug,
				'label' => (string) $class->name,
			];
		}

		return [
			'fields'          => $this->fields(),
			'gateways'        => $this->gateways(),
			'shipping'        => $this->shipping(),
			'roles'           => $roles,
			'countries'       => WC()->countries->get_countries(),
			'states'          => WC()->countries->get_states(),
			'taxClasses'      => $tax_classes,
			'categories'      => self::terms( 'product_cat' ),
			'tags'            => self::terms( 'product_tag' ),
			'shippingClasses' => self::terms( 'product_shipping_class' ),
			'store'           => $this->store_flags(),
		];
	}

	/**
	 * Environment for RuleSchema and Linter.
	 *
	 * @return array<string, mixed>
	 */
	public function lint_env(): array {
		$shipping = [];
		foreach ( $this->shipping() as $method ) {
			if ( $method['enabled'] ) {
				$shipping[] = $method['id'];
			}
		}
		$gateways = [];
		foreach ( $this->gateways() as $gateway ) {
			if ( $gateway['enabled'] ) {
				$gateways[] = $gateway['id'];
			}
		}

		return array_merge(
			$this->store_flags(),
			[
				'fields'       => array_map( static fn ( array $f ): string => $f['key'], $this->fields() ),
				'block_fields' => self::block_fields(),
				'gateways'     => $gateways,
				'shipping'     => $shipping,
			]
		);
	}

	/**
	 * Schema environment (existing tax classes).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema_env(): array {
		return [ 'tax_classes' => array_merge( [ '' ], \WC_Tax::get_tax_class_slugs() ) ];
	}

	/**
	 * Store-wide flags.
	 *
	 * @return array{tax_enabled: bool, tax_inclusive: bool, block_checkout: bool}
	 */
	public function store_flags(): array {
		$checkout_page = wc_get_page_id( 'checkout' );
		return [
			'tax_enabled'    => wc_tax_enabled(),
			'tax_inclusive'  => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
			'block_checkout' => $checkout_page > 0 && has_block( 'woocommerce/checkout', $checkout_page ),
		];
	}

	/**
	 * Terms of a product taxonomy, with parent paths for categories.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return list<array{id: string, label: string}>
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1000,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$by_id = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_id[ $term->term_id ] = $term;
			}
		}

		$out = [];
		foreach ( $by_id as $id => $term ) {
			$path   = [ $term->name ];
			$parent = $term->parent;
			$depth  = 0;
			while ( $parent && isset( $by_id[ $parent ] ) && $depth < 10 ) {
				array_unshift( $path, $by_id[ $parent ]->name );
				$parent = $by_id[ $parent ]->parent;
				++$depth;
			}
			$out[] = [
				'id'    => (string) $id,
				'label' => html_entity_decode( implode( ' › ', $path ), ENT_QUOTES ),
			];
		}
		usort( $out, static fn ( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] ) );
		return $out;
	}
}
