<?php
/**
 * Remove plugin data on uninstall.
 *
 * Order meta `_ccfo_applied_rules` is kept: it documents what happened at checkout.
 *
 * @package CCFO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'ccfo_rules', 'ccfo_settings', 'ccfo_blocked_log', 'ccfo_field_snapshot' ] as $ccfo_option ) {
	delete_option( $ccfo_option );
}
