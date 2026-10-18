/**
 * Renders the WordPress.org icon and banner PNGs from .wordpress-org/icon.svg.
 *
 *   node tools/wporg-assets.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( '@playwright/test' );

const DIR = path.join( __dirname, '..', '.wordpress-org' );
const ICON = fs.readFileSync( path.join( DIR, 'icon.svg' ), 'utf8' );

const banner = `<!doctype html><html><head><style>
	html, body { margin: 0; }
	.b { width: 772px; height: 250px; box-sizing: border-box; padding: 0 48px; display: flex; align-items: center; gap: 40px;
		background: linear-gradient( 120deg, #1e6f8f, #16425b ); color: #fff; font-family: "Segoe UI", Arial, sans-serif; overflow: hidden; }
	.b svg { width: 170px; height: 170px; flex: none; filter: drop-shadow( 0 8px 18px rgba( 0, 0, 0, .25 ) ); }
	h1 { margin: 0 0 12px; font-size: 34px; line-height: 1.15; font-weight: 700; }
	p { margin: 0; font-size: 18px; line-height: 1.4; opacity: .9; }
	.tags { margin-top: 16px; display: flex; gap: 8px; }
	.tags span { background: rgba( 255, 255, 255, .16 ); border-radius: 999px; padding: 4px 12px; font-size: 14px; }
</style></head><body><div class="b">${ ICON }<div>
	<h1>Conditional Checkout<br>Fields and Options</h1>
	<p>Rules for the checkout you already have.</p>
	<div class="tags"><span>Fields</span><span>Payment</span><span>Shipping</span><span>Tax</span></div>
</div></div></body></html>`;

( async () => {
	const browser = await chromium.launch();
	try {
		for ( const scale of [ 1, 2 ] ) {
			const page = await browser.newPage( {
				viewport: { width: 772, height: 250 },
				deviceScaleFactor: scale,
			} );
			await page.setContent( banner );
			const name =
				1 === scale ? 'banner-772x250.png' : 'banner-1544x500.png';
			await page
				.locator( '.b' )
				.screenshot( { path: path.join( DIR, name ) } );
			await page.close();
		}
		for ( const size of [ 128, 256 ] ) {
			const page = await browser.newPage( {
				viewport: { width: size, height: size },
			} );
			await page.setContent(
				`<style>html,body{margin:0;background:transparent}svg{display:block;width:${ size }px;height:${ size }px}</style>${ ICON }`
			);
			await page.locator( 'svg' ).screenshot( {
				path: path.join( DIR, `icon-${ size }x${ size }.png` ),
				omitBackground: true,
			} );
			await page.close();
		}
		// eslint-disable-next-line no-console
		console.log( 'Assets written to', DIR );
	} finally {
		await browser.close();
	}
} )();
