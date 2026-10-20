/**
 * Editor for one rule: target, action and condition groups.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ComboboxControl,
	Flex,
	FlexBlock,
	FlexItem,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import {
	ACTION_LABELS,
	OPERATOR_LABELS,
	SOURCE_GROUPS,
	SOURCE_LABELS,
	TARGET_LABELS,
} from './labels';
import { fieldOptions, targetKeyOptions } from './options';
import ValueInput from './ValueInput';

const CATEGORY_PREFIX = 'cart.category_subtotal:';
const FIELD_PREFIX = 'field:';

/**
 * Sources chosen in two steps: the kind in the condition list, then the field or
 * the category. Stored as one id, e.g. "field:billing_company".
 *
 * @param {string} source Source id.
 * @return {string|null} Prefix of the family, or null.
 */
const familyOf = ( source ) =>
	[ FIELD_PREFIX, CATEGORY_PREFIX ].find( ( p ) => source.startsWith( p ) ) ||
	null;

const sourceType = ( source, definitions ) => {
	if ( source.startsWith( FIELD_PREFIX ) ) {
		return definitions.fieldSource;
	}
	if ( source.startsWith( CATEGORY_PREFIX ) ) {
		return definitions.categorySource;
	}
	return definitions.sources[ source ];
};

const ORDER_ACTIONS = [ 'block_checkout', 'block_order' ];

/**
 * Sources allowed for the target, as grouped select options.
 * Fields and categories are chosen in a second step (familyKeyOptions).
 *
 * @param {Object} rule        Rule.
 * @param {Object} definitions Definitions.
 * @return {Array} Options.
 */
function sourceOptions( rule, definitions ) {
	const options = [];
	const allowed = Object.entries( definitions.sources ).filter( ( [ , d ] ) =>
		d.targets.includes( rule.target.type )
	);

	for ( const group of SOURCE_GROUPS ) {
		const prefixes = [].concat( group.prefix );
		const items = allowed.filter( ( [ id ] ) =>
			prefixes.some( ( p ) => id.startsWith( p ) )
		);
		items.forEach( ( [ id ] ) =>
			options.push( {
				value: id,
				label: `${ group.label }: ${ SOURCE_LABELS[ id ] || id }`,
			} )
		);
	}

	if ( definitions.categorySource?.targets.includes( rule.target.type ) ) {
		options.push( {
			value: CATEGORY_PREFIX,
			label: __(
				'Cart: Subtotal of a product category… (before discounts, excl. tax)',
				'conditional-checkout-fields-and-options-for-woocommerce'
			),
		} );
	}

	options.push( {
		value: FIELD_PREFIX,
		label: __(
			'Field value: a checkout field…',
			'conditional-checkout-fields-and-options-for-woocommerce'
		),
	} );

	return options;
}

/**
 * Second step: the field or category of a two-step source.
 *
 * @param {string} family  Prefix of the family.
 * @param {Object} rule    Rule.
 * @param {Object} catalog Catalog.
 * @return {Array} Options.
 */
function familyKeyOptions( family, rule, catalog ) {
	if ( family === CATEGORY_PREFIX ) {
		return ( catalog.categories || [] ).map( ( c ) => ( {
			value: String( c.id ),
			label: c.label,
		} ) );
	}
	// A field rule cannot depend on its own field.
	return fieldOptions( catalog ).filter(
		( o ) =>
			! ( rule.target.type === 'field' && o.value === rule.target.key )
	);
}

function newCondition( source, definitions ) {
	const type = sourceType( source, definitions )?.type;
	const operator = definitions.operators[ type ]?.[ 0 ] || 'equals';
	return { source, operator };
}

/**
 * One condition row.
 *
 * @param {Object}   props
 * @param {Object}   props.condition   Condition.
 * @param {Object}   props.rule        Rule.
 * @param {Object}   props.definitions Definitions.
 * @param {Object}   props.catalog     Catalog.
 * @param {Function} props.onChange    Change handler.
 * @param {Function} props.onRemove    Remove handler.
 */
