/**
 * Value input for a condition, shaped by the operator.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	ComboboxControl,
	FormTokenField,
	TextControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { valueOptions } from './options';

/**
 * Token field mapping labels to ids.
 *
 * @param {Object}   props
 * @param {Array}    props.value    Selected ids.
 * @param {Array}    props.options  {value,label} options.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.free     Allow values not in options.
 * @param {Function} props.onInput  Search input handler.
 */
function TokenInput( { value, options, onChange, free = false, onInput } ) {
	const byValue = new Map(
		options.map( ( o ) => [ String( o.value ), o.label ] )
	);
	const byLabel = new Map(
		options.map( ( o ) => [ o.label, String( o.value ) ] )
	);
	const tokens = ( value || [] ).map(
		( v ) => byValue.get( String( v ) ) || String( v )
	);

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __(
				'Values',
				'conditional-checkout-fields-and-options-for-woocommerce'
			) }
			hideLabelFromVision
			value={ tokens }
			suggestions={ options.map( ( o ) => o.label ) }
			onInputChange={ onInput }
			__experimentalExpandOnFocus
			__experimentalValidateInput={ ( token ) =>
				free || byLabel.has( token )
			}
			onChange={ ( next ) =>
				onChange(
					next
						.map( ( t ) => ( typeof t === 'string' ? t : t.value ) )
						.map(
							( t ) =>
								byLabel.get( t ) ||
								( free || byValue.has( t ) ? t : null )
						)
						.filter( ( t ) => t !== null && t !== '' )
				)
			}
		/>
	);
}

/**
 * Product picker with remote search.
 *
 * @param {Object}   props
 * @param {Array}    props.value    Product ids.
 * @param {Function} props.onChange Change handler.
 */
function ProductInput( { value, onChange } ) {
	const [ options, setOptions ] = useState( [] );

	useEffect( () => {
		if ( ! value?.length ) {
			return;
		}
		apiFetch( {
			path: `/ccfo/v1/products?${ value
				.map( ( id ) => `include[]=${ encodeURIComponent( id ) }` )
				.join( '&' ) }`,
		} ).then( ( found ) =>
			setOptions( ( prev ) => mergeOptions( prev, found ) )
		);
		// Only resolve labels on first render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const search = ( text ) => {
		if ( text.length < 2 ) {
			return;
		}
		apiFetch( {
			path: `/ccfo/v1/products?search=${ encodeURIComponent( text ) }`,
		} ).then( ( found ) =>
			setOptions( ( prev ) => mergeOptions( prev, found ) )
		);
	};

	return (
		<TokenInput
			value={ value }
			options={ options }
			onChange={ onChange }
			onInput={ search }
		/>
	);
}

/**
 * Merge products from /ccfo/v1/products ({id, label}) into {value, label} options.
 *
 * @param {Array} prev  Current options.
 * @param {Array} found API results.
 * @return {Array} Options.
 */
function mergeOptions( prev, found ) {
	const map = new Map( prev.map( ( o ) => [ o.value, o ] ) );
	( found || [] ).forEach( ( p ) =>
		map.set( String( p.id ), { value: String( p.id ), label: p.label } )
	);
	return [ ...map.values() ];
}

/**
 * Value input.
 *
 * @param {Object}   props
 * @param {string}   props.source   Source id.
 * @param {string}   props.shape    none|scalar|number|list|range.
 * @param {*}        props.value    Value.
 * @param {Object}   props.catalog  Catalog.
 * @param {Function} props.onChange Change handler.
 */
export default function ValueInput( {
	source,
	shape,
	value,
	catalog,
	onChange,
} ) {
	if ( shape === 'none' ) {
		return null;
	}

	const options = valueOptions( source, catalog );

	if ( shape === 'number' ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="number"
				step="any"
				label={ __(
					'Value',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				hideLabelFromVision
				value={ value ?? '' }
				onChange={ ( v ) => onChange( v === '' ? '' : Number( v ) ) }
			/>
		);
	}

	if ( shape === 'range' ) {
		const [ min, max ] = Array.isArray( value ) ? value : [ '', '' ];
		const set = ( i, v ) => {
			const next = [ min, max ];
			next[ i ] = v === '' ? '' : Number( v );
			onChange( next );
		};
		return (
			<Flex>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						step="any"
						label={ __(
							'Minimum',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						hideLabelFromVision
						placeholder={ __(
							'Min',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ min ?? '' }
						onChange={ ( v ) => set( 0, v ) }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						step="any"
						label={ __(
							'Maximum',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						hideLabelFromVision
						placeholder={ __(
							'Max',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ max ?? '' }
						onChange={ ( v ) => set( 1, v ) }
					/>
				</FlexItem>
			</Flex>
		);
	}

	if ( shape === 'list' ) {
		if ( source === 'cart.products' ) {
			return <ProductInput value={ value || [] } onChange={ onChange } />;
		}
		return (
			<TokenInput
				value={ value || [] }
				options={ options || [] }
				onChange={ onChange }
				free={ ! options }
			/>
		);
	}

	// Scalar.
	if ( options ) {
		return (
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __(
					'Value',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				hideLabelFromVision
				value={ value ?? '' }
				options={ options }
				onChange={ ( v ) => onChange( v ?? '' ) }
			/>
		);
	}
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __(
				'Value',
				'conditional-checkout-fields-and-options-for-woocommerce'
			) }
			hideLabelFromVision
			value={ value ?? '' }
			onChange={ onChange }
		/>
	);
}
