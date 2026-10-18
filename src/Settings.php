<?php
/**
 * Plugin settings (customer messages).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO;

/**
 * Settings stored in the `ccfo_settings` option.
 */
final class Settings {

	public const OPTION = 'ccfo_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			'payment_blocked_message'  => __( 'No payment method is available for your order with the details you entered. Please check your details or contact us.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
			'shipping_blocked_message' => __( 'No shipping method is available for your order with the details you entered. Please check your address and cart, or contact us.', 'conditional-checkout-fields-and-options-for-woocommerce' ),
			// Seconds the classic checkout waits while the customer is typing before it
			// refreshes payment/shipping methods for rules that read form values.
			'live_update_delay'        => '5',
		];
	}

	/**
	 * All settings with defaults filled in.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$stored   = get_option( self::OPTION, [] );
		$stored   = is_array( $stored ) ? $stored : [];
		$defaults = self::defaults();
		$out      = [];
		foreach ( $defaults as $key => $default ) {
			$value       = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? trim( $stored[ $key ] ) : '';
			$out[ $key ] = '' === $value ? $default : $value;
		}
		return $out;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 */
	public static function get( string $key ): string {
		return self::all()[ $key ] ?? '';
	}

	/**
	 * Save settings; unknown keys are ignored, text is sanitised.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, string>
	 */
	public static function save( array $values ): array {
		$clean = [];
		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = 'live_update_delay' === $key
				? (string) max( 0, min( 30, (int) $values[ $key ] ) )
				: sanitize_textarea_field( (string) $values[ $key ] );
		}
		update_option( self::OPTION, $clean, false );
		return self::all();
	}
}
