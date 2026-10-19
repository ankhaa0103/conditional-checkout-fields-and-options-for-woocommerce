/**
 * E2E helpers: WP-CLI access to the test site and checkout form actions.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '../..' );
const SITE = process.env.CCFO_WP_PATH || 'C:/wamp64/www/brand.mn';

// Private OPcache file cache for WP-CLI: not shared with other tools, and safe to wipe.
const OPCACHE_DIR = path.join( ROOT, '.cache', 'opcache-cli' );

/**
 * Whether a WP-CLI call can skip loading plugins and themes.
 *
 * Reading any option, and writing our own `ccfo_*` options or user meta, needs no
 * plugin code. Writing WooCommerce options keeps plugins loaded so their update
 * hooks still run.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {boolean} Light call.
 */
function isLight( args ) {
	const [ command, sub, name = '' ] = args;
	if ( command === 'option' ) {
		return sub === 'get' || String( name ).startsWith( 'ccfo_' );
	}
	return command === 'user' && sub === 'meta';
}

function runPhp( args ) {
	return execFileSync(
		'php',
		[
			`-dopcache.file_cache=${ OPCACHE_DIR }`,
			path.join( ROOT, 'tools/wp-cli.phar' ),
			`--path=${ SITE }`,
			...( isLight( args ) ? [ '--skip-plugins', '--skip-themes' ] : [] ),
			...args,
		],
		{
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			env: { ...process.env, XDEBUG_MODE: 'off' },
		}
	).trim();
}

/**
 * Run WP-CLI. When PHP crashes (e.g. a damaged OPcache file) or cannot use the
 * cache folder (e.g. it was deleted meanwhile), the private cache is recreated and
 * the call is retried once.
 *
 * @param {...string} args WP-CLI arguments.
 * @return {string} Output.
 */
function wp( ...args ) {
	fs.mkdirSync( OPCACHE_DIR, { recursive: true } );
	try {
		return runPhp( args );
	} catch ( error ) {
		const crashed =
			error.status === 139 ||
			error.status === 3221225477 ||
			error.signal === 'SIGSEGV';
		const cacheProblem = /opcache\.file_cache/.test(
			String( error.stderr )
		);
		if ( ! crashed && ! cacheProblem ) {
			throw error;
		}
		fs.rmSync( OPCACHE_DIR, { recursive: true, force: true } );
		fs.mkdirSync( OPCACHE_DIR, { recursive: true } );
		return runPhp( args );
	}
}

function wpEval( code ) {
	return wp( 'eval', code );
}

/**
 * Delete an option; a missing option is fine.
 *
 * @param {string} name Option name.
 */
function deleteOption( name ) {
	try {
		wp( 'option', 'delete', name );
	} catch ( e ) {
		// WP-CLI exits with an error when the option does not exist.
	}
}

let adminCookies = null;

/**
 * Give a page the admin session saved by global-setup.js.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function useAdmin( page ) {
	if ( ! adminCookies ) {
		adminCookies = JSON.parse(
			fs.readFileSync(
				path.join( __dirname, '.auth', 'admin.json' ),
				'utf8'
			)
		).cookies;
	}
	await page.context().addCookies( adminCookies );
}

const fixtures = () =>
	JSON.parse( wp( 'option', 'get', 'ccfo_test_fixtures', '--format=json' ) );

let revision = 1000;

/**
 * Replace the stored rules.
 *
 * @param {Object[]} rules Rules.
 */
function setRules( rules ) {
	// Always a new value: WP-CLI fails when an option is updated to the same value.
	revision = Math.max( revision + 1, Date.now() );
	const json = JSON.stringify( { schema: 1, revision, rules } );
	wp(
		'option',
		'update',
		'ccfo_rules',
		json,
		'--format=json',
		'--autoload=no'
	);
}

function rule( id, type, key, action, groups = [], extra = {} ) {
	return {
		id,
		title: id,
		enabled: true,
		priority: 10,
		target: { type, key },
		action,
		groups,
		...extra,
	};
}

/**
 * Empty the cart and add products by fixture key.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Object}                          fx    Fixtures.
 * @param {Object<string, number>}          items Fixture key → quantity.
 */
