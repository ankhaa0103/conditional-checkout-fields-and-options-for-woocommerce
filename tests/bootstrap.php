<?php
/**
 * Unit test bootstrap: Composer autoload and minimal WordPress function stubs.
 *
 * @package CCFO
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags stub (same behaviour as WordPress for plain input).
	 *
	 * @param string $text Text.
	 */
	function wp_strip_all_tags( $text ) {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return trim( strip_tags( $text ) );
	}
}
