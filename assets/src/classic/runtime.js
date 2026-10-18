/**
 * DOM side of the classic checkout rules, kept apart from event wiring for tests.
 */

/**
 * Create the runtime.
 *
 * @param {Object}   deps              Dependencies.
 * @param {Function} deps.$            jQuery.
 * @param {Object}   deps.data         Localized `ccfoCheckout` data.
 * @param {Function} deps.resolveField Field resolver.
 * @param {Function} deps.resolveFlag  Flag resolver.
 * @param {Document} deps.root         Document.
 * @return {Object} Runtime.
 */
export function createFieldRuntime( {
	$,
	data,
	resolveField,
	resolveFlag,
	root,
} ) {
	let facts = {};
	const base = { ...( data.required || {} ) };

	const fieldRules = {};
	const taxRules = [];
	for ( const rule of data.rules || [] ) {
		if ( rule.target.type === 'field' ) {
			( fieldRules[ rule.target.key ] =
				fieldRules[ rule.target.key ] || [] ).push( rule );
		} else if ( rule.target.type === 'tax_display' ) {
			taxRules.push( rule );
		}
	}

	const $form = () => $( 'form.checkout', root );

	const inputs = ( key ) =>
		$form().find( `[name="${ key }"], [name="${ key }[]"]` );

	const row = ( key ) => {
		const byId = $( `#${ $.escapeSelector( key ) }_field`, root );
		return byId.length
			? byId
			: inputs( key ).first().closest( '.form-row' );
	};

	/**
	 * Current value of a form field, '' when hidden (as the server sees it).
	 *
	 * @param {string} key Field key.
	 * @return {string|string[]} Value.
	 */
	const fieldValue = ( key ) => {
		const $inputs = inputs( key ).filter( ':enabled' );
		if ( ! $inputs.length ) {
			return '';
		}
		const first = $inputs.get( 0 );
		if ( first.type === 'radio' ) {
			return $inputs.filter( ':checked' ).val() || '';
		}
		if ( first.type === 'checkbox' ) {
			if ( $inputs.length > 1 ) {
				return $inputs
					.filter( ':checked' )
					.map( ( i, el ) => el.value )
					.get();
			}
			return first.checked ? first.value || '1' : '';
		}
		const value = $( first ).val();
		return value === null || value === undefined ? '' : value;
	};

	const shipToDifferent = () => {
		const box = $( '#ship-to-different-address-checkbox', root );
		return box.length > 0 && box.is( ':checked' );
	};

	const chosenRates = () => {
		const ids = [];
		$(
			'select.shipping_method, input.shipping_method[type="hidden"], input.shipping_method:checked',
			root
		).each( ( i, el ) => {
			const id = $( el ).val();
			if ( id ) {
				ids.push( id, id.split( ':' )[ 0 ] );
			}
		} );
		return [ ...new Set( ids ) ];
	};

	const getValue = ( source ) => {
		if ( source.startsWith( 'field:' ) ) {
			return fieldValue( source.slice( 6 ) );
		}
		const address = /^(billing|shipping)\.(country|state|postcode)$/.exec(
			source
		);
		if ( address ) {
			const prefix =
				address[ 1 ] === 'shipping' && shipToDifferent()
					? 'shipping'
					: 'billing';
			return fieldValue( `${ prefix }_${ address[ 2 ] }` );
		}
		if ( source === 'payment.method' ) {
			return (
				$( 'input[name="payment_method"]:checked', root ).val() || ''
			);
		}
		if ( source === 'shipping.method' ) {
			return chosenRates();
		}
		return Object.prototype.hasOwnProperty.call( facts, source )
			? facts[ source ]
			: null;
	};

	const setRequired = ( $row, required ) => {
		const $label = $row.find( 'label' ).first();
		if ( required ) {
			$label.find( '.optional' ).remove();
			$row.addClass( 'validate-required' );
			if ( ! $label.find( '.required' ).length ) {
				$label.append(
					'&nbsp;<span class="required" aria-hidden="true">*</span>'
				);
			}
			$row.find( ':input' ).attr( 'aria-required', 'true' );
		} else {
			$label.find( '.required' ).remove();
			$row.removeClass(
				'validate-required woocommerce-invalid woocommerce-invalid-required-field'
			);
			if ( ! $label.find( '.optional' ).length ) {
				$label.append(
					`&nbsp;<span class="optional">(${ data.optionalText })</span>`
				);
			}
			$row.find( ':input' ).removeAttr( 'aria-required' );
		}
	};

	const setVisible = ( $row, visible ) => {
		if ( visible ) {
			$row.removeClass( 'ccfo-hidden' ).removeAttr( 'aria-hidden' );
			$row.find( '[data-ccfo-disabled]' )
				.prop( 'disabled', false )
				.removeAttr( 'data-ccfo-disabled' );
		} else {
			$row.addClass( 'ccfo-hidden' ).attr( 'aria-hidden', 'true' );
			$row.find( ':input:enabled' )
				.prop( 'disabled', true )
				.attr( 'data-ccfo-disabled', '1' );
		}
	};

	return {
		setFacts( next ) {
			facts = next || {};
		},

		/**
		 * After a country change, address-i18n.js (and themes) rewrite the required
		 * state of locale fields. Take that as the new base for those fields.
		 */
		captureBase() {
			const params = window.wc_address_i18n_params;
			let selectors = '';
			try {
				selectors = Object.values(
					JSON.parse( params?.locale_fields || '{}' )
				).join( ',' );
			} catch ( e ) {
				return;
			}
			if ( ! selectors ) {
				return;
			}
			for ( const key of Object.keys( fieldRules ) ) {
				const $row = row( key );
				if ( $row.length && $row.is( selectors ) ) {
					base[ key ] = $row.hasClass( 'validate-required' );
				}
			}
		},

		apply() {
			// Fields first, in a stable order, so values of fields hidden here read as ''.
			for ( const key of Object.keys( fieldRules ) ) {
				const $row = row( key );
				if ( ! $row.length ) {
					continue;
				}
				const decision = resolveField(
					fieldRules[ key ],
					!! base[ key ],
					getValue
				);
				setVisible( $row, decision.active );
				if ( decision.active ) {
					setRequired( $row, decision.required );
				}
			}

			if ( taxRules.length ) {
				const hidden = resolveFlag(
					taxRules,
					'hide_tax_note',
					getValue
				).active;
				$( root.body ).toggleClass( 'ccfo-hide-tax-note', hidden );
			}
		},

		getValue,
	};
}
