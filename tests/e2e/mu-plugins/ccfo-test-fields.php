<?php
/**
 * Plugin Name: CCFO test fields (test fixture only)
 * Description: Registers a VAT number field the way third-party plugins do, for the E2E suite.
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