function ConditionRow( {
	condition,
	rule,
	definitions,
	catalog,
	onChange,
	onRemove,
} ) {
	const def = sourceType( condition.source, definitions );
	const operators = def ? definitions.operators[ def.type ] : [];
	const shape = definitions.operatorValues[ condition.operator ] || 'none';
	const family = familyOf( condition.source );
	const key = family ? condition.source.slice( family.length ) : '';

	return (
		<Flex className="ccfo-condition" align="flex-start" wrap>
			<FlexBlock className="ccfo-condition__source">
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Condition',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
					hideLabelFromVision
					value={ family || condition.source }
					options={ sourceOptions( rule, definitions ) }
					onChange={ ( source ) => {
						// Picking the same kind again keeps the chosen field or category.
						if (
							! source ||
							source === ( family || condition.source )
						) {
							return;
						}
						onChange( newCondition( source, definitions ) );
					} }
				/>
			</FlexBlock>
			{ family && (
				<FlexBlock className="ccfo-condition__key">
					<ComboboxControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={
							family === CATEGORY_PREFIX
								? __(
										'Product category',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  )
								: __(
										'Checkout field',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  )
						}
						hideLabelFromVision
						placeholder={
							family === CATEGORY_PREFIX
								? __(
										'Choose a category',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  )
								: __(
										'Choose a field',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  )
						}
						value={ key }
						options={ familyKeyOptions( family, rule, catalog ) }
						onChange={ ( next ) =>
							onChange( {
								...condition,
								source: family + ( next || '' ),
							} )
						}
					/>
				</FlexBlock>
			) }
			<FlexItem className="ccfo-condition__operator">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Operator',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
					hideLabelFromVision
					value={ condition.operator }
					options={ operators.map( ( op ) => ( {
						value: op,
						label: OPERATOR_LABELS[ op ] || op,
					} ) ) }
					onChange={ ( operator ) => {
						const nextShape =
							definitions.operatorValues[ operator ];
						const next = { source: condition.source, operator };
						if (
							nextShape === shape &&
							condition.value !== undefined
						) {
							next.value = condition.value;
						}
						onChange( next );
					} }
				/>
			</FlexItem>
			<FlexBlock className="ccfo-condition__value">
				<ValueInput
					source={ condition.source }
					shape={ shape }
					value={ condition.value }
					catalog={ catalog }
					onChange={ ( value ) =>
						onChange( { ...condition, value } )
					}
				/>
			</FlexBlock>
			<FlexItem>
				<Button
					icon="no-alt"
					label={ __(
						'Remove condition',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
					onClick={ onRemove }
				/>
			</FlexItem>
		</Flex>
	);
}

/**
 * Rule editor.
 *
 * @param {Object}   props
 * @param {Object}   props.rule        Rule.
 * @param {Object}   props.definitions Definitions.
 * @param {Object}   props.catalog     Catalog.
 * @param {Function} props.onChange    Change handler.
 */
export default function RuleEditor( { rule, definitions, catalog, onChange } ) {
	const set = ( patch ) => onChange( { ...rule, ...patch } );
	const actions = definitions.actions[ rule.target.type ] || [];
	const keyOptions = targetKeyOptions( rule.target.type, catalog );
	const defaultSource = Object.keys( definitions.sources ).find( ( id ) =>
		definitions.sources[ id ].targets.includes( rule.target.type )
	);

	const setGroups = ( groups ) => set( { groups } );
	const updateCondition = ( g, c, condition ) =>
		setGroups(
			rule.groups.map( ( group, gi ) =>
				gi === g
					? group.map( ( x, ci ) => ( ci === c ? condition : x ) )
					: group
			)
		);
	const removeCondition = ( g, c ) =>
		setGroups(
			rule.groups
				.map( ( group, gi ) =>
					gi === g ? group.filter( ( x, ci ) => ci !== c ) : group
				)
				.filter( ( group ) => group.length )
		);

	return (
		<div className="ccfo-editor">
			<Flex align="flex-start" wrap className="ccfo-editor__head">
				<FlexBlock>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Rule name',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ rule.title }
						onChange={ ( title ) => set( { title } ) }
					/>
				</FlexBlock>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						max={ 1000 }
						label={ __(
							'Priority',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						help={ __(
							'Lower number wins conflicts.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ rule.priority }
						onChange={ ( v ) =>
							set( { priority: parseInt( v || '0', 10 ) } )
						}
					/>
				</FlexItem>
				<FlexItem>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Enabled',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						checked={ rule.enabled }
						onChange={ ( enabled ) => set( { enabled } ) }
					/>
				</FlexItem>
			</Flex>

			<Flex align="flex-start" wrap className="ccfo-editor__target">
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Applies to',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ rule.target.type }
						options={ Object.keys( definitions.actions ).map(
							( t ) => ( {
								value: t,
								label: TARGET_LABELS[ t ] || t,
							} )
						) }
						onChange={ ( type ) =>
							set( {
								target: {
									type,
									key: definitions.fixedKeys[ type ] || '',
								},
								action: definitions.actions[ type ][ 0 ],
								args: {},
								groups: [],
							} )
						}
					/>
				</FlexItem>
				{ ! definitions.fixedKeys[ rule.target.type ] && (
					<FlexBlock>
						<ComboboxControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ TARGET_LABELS[ rule.target.type ] }
							value={ rule.target.key }
							options={ keyOptions }
							onChange={ ( key ) =>
								set( {
									target: { ...rule.target, key: key || '' },
								} )
							}
						/>
					</FlexBlock>
				) }
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Action',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
						value={ rule.action }
						options={ actions.map( ( a ) => ( {
							value: a,
							label: ACTION_LABELS[ a ] || a,
						} ) ) }
						onChange={ ( action ) => {
							let args = {};
							if ( action === 'set_tax_class' ) {
								// eslint-disable-next-line camelcase
								args = { tax_class: '' };
							} else if ( ORDER_ACTIONS.includes( action ) ) {
								args = { message: rule.args?.message ?? '' };
							}
							set( { action, args } );
						} }
					/>
				</FlexItem>
				{ rule.action === 'set_tax_class' && (
					<FlexItem>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Tax class',
								'conditional-checkout-fields-and-options-for-woocommerce'
							) }
							value={ rule.args?.tax_class ?? '' }
							options={ catalog.taxClasses.map( ( c ) => ( {
								value: c.id,
								label: c.label,
							} ) ) }
							onChange={ ( taxClass ) =>
								// eslint-disable-next-line camelcase
								set( { args: { tax_class: taxClass } } )
							}
						/>
					</FlexItem>
				) }
			</Flex>

			{ ORDER_ACTIONS.includes( rule.action ) && (
				<TextareaControl
					__nextHasNoMarginBottom
					className="ccfo-editor__message"
					label={ __(
						'Message shown to customers',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
					help={ sprintf(
						/* translators: %s: list of placeholders */ __(
							'Leave empty to use the default message from the Settings tab. Placeholders: %s. {min} and {remaining} come from a "less than" condition (e.g. order total < 50000), {max} and {excess} from a "greater than" condition, {current} is the current value.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						),
						( definitions.placeholders || [] ).join( ' ' )
					) }
					value={ rule.args?.message ?? '' }
					onChange={ ( message ) =>
						set( { args: { ...rule.args, message } } )
					}
				/>
			) }

			<div className="ccfo-groups">
				{ rule.groups.length === 0 && (
					<p className="ccfo-muted">
						{ __(
							'No conditions: the rule always applies.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
					</p>
				) }
				{ rule.groups.map( ( group, g ) => (
					<div key={ g }>
						{ g > 0 && (
							<div className="ccfo-or">
								{ __(
									'OR',
									'conditional-checkout-fields-and-options-for-woocommerce'
								) }
							</div>
						) }
						<div className="ccfo-group">
							<div className="ccfo-group__title">
								{ __(
									'All of these are true:',
									'conditional-checkout-fields-and-options-for-woocommerce'
								) }
							</div>
							{ group.map( ( condition, c ) => (
								<ConditionRow
									key={ c }
									condition={ condition }
									rule={ rule }
									definitions={ definitions }
									catalog={ catalog }
									onChange={ ( next ) =>
										updateCondition( g, c, next )
									}
									onRemove={ () => removeCondition( g, c ) }
								/>
							) ) }
							<Button
								variant="link"
								disabled={ ! defaultSource }
								onClick={ () =>
									setGroups(
										rule.groups.map( ( x, gi ) =>
											gi === g
												? [
														...x,
														newCondition(
															defaultSource,
															definitions
														),
												  ]
												: x
										)
									)
								}
							>
								{ __(
									'+ AND condition',
									'conditional-checkout-fields-and-options-for-woocommerce'
								) }
							</Button>
						</div>
					</div>
				) ) }
				<Button
					variant="secondary"
					disabled={ ! defaultSource }
					onClick={ () =>
						setGroups( [
							...rule.groups,
							[ newCondition( defaultSource, definitions ) ],
						] )
					}
				>
					{ rule.groups.length
						? __(
								'+ OR group',
								'conditional-checkout-fields-and-options-for-woocommerce'
						  )
						: __(
								'+ Add condition',
								'conditional-checkout-fields-and-options-for-woocommerce'
						  ) }
				</Button>
			</div>
		</div>
	);
}
