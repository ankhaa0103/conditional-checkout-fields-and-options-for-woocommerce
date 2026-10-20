/**
 * Build: four entries, WordPress and WooCommerce packages as externals.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'assets/src/admin/index.js' ),
		classic: path.resolve( __dirname, 'assets/src/classic/index.js' ),
		blocks: path.resolve( __dirname, 'assets/src/blocks/index.js' ),
		'order-notices': path.resolve(
			__dirname,
			'assets/src/classic/order-notices.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