async function fillCart( page, fx, items ) {
	// Store API instead of page loads; page.request shares the page's cookies.
	await page.context().clearCookies();
	const cart = await page.request.get( '/wp-json/wc/store/v1/cart' );
	const nonce = cart.headers().nonce;
	for ( const [ key, qty ] of Object.entries( items ) ) {
		const res = await page.request.post(
			'/wp-json/wc/store/v1/cart/add-item',
			{
				headers: { Nonce: nonce },
				data: { id: Number( fx.products[ key ] ), quantity: qty },
			}
		);
		if ( ! res.ok() ) {
			throw new Error(
				`add-item failed: ${ res.status() } ${ await res.text() }`
			);
		}
	}
}

async function waitForCheckoutIdle( page ) {
	await page.waitForFunction(
		() => ! document.querySelector( '.blockUI.blockOverlay' ),
		null,
		{ timeout: 30000 }
	);
}

/**
 * Open the classic checkout and wait for the first order review update.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openClassicCheckout( page ) {
	// The checkout is ready after its first order review refresh; images and fonts
	// do not matter, so do not wait for the full page load.
	const review = page.waitForResponse( ( r ) =>
		r.url().includes( 'wc-ajax=update_order_review' )
	);
	await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( 'form.checkout' );
	await review;
	await waitForCheckoutIdle( page );
}

async function selectCountry( page, prefix, country ) {
	await page.selectOption( `#${ prefix }_country`, country );
	await waitForCheckoutIdle( page );
}

/**
 * Fill the required classic billing fields.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function fillClassicBilling( page ) {
	const values = {
		billing_first_name: 'Test',
		billing_last_name: 'Buyer',
		billing_address_1: 'Peace Avenue 1',
		billing_city: 'Ulaanbaatar',
		billing_postcode: '14200',
		billing_phone: '99112233',
		billing_email: 'buyer@example.test',
	};
	for ( const [ id, value ] of Object.entries( values ) ) {
		const input = page.locator( `#${ id }` );
		if ( ( await input.count() ) && ( await input.isVisible() ) ) {
			await input.fill( value );
		}
	}
	const state = page.locator( 'input#billing_state' );
	if ( ( await state.count() ) && ( await state.isVisible() ) ) {
		await state.fill( 'UB' );
	}
}

async function placeClassicOrder( page ) {
	await waitForCheckoutIdle( page );
	const terms = page.locator( '#terms' );
	if ( ( await terms.count() ) && ( await terms.isVisible() ) ) {
		await terms.check();
	}
	await page.locator( '#place_order' ).click();
	await page.waitForFunction(
		() =>
			document.querySelector(
				'.woocommerce-error, .woocommerce-NoticeGroup-checkout, .wc-block-components-notice-banner.is-error'
			) || /order-received/.test( window.location.href ),
		null,
		{ timeout: 45000 }
	);
}

function orderMeta( orderId, key ) {
	return wpEval(
		`$o = wc_get_order( ${ Number(
			orderId
		) } ); echo wp_json_encode( $o ? $o->get_meta( '${ key }' ) : null );`
	);
}

function orderIdFromUrl( url ) {
	const match = /order-received\/(\d+)/.exec( url );
	return match ? Number( match[ 1 ] ) : null;
}

/**
 * Log in through wp-login.php.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          user ccfo_admin|ccfo_customer|ccfo_wholesale.
 */
async function login( page, user = 'ccfo_admin' ) {
	// Wait for 'load': wp-login.php clears and focuses the username field shortly after it.
	await page.goto( '/wp-login.php', { waitUntil: 'load' } );
	await page.waitForTimeout( 300 );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', 'ccfo-test-pass-123' );
	// Wait until the login request has set the cookies and left wp-login.php.
	await Promise.all( [
		page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login.php' ), {
			waitUntil: 'domcontentloaded',
		} ),
		page.click( '#wp-submit' ),
	] );
}

/**
 * Replace plugin settings (missing keys fall back to defaults).
 *
 * @param {Object} settings Settings.
 */
function setSettings( settings ) {
	wp(
		'option',
		'update',
		'ccfo_settings',
		JSON.stringify( settings ),
		'--format=json',
		'--autoload=no'
	);
}

/**
 * Turn taxes on with a 10% MN rate; returns a function that restores the store.
 *
 * @param {Object} options Tax options to set, e.g. { woocommerce_tax_display_cart: 'incl' }.
 * @return {Function} Restore.
 */
