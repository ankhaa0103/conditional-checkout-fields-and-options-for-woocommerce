<?php
/**
 * Plugin Name: CCFO test fields (test fixture only)
 * Description: Registers fields the way third-party plugins do: a classic VAT number field and a Checkout Block additional field.
 *
 * @package CCFO
 */

add_filter(
	'woocommerce_billing_fields',
	static function ( $fields ) {
		$fields['billing_vat'] = [
			'label'    => 'VAT number',
			'required' => false,
			'class'    => [ 'form-row-wide' ],
			'priority' => 35,
		];
		return $fields;
	}
);

add_action(
	'woocommerce_init',
	static function () {
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field(
				[
					'id'       => 'ccfo-test/po-number',
					'label'    => 'PO number',
					'location' => 'order',
					'type'     => 'text',
				]
			);
		}
	}
);
