<?php
/**
 * Test fixtures for the local test site.
 *
 * Usage:
 *   php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn eval-file tools/fixtures.php setup
 *   php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn eval-file tools/fixtures.php teardown
 *
 * Everything created or changed is recorded in the `ccfo_test_fixtures` option and
 * undone by teardown. Prints the fixture ids as JSON.
 *
 * @package CCFO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- CLI script.

$ccfo_action = $args[0] ?? 'setup';
$ccfo_state  = get_option( 'ccfo_test_fixtures', [] );

if ( 'teardown' === $ccfo_action ) {
	if ( ! $ccfo_state ) {
		echo wp_json_encode( [ 'teardown' => 'nothing to do' ] ), "\n";
		return;
	}
	foreach ( $ccfo_state['products'] ?? [] as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$product->delete( true );
		}
	}
	foreach ( $ccfo_state['terms'] ?? [] as $term ) {
		wp_delete_term( $term['id'], $term['taxonomy'] );
	}
	if ( ! empty( $ccfo_state['zone_methods'] ) ) {
		$zone = new WC_Shipping_Zone( $ccfo_state['zone_id'] );
		foreach ( $ccfo_state['zone_methods'] as $instance_id ) {
			$zone->delete_shipping_method( $instance_id );
		}
	}
	foreach ( $ccfo_state['gateways'] ?? [] as $id => $enabled ) {
		$settings            = get_option( "woocommerce_{$id}_settings", [] );
		$settings['enabled'] = $enabled;
		update_option( "woocommerce_{$id}_settings", $settings );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $ccfo_state['users'] ?? [] as $id ) {
		wp_delete_user( $id );
	}
	remove_role( 'ccfo_wholesale' );
	foreach ( $ccfo_state['pages'] ?? [] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $ccfo_state['options'] ?? [] as $name => $value ) {
		if ( false === $value ) {
			delete_option( $name ); // Did not exist before setup.
		} else {
			update_option( $name, $value );
		}
	}
	$mu = WP_CONTENT_DIR . '/mu-plugins/ccfo-test-fields.php';
	if ( file_exists( $mu ) ) {
		wp_delete_file( $mu );
	}
	delete_option( 'ccfo_rules' );
	delete_option( 'ccfo_test_fixtures' );
	echo wp_json_encode( [ 'teardown' => 'done' ] ), "\n";
	return;
}

if ( $ccfo_state ) {
	echo wp_json_encode( $ccfo_state ), "\n";
	return;
}

$ccfo_state = [
	'products'     => [],
	'terms'        => [],
	'zone_methods' => [],
	'gateways'     => [],
	'users'        => [],
	'pages'        => [],
	'options'      => [],
];

// Keep original values of options that tests switch.
// The Checkout Block syncs its field settings into the three *_field options when it is the checkout page.
foreach ( [ 'ccfo_rules', 'woocommerce_checkout_page_id', 'woocommerce_calc_taxes', 'woocommerce_tax_display_cart', 'woocommerce_prices_include_tax', 'woocommerce_checkout_company_field', 'woocommerce_checkout_address_2_field', 'woocommerce_checkout_phone_field' ] as $ccfo_option ) {
	$ccfo_state['options'][ $ccfo_option ] = get_option( $ccfo_option );
}

// Category and products.
$ccfo_term = wp_insert_term( 'CCFO Heavy', 'product_cat', [ 'slug' => 'ccfo-heavy' ] );
$ccfo_cat  = is_wp_error( $ccfo_term ) ? (int) $ccfo_term->get_error_data( 'term_exists' ) : (int) $ccfo_term['term_id'];
$ccfo_state['terms'][] = [
	'id'       => $ccfo_cat,
	'taxonomy' => 'product_cat',
];

$ccfo_products = [
	'light' => [ 'CCFO Test Light', 300000, '1', [] ],
	'heavy' => [ 'CCFO Test Heavy', 100000, '20', [ $ccfo_cat ] ],
];
foreach ( $ccfo_products as $ccfo_key => [ $ccfo_name, $ccfo_price, $ccfo_weight, $ccfo_cats ] ) {
	$ccfo_product = new WC_Product_Simple();
	$ccfo_product->set_name( $ccfo_name );
	$ccfo_product->set_slug( 'ccfo-test-' . $ccfo_key );
	$ccfo_product->set_regular_price( (string) $ccfo_price );
	$ccfo_product->set_weight( $ccfo_weight );
	$ccfo_product->set_category_ids( $ccfo_cats );
	$ccfo_product->set_status( 'publish' );
	$ccfo_product->set_tax_status( 'taxable' );
	$ccfo_product->save();
	$ccfo_state['products'][ $ccfo_key ] = $ccfo_product->get_id();
}

// Shipping methods in the zone that covers MN.
$ccfo_package = [
	'destination' => [
		'country'  => 'MN',
		'state'    => '',
		'postcode' => '',
	],
];
$ccfo_zone               = WC_Shipping_Zones::get_zone_matching_package( $ccfo_package );
$ccfo_state['zone_id']   = $ccfo_zone->get_id();
$ccfo_flat               = $ccfo_zone->add_shipping_method( 'flat_rate' );
$ccfo_pickup             = $ccfo_zone->add_shipping_method( 'local_pickup' );
update_option(
	"woocommerce_flat_rate_{$ccfo_flat}_settings",
	[
		'title'      => 'CCFO Flat',
		'tax_status' => 'taxable',
		'cost'       => '5000',
	]
);
update_option(
	"woocommerce_local_pickup_{$ccfo_pickup}_settings",
	[
		'title'      => 'CCFO Pickup',
		'tax_status' => 'none',
		'cost'       => '0',
	]
);
$ccfo_state['zone_methods'] = [ $ccfo_flat, $ccfo_pickup ];
$ccfo_state['rates']        = [
	'flat'   => "flat_rate:$ccfo_flat",
	'pickup' => "local_pickup:$ccfo_pickup",
];
foreach ( $ccfo_zone->get_shipping_methods() as $ccfo_method ) {
	if ( 'free_shipping' === $ccfo_method->id ) {
		$ccfo_state['rates']['free'] = 'free_shipping:' . $ccfo_method->get_instance_id();
	}
}

// Gateways.
foreach ( [ 'cod', 'bacs' ] as $ccfo_gateway ) {
	$ccfo_settings                         = get_option( "woocommerce_{$ccfo_gateway}_settings", [] );
	$ccfo_state['gateways'][ $ccfo_gateway ] = $ccfo_settings['enabled'] ?? 'no';
	$ccfo_settings['enabled']              = 'yes';
	update_option( "woocommerce_{$ccfo_gateway}_settings", $ccfo_settings );
}

// Users.
add_role( 'ccfo_wholesale', 'CCFO Wholesale', [ 'read' => true ] );
foreach ( [ 'customer' => 'customer', 'wholesale' => 'ccfo_wholesale', 'admin' => 'administrator' ] as $ccfo_key => $ccfo_role ) {
	$ccfo_user = wp_insert_user(
		[
			'user_login' => "ccfo_$ccfo_key",
			'user_pass'  => 'ccfo-test-pass-123',
			'user_email' => "ccfo_$ccfo_key@example.test",
			'role'       => $ccfo_role,
		]
	);
	if ( ! is_wp_error( $ccfo_user ) ) {
		$ccfo_state['users'][ $ccfo_key ] = $ccfo_user;
	}
}

// Checkout Block page.
$ccfo_state['pages']['block_checkout'] = wp_insert_post(
	[
		'post_title'   => 'CCFO Block Checkout',
		'post_name'    => 'ccfo-block-checkout',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		// WooCommerce's own default Checkout Block markup; without inner blocks it renders the classic form.
		'post_content' => ( new ReflectionMethod( 'WC_Install', 'get_checkout_block_content' ) )->invoke( null ),
	]
);
$ccfo_state['pages']['classic_checkout'] = (int) get_option( 'woocommerce_checkout_page_id' );

// Test-only field registered the way third-party plugins do.
$ccfo_mu_dir = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p( $ccfo_mu_dir );
copy( dirname( __DIR__ ) . '/tests/e2e/mu-plugins/ccfo-test-fields.php', $ccfo_mu_dir . '/ccfo-test-fields.php' );

update_option( 'ccfo_test_fixtures', $ccfo_state, false );
echo wp_json_encode( $ccfo_state ), "\n";
