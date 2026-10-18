/**
 * Admin app: rules list, editor, messages, import/export.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	Spinner,
	TabPanel,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { ACTION_LABELS, TARGET_LABELS } from './labels';
import { targetKeyOptions } from './options';
import RuleEditor from './RuleEditor';

const newId = () => `r_${ Math.random().toString( 36 ).slice( 2, 10 ) }`;

function newRule() {
	return {
		id: newId(),
		title: '',
		enabled: true,
		priority: 10,
		target: { type: 'field', key: '' },
		action: 'show',
		args: {},
		groups: [],
	};
}

function targetLabel( rule, catalog ) {
	const option = targetKeyOptions( rule.target.type, catalog ).find(
		( o ) => o.value === rule.target.key
	);
	return `${ TARGET_LABELS[ rule.target.type ] }: ${
		option ? option.label : rule.target.key
	}`;
}

function errorText( error ) {
	return (
		error?.message ||
		__(
			'Something went wrong.',
			'conditional-checkout-fields-and-options-for-woocommerce'
		)
	);
}

function download( name, data ) {
	const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], {
		type: 'application/json',
	} );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = name;
	a.click();
	URL.revokeObjectURL( url );
}

function RuleCard( {
	rule,
	catalog,
	definitions,
	findings,
	errors,
	open,
	onToggleOpen,
	onChange,
	onDelete,
	onDuplicate,
} ) {
	return (
		<Card
			className={ `ccfo-rule${ rule.enabled ? '' : ' is-disabled' }` }
			size="small"
		>
			<CardHeader>
				<Flex wrap>
					<FlexBlock>
						<strong>
							{ rule.title ||
								__(
									'(untitled rule)',
									'conditional-checkout-fields-and-options-for-woocommerce'
								) }
						</strong>
						<div className="ccfo-muted">
							{ targetLabel( rule, catalog ) } ·{ ' ' }
							{ ACTION_LABELS[ rule.action ] } ·{ ' ' }
							{ sprintf(
								/* translators: %d: number of condition groups */
								_n(
									'%d condition group',
									'%d condition groups',
									rule.groups.length,
									'conditional-checkout-fields-and-options-for-woocommerce'
								),
								rule.groups.length
							) }{ ' ' }
							·{ ' ' }
							{ sprintf(
								/* translators: %d: priority */ __(
									'priority %d',
									'conditional-checkout-fields-and-options-for-woocommerce'
								),
								rule.priority
							) }{ ' ' }
							· <code>{ rule.id }</code>
						</div>
					</FlexBlock>
					<FlexItem>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Enabled',
								'conditional-checkout-fields-and-options-for-woocommerce'
							) }
							checked={ rule.enabled }
							onChange={ ( enabled ) =>
								onChange( { ...rule, enabled } )
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button variant="secondary" onClick={ onToggleOpen }>
							{ open
								? __(
										'Close',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  )
								: __(
										'Edit',
										'conditional-checkout-fields-and-options-for-woocommerce'
								  ) }
						</Button>{ ' ' }
						<Button variant="tertiary" onClick={ onDuplicate }>
							{ __(
								'Duplicate',
								'conditional-checkout-fields-and-options-for-woocommerce'
							) }
						</Button>{ ' ' }
						<Button
							variant="tertiary"
							isDestructive
							onClick={ onDelete }
						>
							{ __(
								'Delete',
								'conditional-checkout-fields-and-options-for-woocommerce'
							) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			{ ( errors.length > 0 || findings.length > 0 ) && (
				<CardBody className="ccfo-findings">
					{ errors.map( ( e, i ) => (
						<Notice
							key={ `e${ i }` }
							status="error"
							isDismissible={ false }
						>
							{ e.path ? <code>{ e.path }</code> : null }{ ' ' }
							{ e.message }
						</Notice>
					) ) }
					{ findings.map( ( f, i ) => (
						<Notice
							key={ `f${ i }` }
							status={ f.level === 'info' ? 'info' : 'warning' }
							isDismissible={ false }
						>
							{ f.message }
						</Notice>
					) ) }
				</CardBody>
			) }
			{ open && (
				<CardBody>
					<RuleEditor
						rule={ rule }
						definitions={ definitions }
						catalog={ catalog }
						onChange={ onChange }
					/>
				</CardBody>
			) }
		</Card>
	);
}

