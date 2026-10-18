<?php
/**
 * REST API, settings, catalog, plugin header and compatibility (SPEC 1.1, 1.11, 2.5, 2.7, §8, §10).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Tests\integration;

use CCFO\Engine\RequestLog;
use CCFO\Plugin;
use CCFO\Settings;

/**
 * Admin-facing behaviour.
 */
final class AdminApiTest extends IntegrationTestCase {

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		delete_option( RequestLog::BLOCKED_OPTION );
		parent::tearDown();
	}

	/**
	 * REST request as the current user.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body.
	 * @param array<string, mixed> $query  Query.
	 */
	private function call( string $method, string $route, array $body = [], array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/ccfo/v1' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_do_request( $request );
	}

	/**
	 * Response data as arrays.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return array<mixed>
	 */
	private static function data( \WP_REST_Response $response ): array {
		return json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}

	private function admin(): void {
		wp_set_current_user( (int) $this->fx['users']['admin'] );
	}

	public function test_every_route_needs_manage_woocommerce(): void {
		$routes = [
			[ 'GET', '/rules' ],
			[ 'PUT', '/rules' ],
			[ 'POST', '/lint' ],
			[ 'GET', '/catalog' ],
			[ 'GET', '/products' ],
			[ 'GET', '/settings' ],
			[ 'POST', '/settings' ],
			[ 'GET', '/blocked' ],
			[ 'DELETE', '/blocked' ],
		];
		foreach ( [
			0                                    => 401,
			(int) $this->fx['users']['customer'] => 403,
		] as $user => $status ) {
			wp_set_current_user( $user );
			foreach ( $routes as [ $method, $route ] ) {
				$body = 'PUT' === $method ? [
					'revision' => 0,
					'rules'    => [],
				] : ( 'POST' === $method && '/lint' === $route ? [ 'rules' => [] ] : [] );
				$this->assertSame( $status, $this->call( $method, $route, $body )->get_status(), "$method $route as user $user" );
			}
		}
	}

	public function test_save_validate_and_revision_conflict(): void {
		$this->admin();
		$current = self::data( $this->call( 'GET', '/rules' ) );
		$this->assertArrayHasKey( 'definitions', $current );

		$rule  = $this->rule(
			'a',
			'payment',
			'cod',
			'disable',
			[
				[
					[
						'source'   => 'cart.total',
						'operator' => 'gt',
						'value'    => 5,
					],
				],
			]
		);
		$saved = $this->call(
			'PUT',
			'/rules',
			[
				'revision' => $current['revision'],
				'rules'    => [ $rule ],
			]
		);
		$this->assertSame( 200, $saved->get_status() );
		$saved = self::data( $saved );
		$this->assertSame( $current['revision'] + 1, $saved['revision'] );
		$this->assertSame( 'cod', $saved['rules'][0]['target']['key'] );

		// Saving from the old revision is refused (another window saved meanwhile).
		$stale = $this->call(
			'PUT',
			'/rules',
			[
				'revision' => $current['revision'],
				'rules'    => [],
			]
		);
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'ccfo_revision_conflict', $stale->as_error()->get_error_code() );

		// Invalid rules are refused with the reasons.
		$bad           = $rule;
		$bad['groups'] = [
			[
				[
					'source'   => 'payment.method',
					'operator' => 'equals',
					'value'    => 'cod',
				],
			],
		];
		$invalid       = $this->call(
			'PUT',
			'/rules',
			[
				'revision' => $saved['revision'],
				'rules'    => [ $bad ],
			]
		);
		$this->assertSame( 400, $invalid->get_status() );
		$errors = $invalid->as_error()->get_error_data()['errors'];
		$this->assertSame( 'groups.0.0.source', $errors[0]['path'] );

		// Nothing was stored by the refused calls.
		$this->assertSame( $saved['revision'], self::data( $this->call( 'GET', '/rules' ) )['revision'] );
	}

	public function test_lint_endpoint_reports_errors_and_findings(): void {
		$this->admin();
		$result = self::data(
			$this->call(
				'POST',
				'/lint',
				[
					'rules' => [
						$this->rule( 's', 'field', 'billing_company', 'show' ),
						$this->rule( 'h', 'field', 'billing_company', 'hide' ),
						$this->rule( 'x', 'payment', 'not a key', 'disable' ),
					],
				]
			)
		);
		$this->assertSame( 'target.key', $result['errors'][0]['path'] );
		$this->assertContains( 'conflict', array_column( $result['findings'], 'code' ) );
	}

	public function test_catalog_lists_store_objects(): void {
		$this->admin();
		$catalog = self::data( $this->call( 'GET', '/catalog' ) );

		$fields = array_column( $catalog['fields'], null, 'key' );
		// SPEC 1.1: core fields of every fieldset and fields of other plugins.
		foreach ( [ 'billing_first_name', 'shipping_city', 'order_comments', 'billing_vat' ] as $key ) {
			$this->assertArrayHasKey( $key, $fields, $key );
		}
		$this->assertTrue( $fields['billing_first_name']['required'] );
		$this->assertSame( 'submit', $fields['billing_company']['block'] );
		$this->assertSame( 'none', $fields['billing_vat']['block'], 'third-party classic field is marked for the Checkout Block' );

		$this->assertContains( 'cod', array_column( $catalog['gateways'], 'id' ) );
		$shipping = array_column( $catalog['shipping'], 'id' );
		$this->assertContains( 'flat_rate', $shipping );
		$this->assertContains( $this->fx['rates']['flat'], $shipping );
		$this->assertContains( 'ccfo_wholesale', array_column( $catalog['roles'], 'id' ) );
		$this->assertContains( (string) $this->fx['terms'][0]['id'], array_column( $catalog['categories'], 'id' ) );
		$this->assertSame( '', $catalog['taxClasses'][0]['id'] );
		$this->assertArrayHasKey( 'MN', $catalog['countries'] );
		$this->assertArrayHasKey( 'block_checkout', $catalog['store'] );

		// SPEC 2.5 / ARCHITECTURE §6: Checkout Block additional fields are listed as managed by their plugin.
		$additional = array_column( $catalog['additionalFields'], null, 'key' );
		$this->assertArrayHasKey( 'ccfo-test/po-number', $additional );
		$this->assertSame( 'order', $additional['ccfo-test/po-number']['location'] );
		$this->assertArrayNotHasKey( 'ccfo-test/po-number', $fields, 'not offered as a rule target' );
	}

	public function test_product_search_and_labels(): void {
		$this->admin();
		$found = self::data( $this->call( 'GET', '/products', [], [ 'search' => 'CCFO Test' ] ) );
		$this->assertContains( (string) $this->fx['products']['light'], array_column( $found, 'id' ) );

		$by_id = self::data( $this->call( 'GET', '/products', [], [ 'include' => [ $this->fx['products']['heavy'] ] ] ) );
		$this->assertCount( 1, $by_id );
		$this->assertStringStartsWith( 'CCFO Test Heavy', $by_id[0]['label'] );
		$this->assertSame( 1, substr_count( $by_id[0]['label'], '#' . $this->fx['products']['heavy'] ), 'id shown once' );
	}

	public function test_settings_defaults_sanitising_and_limits(): void {
		$this->admin();
		$defaults = self::data( $this->call( 'GET', '/settings' ) );
		$this->assertSame( '5', $defaults['live_update_delay'] );
		$this->assertNotSame( '', $defaults['payment_blocked_message'] );

		$saved = self::data(
			$this->call(
				'POST',
				'/settings',
				[
					'payment_blocked_message' => '<script>x</script>Call us',
					'live_update_delay'       => '999',
					'unknown'                 => 'ignored',
				]
			)
		);
		$this->assertSame( 'Call us', $saved['payment_blocked_message'] );
		$this->assertSame( '30', $saved['live_update_delay'] );
		$this->assertArrayNotHasKey( 'unknown', get_option( Settings::OPTION ) );

		$this->assertSame( '0', Settings::save( [ 'live_update_delay' => '-4' ] )['live_update_delay'] );
		// Empty message falls back to the default text.
		$this->assertSame( $defaults['shipping_blocked_message'], Settings::save( [ 'shipping_blocked_message' => '  ' ] )['shipping_blocked_message'] );
	}

	public function test_blocked_log_read_and_clear(): void {
		Plugin::instance()->log->block( 'payment', [ 'r1', 'r2' ] );
		Plugin::instance()->log->block( 'payment', [ 'r1', 'r2' ] );
		$this->admin();
		$log = self::data( $this->call( 'GET', '/blocked' ) );
		$this->assertCount( 1, $log, 'same event within 10 minutes is stored once' );
		$this->assertSame( [ 'r1', 'r2' ], $log[0]['rules'] );

		$this->assertSame( 200, $this->call( 'DELETE', '/blocked' )->get_status() );
		$this->assertSame( [], self::data( $this->call( 'GET', '/blocked' ) ) );
	}

	public function test_invalid_stored_rules_are_skipped_not_fatal(): void {
		$this->rules(
			[
				$this->rule( 'ok', 'payment', 'cod', 'disable' ),
				[ 'id' => 'broken' ],
			]
		);
		$index = Plugin::instance()->rules->index();
		$this->assertSame( [ 'ok' ], array_map( static fn ( $r ) => $r->id, $index->all() ) );
	}

	public function test_plugin_header_matches_spec(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( CCFO_FILE, false, false );
		$this->assertSame( '6.6', $data['RequiresWP'] );
		$this->assertSame( '8.1', $data['RequiresPHP'] );
		$this->assertSame( 'woocommerce', $data['RequiresPlugins'] );
		$this->assertSame( 'conditional-checkout-fields-and-options-for-woocommerce', $data['TextDomain'] );
		$this->assertSame( CCFO_VERSION, $data['Version'] );
		$this->assertStringContainsString( 'WC requires at least: 9.9.3', (string) file_get_contents( CCFO_FILE ) );
	}

	public function test_hpos_and_blocks_compatibility_declared(): void {
		$features = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
		$basename = plugin_basename( CCFO_FILE );
		foreach ( [ 'custom_order_tables', 'cart_checkout_blocks' ] as $feature ) {
			$this->assertContains( $basename, $features->get_compatible_plugins_for_feature( $feature, true )['compatible'], $feature );
		}
	}
}
