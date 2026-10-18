<?php
/**
 * Field visibility and required state in the classic checkout.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Classic;

use CCFO\Engine\ContextFactory;
use CCFO\Engine\Resolver;
use CCFO\Plugin;
use CCFO\Rules\Definitions;

/**
 * Applies field rules through `woocommerce_checkout_fields` (WC_Checkout::get_checkout_fields()).
 *
 * - Page render: hidden fields get the `ccfo-hidden` class; the browser takes over from there.
 * - Submit: hidden fields get `hidden => true`, which WC_Checkout::validate_posted_data()
 *   treats as not required, and their posted values are cleared before the order is made.
 */
final class Fields {

	/**
	 * Field definitions before our changes, by key.
	 *
	 * @var array<string, array{fieldset: string, label: string, required: bool, type: string}>
	 */
	private array $originals = [];

	/**
	 * Fields hidden in this request.
	 *
	 * @var list<string>
	 */
	private array $hidden = [];

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
		// Run after themes and field editors (Woodmart uses 99999) so we see the final fields.
		add_filter( 'woocommerce_checkout_fields', [ $this, 'filter_fields' ], PHP_INT_MAX - 10 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'clear_hidden_values' ], PHP_INT_MAX - 10 );
	}

	/**
	 * Apply field rules.
	 *
	 * @param mixed $fieldsets Fields by fieldset.
	 * @return mixed
	 */
	public function filter_fields( mixed $fieldsets ): mixed {
		if ( ! is_array( $fieldsets ) ) {
			return $fieldsets;
		}

		$this->remember_originals( $fieldsets );

		$index = $this->plugin->rules->index();
		if ( ! $index->has( Definitions::TARGET_FIELD ) ) {
			return $fieldsets;
		}

		$mode         = $this->plugin->contexts->mode();
		$context      = $this->plugin->contexts->make();
		$this->hidden = [];

		foreach ( $fieldsets as $fieldset => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $key => $field ) {
				$rules = $index->for_target( Definitions::TARGET_FIELD, (string) $key );
				if ( [] === $rules || ! is_array( $field ) ) {
					continue;
				}

				$decision = Resolver::field( $rules, ! empty( $field['required'] ), $context );
				$this->plugin->log->record( 'field:' . $key, $decision );

				if ( $decision->active ) {
					$field['required'] = $decision->required;
				} elseif ( ContextFactory::RENDER === $mode ) {
					$field['required'] = false;
					$field['class']    = array_merge( (array) ( $field['class'] ?? [] ), [ 'ccfo-hidden' ] );
				} else {
					$field['required'] = false;
					$field['hidden']   = true;
					$this->hidden[]    = (string) $key;
				}

				$fieldsets[ $fieldset ][ $key ] = $field;
			}
		}

		return $fieldsets;
	}

	/**
	 * Clear values of hidden fields so they are not validated or stored.
	 *
	 * @param mixed $data Posted data.
	 * @return mixed
	 */
	public function clear_hidden_values( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $this->hidden as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$data[ $key ] = '';
			}
		}
		return $data;
	}

	/**
	 * Field definitions before our changes.
	 *
	 * @return array<string, array{fieldset: string, label: string, required: bool, type: string}>
	 */
	public function originals(): array {
		return $this->originals;
	}

	/**
	 * Keys hidden in this request.
	 *
	 * @return list<string>
	 */
	public function hidden(): array {
		return $this->hidden;
	}

	/**
	 * Remember the definitions other code produced.
	 *
	 * @param array<mixed> $fieldsets Fields by fieldset.
	 */
	private function remember_originals( array $fieldsets ): void {
		foreach ( $fieldsets as $fieldset => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
				if ( '' === $label && isset( $field['placeholder'] ) && is_string( $field['placeholder'] ) ) {
					$label = $field['placeholder'];
				}
				$this->originals[ (string) $key ] = [
					'fieldset' => (string) $fieldset,
					'label'    => wp_strip_all_tags( $label ),
					'required' => ! empty( $field['required'] ),
					'type'     => isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text',
				];
			}
		}
	}
}
