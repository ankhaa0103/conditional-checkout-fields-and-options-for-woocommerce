<?php
/**
 * REST API for the admin UI (namespace ccfo/v1).
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Admin;

defined( 'ABSPATH' ) || exit;

use CCFO\Engine\RequestLog;
use CCFO\Plugin;
use CCFO\Rules\Definitions;
use CCFO\Rules\Linter;
use CCFO\Rules\Rule;
use CCFO\Rules\RuleSchema;
use CCFO\Settings;

/**
 * All routes need the `manage_woocommerce` capability; the REST nonce is checked by WordPress.
 */
final class RestController {

	public const NAMESPACE = 'ccfo/v1';

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
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$permission = [ $this, 'can_manage' ];

		register_rest_route(
			self::NAMESPACE,
			'/rules',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_rules' ],
					'permission_callback' => $permission,
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save_rules' ],
					'permission_callback' => $permission,
					'args'                => [
						'revision' => [
							'type'     => 'integer',
							'required' => true,
						],
						'rules'    => [
							'type'     => 'array',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lint',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'lint' ],
				'permission_callback' => $permission,
				'args'                => [
					'rules' => [
						'type'     => 'array',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'catalog' ],
				'permission_callback' => $permission,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/products',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'products' ],
				'permission_callback' => $permission,
				'args'                => [
					'search'  => [
						'type'    => 'string',
						'default' => '',
					],
					'include' => [
						'type'    => 'array',
						'items'   => [ 'type' => 'integer' ],
						'default' => [],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn () => rest_ensure_response( Settings::all() ),
					'permission_callback' => $permission,
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn ( \WP_REST_Request $request ) => rest_ensure_response( Settings::save( (array) $request->get_json_params() ) ),
					'permission_callback' => $permission,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/blocked',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'blocked' ],
					'permission_callback' => $permission,
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear_blocked' ],
					'permission_callback' => $permission,
				],
			]
		);
	}

	/**
	 * Permission check.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Stored rules with lint findings.
	 */
	public function get_rules(): \WP_REST_Response {
		$stored = $this->plugin->rules->stored();
		$schema = new RuleSchema();
		$rules  = $schema->validate( $stored['rules'] );

		return rest_ensure_response(
			[
				'revision'    => $stored['revision'],
				'rules'       => array_map( static fn ( Rule $r ): array => $r->to_array(), $rules ),
				'invalid'     => $schema->errors(),
				'findings'    => $this->findings( $rules ),
				'definitions' => Definitions::export(),
			]
		);
	}

	/**
	 * Save the full rule set.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_rules( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->rules->save(
			$request->get_param( 'rules' ),
			(int) $request->get_param( 'revision' ),
			StoreInfo::schema_env()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'revision' => $result['revision'],
				'rules'    => array_map( static fn ( Rule $r ): array => $r->to_array(), $result['rules'] ),
				'findings' => $this->findings( $result['rules'] ),
			]
		);
	}

	/**
	 * Lint unsaved rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function lint( \WP_REST_Request $request ): \WP_REST_Response {
		$schema = new RuleSchema();
		$rules  = $schema->validate( $request->get_param( 'rules' ), StoreInfo::schema_env() );
		return rest_ensure_response(
			[
				'errors'   => $schema->errors(),
				'findings' => $this->findings( $rules ),
			]
		);
	}

	/**
	 * Store catalog.
	 */
	public function catalog(): \WP_REST_Response {
		return rest_ensure_response( ( new StoreInfo( $this->plugin ) )->catalog() );
	}

	/**
	 * Product search, or labels of given ids.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function products( \WP_REST_Request $request ): \WP_REST_Response {
		$include = array_filter( array_map( 'absint', (array) $request->get_param( 'include' ) ) );
		$args    = [
			'limit'   => 20,
			'status'  => [ 'publish', 'private' ],
			'type'    => array_merge( array_keys( wc_get_product_types() ), [ 'variation' ] ),
			'orderby' => 'title',
			'order'   => 'ASC',
		];
		if ( [] !== $include ) {
			$args['include'] = $include;
			$args['limit']   = count( $include );
		} else {
			$args['s'] = sanitize_text_field( (string) $request->get_param( 'search' ) );
		}

		$out = [];
		foreach ( wc_get_products( $args ) as $product ) {
			if ( $product instanceof \WC_Product ) {
				// get_formatted_name() already adds the SKU, or the id when there is none.
				$out[] = [
					'id'    => (string) $product->get_id(),
					'label' => html_entity_decode( wp_strip_all_tags( $product->get_formatted_name() ), ENT_QUOTES ),
				];
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Recent "everything removed" events.
	 */
	public function blocked(): \WP_REST_Response {
		$log = get_option( RequestLog::BLOCKED_OPTION, [] );
		return rest_ensure_response( is_array( $log ) ? array_values( $log ) : [] );
	}

	/**
	 * Clear the events (admin has seen them).
	 */
	public function clear_blocked(): \WP_REST_Response {
		delete_option( RequestLog::BLOCKED_OPTION );
		return rest_ensure_response( [] );
	}

	/**
	 * Lint findings for rules.
	 *
	 * @param list<Rule> $rules Rules.
	 * @return list<array{rule: string, level: string, code: string, message: string}>
	 */
	private function findings( array $rules ): array {
		return ( new Linter() )->lint( $rules, ( new StoreInfo( $this->plugin ) )->lint_env() );
	}
}
