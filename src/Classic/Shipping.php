<?php
/**
 * Shipping rate availability (cart, classic checkout and Store API).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Classic;

defined( 'ABSPATH' ) || exit;

use CCFO\Engine\Resolver;
use CCFO\Plugin;
use CCFO\Rules\Definitions;
use CCFO\Settings;

/**
 * Filters `woocommerce_package_rates` (WC_Shipping::calculate_shipping_for_package()).
 *
 * WooCommerce caches rates in the session by package hash and only runs the
 * filter on a cache miss. Rules may read values that are not in the package
 * (customer role, field values), so a hash of those values is added to every
 * package; when it changes, WooCommerce recalculates and filters again.
 */
final class Shipping {

	public const PACKAGE_KEY = 'ccfo_ctx';
	public const SESSION_KEY = 'ccfo_shipping_blocked';

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
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'add_context_hash' ], PHP_INT_MAX - 10 );
		add_filter( 'woocommerce_package_rates', [ $this, 'filter_rates' ], 100, 2 );
		add_filter( 'woocommerce_no_shipping_available_html', [ $this, 'blocked_message' ], 100 );
		add_filter( 'woocommerce_cart_no_shipping_available_html', [ $this, 'blocked_message' ], 100 );
	}

	/**
	 * Add the rule context hash to every package.
	 *
	 * @param mixed $packages Packages.
	 * @return mixed
	 */
	public function add_context_hash( mixed $packages ): mixed {
		$index = $this->plugin->rules->index();
		if ( ! is_array( $packages ) || ! $index->has( Definitions::TARGET_SHIPPING ) ) {
			return $packages;
		}

		$sources = $index->sources( [ Definitions::TARGET_SHIPPING ] );
		$hash    = md5( (string) wp_json_encode( [ $index->revision, $this->plugin->contexts->make()->snapshot( $sources ) ] ) );

		foreach ( $packages as $i => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $i ][ self::PACKAGE_KEY ] = $hash;
			}
		}
		return $packages;
	}

	/**
	 * Remove rates disabled by rules.
	 *
	 * @param mixed $rates   Rates keyed by rate id.
	 * @param mixed $package Package.
	 * @return mixed
	 */
	public function filter_rates( mixed $rates, mixed $package ): mixed {
		$index = $this->plugin->rules->index();
		if ( ! is_array( $rates ) || [] === $rates || ! $index->has( Definitions::TARGET_SHIPPING ) ) {
			return $rates;
		}

		$context = $this->plugin->contexts->make();
		$removed = [];
		$applied = [];

		foreach ( $rates as $rate_id => $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			$rules = $index->for_rate( $rate->get_id(), $rate->get_method_id() );
			if ( [] === $rules ) {
				continue;
			}
			$decision = Resolver::toggle( $rules, $context );
			if ( $decision->changed ) {
				$applied[ 'shipping:' . $rate->get_id() ] = $decision->applied;
			}
			if ( ! $decision->active ) {
				unset( $rates[ $rate_id ] );
				array_push( $removed, ...$decision->applied );
			}
		}

		$this->remember( is_array( $package ) ? $package : [], [] === $rates ? $removed : null, $applied );

		return $rates;
	}

	/**
	 * Customer message when rules removed every rate.
	 *
	 * @param mixed $html Default message.
	 * @return mixed
	 */
	public function blocked_message( mixed $html ): mixed {
		return $this->is_blocked() ? esc_html( Settings::get( 'shipping_blocked_message' ) ) : $html;
	}

	/**
	 * Whether rules removed every rate of a current package.
	 *
	 * Rates are cached in the session, so our per-package state is cached there too.
	 */
	public function is_blocked(): bool {
		if ( $this->plugin->log->is_blocked( 'shipping' ) ) {
			return true;
		}
		foreach ( $this->current_states() as $state ) {
			if ( null !== $state['blocked'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rules that changed the rates of the current packages, for the order record.
	 *
	 * @return array<string, list<string>>
	 */
	public function applied(): array {
		$applied = [];
		foreach ( $this->current_states() as $state ) {
			$applied = array_merge( $applied, $state['applied'] );
		}
		return $applied;
	}

	/**
	 * Stored states of the current packages.
	 *
	 * @return list<array{blocked: list<string>|null, applied: array<string, list<string>>}>
	 */
	private function current_states(): array {
		if ( ! WC()->session || ! $this->plugin->rules->index()->has( Definitions::TARGET_SHIPPING ) ) {
			return [];
		}
		$stored = (array) WC()->session->get( self::SESSION_KEY, [] );
		$states = [];
		foreach ( WC()->shipping()->get_packages() as $package ) {
			$key = is_array( $package ) ? $this->package_key( $package ) : '';
			if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$states[] = [
					'blocked' => isset( $stored[ $key ]['blocked'] ) && is_array( $stored[ $key ]['blocked'] ) && empty( $package['rates'] ) ? $stored[ $key ]['blocked'] : null,
					'applied' => isset( $stored[ $key ]['applied'] ) && is_array( $stored[ $key ]['applied'] ) ? $stored[ $key ]['applied'] : [],
				];
			}
		}
		return $states;
	}

	/**
	 * Store the outcome for a package.
	 *
	 * @param array<string, mixed>        $package  Package.
	 * @param list<string>|null           $blocked  Rules that removed every rate, or null if rates remain.
	 * @param array<string, list<string>> $applied  Rules that changed rates, by target.
	 */
	private function remember( array $package, ?array $blocked, array $applied ): void {
		if ( null === $blocked ) {
			$this->plugin->log->unblock( 'shipping' );
		} else {
			$this->plugin->log->block( 'shipping', $blocked );
		}

		if ( ! WC()->session ) {
			return;
		}
		$stored         = (array) WC()->session->get( self::SESSION_KEY, [] );
		$key            = $this->package_key( $package );
		$stored[ $key ] = [
			'blocked' => $blocked,
			'applied' => $applied,
		];
		WC()->session->set( self::SESSION_KEY, array_slice( $stored, -10, null, true ) );
	}

	/**
	 * Stable key of a package: our context hash, destination and contents.
	 *
	 * @param array<string, mixed> $package Package.
	 */
	private function package_key( array $package ): string {
		$contents = [];
		foreach ( (array) ( $package['contents'] ?? [] ) as $item_key => $item ) {
			$contents[ $item_key ] = is_array( $item ) ? ( $item['quantity'] ?? 0 ) : 0;
		}
		return md5( (string) wp_json_encode( [ $package[ self::PACKAGE_KEY ] ?? '', $package['destination'] ?? [], $contents ] ) );
	}
}
