/**
 * Classic checkout: applies field and tax display rules as the customer types,
 * and refreshes the checkout when payment, shipping or tax rules may change.
 *
 * The server decides again on submit; this only keeps the form in step.
 */
import { applyOperator, resolveField, resolveFlag } from '../shared/evaluator';
import { createFieldRuntime } from './runtime';
import { createRefresher } from './refresher';

( function ( $, data ) {
	if ( ! $ || ! data ) {
		return;
	}

	const runtime = createFieldRuntime( {
		$,
		data,
		resolveField,
		resolveFlag,
		root: document,
	} );

	const refresher = createRefresher( {
		conditions: data.triggers?.conditions || [],
		delay: Number( data.updateDelay ) || 0,
		evaluate: ( c ) =>
			applyOperator( c.operator, runtime.getValue( c.source ), c.value ),
		refresh: () => $( document.body ).trigger( 'update_checkout' ),
	} );

	const readContext = () => {
		const el = document.querySelector( 'script.ccfo-ctx' );
		if ( ! el ) {
			return;
		}
		try {
			const ctx = JSON.parse( el.textContent || '{}' );
			runtime.setFacts( ctx.facts || {} );
		} catch ( e ) {
			// Keep the previous facts.
		}
	};

	const later = ( fn ) => window.setTimeout( fn, 0 );
	let timer = null;
	const debounced = () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( () => runtime.apply(), 120 );
	};

	$( function () {
		readContext();
		runtime.apply();
		refresher.sent();

		const $form = $( 'form.checkout' );
		$form.on( 'input change', ':input', debounced );

		// Server-side rules read form values: refresh when one of their results changes,
		// right away when the customer leaves the field, at the latest after the delay while typing.
		$form.on( 'input', ':input', () => refresher.changed( false ) );
		$form.on( 'change', ':input', () => refresher.changed( true ) );

		if ( data.triggers?.payment ) {
			$form.on( 'change', 'input[name="payment_method"]', () =>
				$( document.body ).trigger( 'update_checkout' )
			);
		}
	} );

	$( document.body )
		.on( 'updated_checkout', () => {
			readContext();
			runtime.apply();
			refresher.sent();
		} )
		.on( 'payment_method_selected', () => runtime.apply() )
		// WooCommerce (address-i18n.js) and themes reset address fields on country change; re-apply after them.
		.on( 'country_to_state_changing country_to_state_changed', () =>
			later( () => {
				runtime.captureBase();
				runtime.apply();
			} )
		);
} )( window.jQuery, window.ccfoCheckout );
