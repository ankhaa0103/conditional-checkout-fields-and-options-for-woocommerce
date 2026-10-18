const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	rootDir: '../../',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	testEnvironment: 'jsdom',
};
