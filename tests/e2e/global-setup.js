/**
 * Logs in as the test admin once and saves the session for all tests.
 *
 * Tests add these cookies to the page that needs admin access (h.useAdmin), so
 * shopper pages opened in the same test stay guests.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( '@playwright/test' );

const STATE = path.join( __dirname, '.auth', 'admin.json' );

module.exports = async ( config ) => {
	const { baseURL, ignoreHTTPSErrors } = config.projects[ 0 ].use;
	fs.mkdirSync( path.dirname( STATE ), { recursive: true } );

	const browser = await chromium.launch();
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors } );
	const page = await context.newPage();
	// Wait for 'load': wp-login.php clears and focuses the username field shortly after it.
	await page.goto( '/wp-login.php', { waitUntil: 'load' } );
	await page.waitForTimeout( 300 );
	await page.fill( '#user_login', 'ccfo_admin' );
	await page.fill( '#user_pass', 'ccfo-test-pass-123' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/, { waitUntil: 'domcontentloaded' } );
	await context.storageState( { path: STATE } );
	await browser.close();
};

module.exports.STATE = STATE;