function withTaxes( options = {} ) {
	const names = [
		'woocommerce_calc_taxes',
		'woocommerce_tax_display_cart',
		'woocommerce_prices_include_tax',
	];
	const before = JSON.parse(
		wpEval(
			`echo wp_json_encode( array_map( 'get_option', ${ JSON.stringify(
				names
			).replace( /"/g, "'" ) } ) );`
		)
	);
	const all = {
		woocommerce_calc_taxes: 'yes',
		woocommerce_prices_include_tax: 'no',
		...options,
	};
	for ( const [ name, value ] of Object.entries( all ) ) {
		wp( 'option', 'update', name, value );
	}
	const rateId = wpEval(
		"echo WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'MN', 'tax_rate' => '10.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );"
	);
	return () => {
		wpEval( `WC_Tax::_delete_tax_rate( ${ Number( rateId ) } );` );
		names.forEach( ( name, i ) => {
			if ( before[ i ] === false ) {
				deleteOption( name );
			} else {
				wp( 'option', 'update', name, String( before[ i ] ) );
			}
		} );
	};
}

/**
 * Money amount shown in an element, as a number.
 *
 * @param {import('@playwright/test').Locator} locator Element.
 * @return {Promise<number>} Amount.
 */
async function amount( locator ) {
	const text = ( await locator.first().innerText() ).replace(
		/\([^)]*\)/g,
		''
	);
	return Number( text.replace( /[^0-9.]/g, '' ) );
}

/**
 * Checkout Block page of the fixtures, ready for input.
 *
 * The block page is not the WooCommerce checkout page: on this site the Woodmart
 * theme's checkout layout replaces the Checkout Block on that page.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openBlockCheckout( page ) {
	await page.goto( '/ccfo-block-checkout/', {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForSelector( '.wc-block-checkout__form', {
		timeout: 30000,
	} );
	await page.waitForFunction(
		() =>
			! document.querySelector(
				'.wc-block-components-loading-mask, .is-loading'
			),
		null,
		{ timeout: 30000 }
	);
}

async function fillBlockAddress( page, { waitForSave = true } = {} ) {
	// WooCommerce sends address input a few seconds after the last change. On this
	// slow test site an order placed before that can stay "processing" (also without
	// any rules), so wait until the address has been saved — or 20 s at most.
	const saved = page
		.waitForResponse(
			( r ) =>
				/update-customer/.test( r.request().postData() || '' ) &&
				/Peace Avenue 2/.test( r.request().postData() || '' ),
			{ timeout: 20000 }
		)
		.catch( () => null );
	await page.fill( '#email', 'block-buyer@example.test' );
	const values = {
		first_name: 'Block',
		last_name: 'Buyer',
		address_1: 'Peace Avenue 2',
		city: 'Ulaanbaatar',
		postcode: '14200',
		phone: '99112233',
		state: 'UB',
	};
	for ( const [ key, value ] of Object.entries( values ) ) {
		for ( const prefix of [ 'shipping', 'billing' ] ) {
			const input = page.locator( `#${ prefix }-${ key }` );
			if ( ( await input.count() ) && ( await input.isVisible() ) ) {
				await input.fill( value );
			}
		}
	}
	if ( waitForSave ) {
		await saved;
	}
}

async function placeBlockOrder( page ) {
	// A payment method change sends a draft update (PUT /checkout) and address input
	// is sent after a pause; on this slow test server the order can hang in
	// "processing" when it is placed while those requests are still running.
	await page
		.waitForLoadState( 'networkidle', { timeout: 20000 } )
		.catch( () => {} );
	await page.waitForFunction(
		() => {
			const data = window.wp?.data;
			const checkout = data?.select( 'wc/store/checkout' );
			const cart = data?.select( 'wc/store/cart' );
			return (
				checkout?.isIdle?.() !== false &&
				! checkout?.isCalculating?.() &&
				! cart?.isCustomerDataUpdating?.()
			);
		},
		null,
		{ timeout: 20000 }
	);
	await page
		.locator( '.wc-block-components-checkout-place-order-button' )
		.click();
	await page.waitForFunction(
		() =>
			document.querySelector(
				'.wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error'
			) || /order-received/.test( window.location.href ),
		null,
		{ timeout: 45000 }
	);
}

module.exports = {
	openBlockCheckout,
	fillBlockAddress,
	placeBlockOrder,
	useAdmin,
	deleteOption,
	login,
	setSettings,
	withTaxes,
	amount,
	wp,
	wpEval,
	fixtures,
	setRules,
	rule,
	fillCart,
	openClassicCheckout,
	selectCountry,
	fillClassicBilling,
	placeClassicOrder,
	waitForCheckoutIdle,
	orderMeta,
	orderIdFromUrl,
};
