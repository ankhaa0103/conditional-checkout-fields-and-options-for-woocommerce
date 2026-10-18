/**
 * Checkout Block: tax note class and payment method sync.
 *
 * Payment and shipping rules need no client code here: the Store API applies them
 * on the server and the block shows what the cart response contains.
 */
import { select, subscribe } from '@wordpress/data';
// WooCommerce packages are externals provided by WooCommerce at runtime.
// eslint-disable-next-line import/no-unresolved
import { getSetting } from '@woocommerce/settings';
// eslint-disable-next-line import/no-unresolved
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';

const settings = getSetting( 'ccfo_data', {} );

let lastHidden = null;
let lastMethod = null;

subscribe( () => {
	const cartStore = select( 'wc/store/cart' );
	const cart = cartStore?.getCartData?.();
	if ( cart ) {
		const hidden = !! cart.extensions?.ccfo?.taxNoteHidden;
		if ( hidden !== lastHidden ) {
			lastHidden = hidden;
			document.body.classList.toggle( 'ccfo-hide-tax-note', hidden );
		}
	}

	if ( settings.syncPaymentMethod ) {
		const method = select( 'wc/store/payment' )?.getActivePaymentMethod?.();
		if ( method && method !== lastMethod ) {
			lastMethod = method;
			extensionCartUpdate( {
				namespace: 'ccfo',
				data: { payment_method: method },
			} );
		}
	}
} );
