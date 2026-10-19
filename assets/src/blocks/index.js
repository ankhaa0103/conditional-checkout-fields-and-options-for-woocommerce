/**
 * Cart and Checkout Blocks: tax note class, order rule messages and payment method sync.
 *
 * Payment and shipping rules need no client code here: the Store API applies them
 * on the server and the block shows what the cart response contains.
 */
import { dispatch, select, subscribe } from '@wordpress/data';
// WooCommerce packages are externals provided by WooCommerce at runtime.
// eslint-disable-next-line import/no-unresolved
import { getSetting } from '@woocommerce/settings';
// eslint-disable-next-line import/no-unresolved
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { createOrderGuard } from './order';
import { createPaymentSync } from './sync';

const settings = getSetting( 'ccfo_data', {} );
const guard = createOrderGuard( { dispatch, doc: document } );
const sync = settings.syncPaymentMethod
	? createPaymentSync( {
			select,
			send: ( method ) =>
				extensionCartUpdate( {
					namespace: 'ccfo',
					data: { payment_method: method },
				} ),
	  } )
	: null;

let lastHidden = null;
let lastCart = null;
let lastMethod = null;

subscribe( () => {
	const cart = select( 'wc/store/cart' )?.getCartData?.();
	const method =
		select( 'wc/store/payment' )?.getActivePaymentMethod?.() || '';

	if ( cart && ( cart !== lastCart || method !== lastMethod ) ) {
		lastCart = cart;
		lastMethod = method;
		const hidden = !! cart.extensions?.ccfo?.taxNoteHidden;
		if ( hidden !== lastHidden ) {
			lastHidden = hidden;
			document.body.classList.toggle( 'ccfo-hide-tax-note', hidden );
		}
		guard.update( cart.extensions?.ccfo, method );
	}
	// The blocks render their buttons again on many changes.
	guard.markButtons();

	sync?.check();
} );