function RulesTab( { state, setState, catalog, definitions } ) {
	const [ open, setOpen ] = useState( null );
	const fileInput = useRef();

	const update = ( index, rule ) =>
		setState( ( s ) => ( {
			...s,
			rules: s.rules.map( ( r, i ) => ( i === index ? rule : r ) ),
			dirty: true,
		} ) );

	const importFile = ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}
		file.text().then( ( text ) => {
			try {
				const parsed = JSON.parse( text );
				const rules = Array.isArray( parsed ) ? parsed : parsed.rules;
				if ( ! Array.isArray( rules ) ) {
					throw new Error(
						__(
							'The file has no rules list.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						)
					);
				}
				setState( ( s ) => ( {
					...s,
					rules,
					dirty: true,
					notice: {
						status: 'info',
						text: __(
							'Rules imported. Review them and save.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						),
					},
				} ) );
			} catch ( e ) {
				setState( ( s ) => ( {
					...s,
					notice: { status: 'error', text: errorText( e ) },
				} ) );
			}
		} );
		event.target.value = '';
	};

	return (
		<>
			<Flex className="ccfo-toolbar" wrap>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ () => {
							const rule = newRule();
							setState( ( s ) => ( {
								...s,
								rules: [ ...s.rules, rule ],
								dirty: true,
							} ) );
							setOpen( rule.id );
						} }
					>
						{ __(
							'Add rule',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
					</Button>
				</FlexItem>
				<FlexBlock />
				<FlexItem>
					<Button
						variant="tertiary"
						onClick={ () =>
							download( 'checkout-rules.json', {
								schema: 1,
								rules: state.rules,
							} )
						}
					>
						{ __(
							'Export',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ () => fileInput.current?.click() }
					>
						{ __(
							'Import',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
					</Button>
					<input
						ref={ fileInput }
						type="file"
						accept="application/json,.json"
						hidden
						onChange={ importFile }
					/>
				</FlexItem>
			</Flex>

			{ state.rules.length === 0 && (
				<p className="ccfo-muted">
					{ __(
						'No rules yet. Add one to show, hide or require checkout fields, or to limit payment and shipping methods.',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
				</p>
			) }

			<div className="ccfo-rules">
				{ state.rules.map( ( rule, index ) => (
					<RuleCard
						key={ rule.id }
						rule={ rule }
						catalog={ catalog }
						definitions={ definitions }
						findings={ state.findings.filter(
							( f ) => f.rule === rule.id
						) }
						errors={ state.errors.filter(
							( e ) =>
								e.rule === index || ( e.id && e.id === rule.id )
						) }
						open={ open === rule.id }
						onToggleOpen={ () =>
							setOpen( open === rule.id ? null : rule.id )
						}
						onChange={ ( next ) => update( index, next ) }
						onDelete={ () => {
							if (
								// eslint-disable-next-line no-alert
								window.confirm(
									__(
										'Delete this rule?',
										'conditional-checkout-fields-and-options-for-woocommerce'
									)
								)
							) {
								setState( ( s ) => ( {
									...s,
									rules: s.rules.filter(
										( r, i ) => i !== index
									),
									dirty: true,
								} ) );
							}
						} }
						onDuplicate={ () => {
							const copy = {
								...JSON.parse( JSON.stringify( rule ) ),
								id: newId(),
								title: `${ rule.title } (2)`,
							};
							setState( ( s ) => ( {
								...s,
								rules: [
									...s.rules.slice( 0, index + 1 ),
									copy,
									...s.rules.slice( index + 1 ),
								],
								dirty: true,
							} ) );
							setOpen( copy.id );
						} }
					/>
				) ) }
			</div>
		</>
	);
}

function MessagesTab() {
	const [ settings, setSettings ] = useState( null );
	const [ status, setStatus ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/ccfo/v1/settings' } ).then( setSettings );
	}, [] );

	if ( ! settings ) {
		return <Spinner />;
	}

	return (
		<div className="ccfo-messages">
			<p>
				{ __(
					'Shown to customers when your rules remove every payment or every shipping method.',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
			</p>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'No payment method message',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				value={ settings.payment_blocked_message }
				onChange={ ( v ) =>
					setSettings( { ...settings, payment_blocked_message: v } )
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'No shipping method message',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				value={ settings.shipping_blocked_message }
				onChange={ ( v ) =>
					setSettings( { ...settings, shipping_blocked_message: v } )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="number"
				min={ 0 }
				max={ 30 }
				label={ __(
					'Live update delay (seconds)',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				help={ __(
					'Classic checkout: when payment, shipping or tax rules read a field such as the company name, the checkout refreshes as soon as the customer leaves the field, or after this many seconds while they are still typing. It refreshes only when the result of a rule condition changes. 0 = right away.',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
				value={ settings.live_update_delay }
				onChange={ ( v ) =>
					setSettings( { ...settings, live_update_delay: v } )
				}
			/>
			<Button
				variant="primary"
				onClick={ () =>
					apiFetch( {
						path: '/ccfo/v1/settings',
						method: 'POST',
						data: settings,
					} )
						.then( ( saved ) => {
							setSettings( saved );
							setStatus( {
								status: 'success',
								text: __(
									'Settings saved.',
									'conditional-checkout-fields-and-options-for-woocommerce'
								),
							} );
						} )
						.catch( ( e ) =>
							setStatus( {
								status: 'error',
								text: errorText( e ),
							} )
						)
				}
			>
				{ __(
					'Save settings',
					'conditional-checkout-fields-and-options-for-woocommerce'
				) }
			</Button>
			{ status && (
				<Notice
					status={ status.status }
					onRemove={ () => setStatus( null ) }
				>
					{ status.text }
				</Notice>
			) }
		</div>
	);
}

export default function App() {
	const [ state, setState ] = useState( {
		loading: true,
		rules: [],
		revision: 0,
		findings: [],
		errors: [],
		dirty: false,
		saving: false,
		notice: null,
	} );
	const [ catalog, setCatalog ] = useState( null );
	const [ definitions, setDefinitions ] = useState( null );
	const [ blocked, setBlocked ] = useState( [] );
	const [ loadError, setLoadError ] = useState( null );

	const load = useCallback( () => {
		Promise.all( [
			apiFetch( { path: '/ccfo/v1/rules' } ),
			apiFetch( { path: '/ccfo/v1/catalog' } ),
			apiFetch( { path: '/ccfo/v1/blocked' } ),
		] )
			.then( ( [ rules, cat, log ] ) => {
				setDefinitions( rules.definitions );
				setCatalog( cat );
				setBlocked( log );
				setState( ( s ) => ( {
					...s,
					loading: false,
					rules: rules.rules,
					revision: rules.revision,
					findings: rules.findings,
					errors: rules.invalid || [],
					dirty: false,
				} ) );
			} )
			.catch( ( e ) => setLoadError( errorText( e ) ) );
	}, [] );

	useEffect( load, [ load ] );

	// Live checks while editing.
	useEffect( () => {
		if ( state.loading || ! state.dirty ) {
			return;
		}
		const timer = setTimeout( () => {
			apiFetch( {
				path: '/ccfo/v1/lint',
				method: 'POST',
				data: { rules: state.rules },
			} )
				.then( ( res ) =>
					setState( ( s ) => ( {
						...s,
						findings: res.findings,
						errors: res.errors,
					} ) )
				)
				.catch( () => {} );
		}, 600 );
		return () => clearTimeout( timer );
	}, [ state.rules, state.dirty, state.loading ] );

	// Warn before leaving with unsaved changes.
	useEffect( () => {
		const handler = ( e ) => {
			if ( state.dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ state.dirty ] );

	const save = () => {
		setState( ( s ) => ( { ...s, saving: true, notice: null } ) );
		apiFetch( {
			path: '/ccfo/v1/rules',
			method: 'PUT',
			data: { revision: state.revision, rules: state.rules },
		} )
			.then( ( res ) =>
				setState( ( s ) => ( {
					...s,
					saving: false,
					dirty: false,
					rules: res.rules,
					revision: res.revision,
					findings: res.findings,
					errors: [],
					notice: {
						status: 'success',
						text: __(
							'Rules saved.',
							'conditional-checkout-fields-and-options-for-woocommerce'
						),
					},
				} ) )
			)
			.catch( ( e ) =>
				setState( ( s ) => ( {
					...s,
					saving: false,
					errors: e?.data?.errors || s.errors,
					notice: {
						status: 'error',
						text: errorText( e ),
						reload: e?.code === 'ccfo_revision_conflict',
					},
				} ) )
			);
	};

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}
	if ( state.loading || ! catalog || ! definitions ) {
		return <Spinner />;
	}

	const store = catalog.store;

	return (
		<div className="ccfo-app">
			{ blocked.length > 0 && (
				<Notice
					status="warning"
					onRemove={ () =>
						apiFetch( {
							path: '/ccfo/v1/blocked',
							method: 'DELETE',
						} ).then( () => setBlocked( [] ) )
					}
				>
					{ blocked.slice( 0, 5 ).map( ( b, i ) => (
						<div key={ i }>
							{ sprintf(
								/* translators: 1: payment|shipping, 2: rule ids, 3: date */
								__(
									'Every %1$s method was removed by rules %2$s on %3$s.',
									'conditional-checkout-fields-and-options-for-woocommerce'
								),
								b.type,
								b.rules.join( ', ' ),
								new Date( b.time * 1000 ).toLocaleString()
							) }
						</div>
					) ) }
				</Notice>
			) }

			<Notice status="info" isDismissible={ false }>
				{ store.block_checkout
					? __(
							'Your checkout page uses the Checkout Block. Payment, shipping and tax rules work fully; field rules are limited — see the notes on each rule.',
							'conditional-checkout-fields-and-options-for-woocommerce'
					  )
					: __(
							'Your checkout page uses the classic checkout. All rule types are supported.',
							'conditional-checkout-fields-and-options-for-woocommerce'
					  ) }{ ' ' }
				{ ! store.tax_enabled &&
					__(
						'Taxes are disabled, so tax rules have no effect.',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
			</Notice>

			<TabPanel
				className="ccfo-tabs"
				tabs={ [
					{
						name: 'rules',
						title: __(
							'Rules',
							'conditional-checkout-fields-and-options-for-woocommerce'
						),
					},
					{
						name: 'messages',
						title: __(
							'Settings',
							'conditional-checkout-fields-and-options-for-woocommerce'
						),
					},
				] }
			>
				{ ( tab ) =>
					tab.name === 'rules' ? (
						<RulesTab
							state={ state }
							setState={ setState }
							catalog={ catalog }
							definitions={ definitions }
						/>
					) : (
						<MessagesTab />
					)
				}
			</TabPanel>

			<div className="ccfo-savebar">
				{ state.notice && (
					<Notice
						status={ state.notice.status }
						onRemove={ () =>
							setState( ( s ) => ( { ...s, notice: null } ) )
						}
					>
						{ state.notice.text }{ ' ' }
						{ state.notice.reload && (
							<Button variant="link" onClick={ load }>
								{ __(
									'Reload rules',
									'conditional-checkout-fields-and-options-for-woocommerce'
								) }
							</Button>
						) }
					</Notice>
				) }
				<Button
					variant="primary"
					isBusy={ state.saving }
					disabled={ ! state.dirty || state.saving }
					onClick={ save }
				>
					{ __(
						'Save rules',
						'conditional-checkout-fields-and-options-for-woocommerce'
					) }
				</Button>
				{ state.dirty && (
					<span className="ccfo-muted">
						{ ' ' }
						{ __(
							'Unsaved changes',
							'conditional-checkout-fields-and-options-for-woocommerce'
						) }
					</span>
				) }
			</div>
		</div>
	);
}
