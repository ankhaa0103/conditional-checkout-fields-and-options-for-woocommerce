/**
 * E2E against the local test site. Run tools/fixtures.php setup first.
 */
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '**/*.spec.js',
	globalSetup: require.resolve( './global-setup' ),
	timeout: 90000,
	expect: { timeout: 15000 },
	workers: 1,
	fullyParallel: false,
	retries: 0,
	reporter: [ [ 'list' ] ],
	outputDir: '../../test-results',
	use: {
		baseURL: process.env.CCFO_BASE_URL || 'https://brand.mn',
		ignoreHTTPSErrors: true,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		locale: 'en-US',
	},
} );
