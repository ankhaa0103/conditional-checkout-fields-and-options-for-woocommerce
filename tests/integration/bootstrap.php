<?php
/**
 * Integration bootstrap: loads the local test site (C:\wamp64\www\brand.mn by default).
 *
 * Needs the fixtures: php tools/wp-cli.phar --path=<site> eval-file tools/fixtures.php setup
 *
 * @package CCFO
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$ccfo_site = getenv( 'CCFO_WP_PATH' ) ? getenv( 'CCFO_WP_PATH' ) : 'C:/wamp64/www/brand.mn';

$_SERVER['HTTP_HOST']   = 'brand.mn';
$_SERVER['SERVER_NAME'] = 'brand.mn';
$_SERVER['HTTPS']       = 'on';
$_SERVER['REQUEST_URI'] = '/';

define( 'WP_USE_THEMES', false );
require_once $ccfo_site . '/wp-load.php';

if ( ! class_exists( \CCFO\Plugin::class ) || ! get_option( 'ccfo_test_fixtures' ) ) {
	fwrite( STDERR, "Plugin inactive or fixtures missing. Run tools/fixtures.php setup first.\n" );
	exit( 1 );
}
