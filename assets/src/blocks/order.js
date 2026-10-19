/**
 * Cart and Checkout Blocks: messages of order rules (SPEC §13).
 *
 * The server decides (`extensions.ccfo.checkoutBlocked` / `orderBlocked` in the
 * cart response) and refuses a blocked order when it is placed. This only shows
 * the messages as block notices and holds back the "Proceed to checkout" and
 * "Place order" buttons while the order is blocked.
 */

export const BUTTONS =
	'.wc-block-cart__submit-button, .wc-block-components-checkout-place-order-button';

const VALIDATION_KEY = 'ccfo-order-blocked';
const NOTICE_PREFIX = 'ccfo-order-blocked-';

/**
 * Create the guard.
 *
 * @param {Object}   deps          Dependencies.
 * @param {Function} deps.dispatch `@wordpress/data` dispatch.
 * @param {Document} deps.doc      Document.
 * @return {{update: Function, isBlocked: Function}} Guard.
 */
export function createOrderGuard( { dispatch, doc } ) {
	let shown = [];
	let shownContext = null;
	let blocked = false;

	const isCheckout = () =>
		!! doc.querySelector(
			'.wp-block-woocommerce-checkout, .wc-block-checkout'
		);

	// Only touches attributes that are wrong, so calling it on every store change is cheap.
	const markButtons = () => {
		doc.querySelectorAll( BUTTONS ).forEach( ( el ) => {
			const marked = el.getAttribute( 'aria-disabled' ) === 'true';
			if ( blocked && ! marked ) {
				el.setAttribute( 'aria-disabled', 'true' );
			} else if ( ! blocked && marked && el.dataset.ccfoMarked ) {
				el.removeAttribute( 'aria-disabled' );
			}
			if ( blocked ) {
				el.dataset.ccfoMarked = '1';
			} else {
				delete el.dataset.ccfoMarked;
			}
		} );
	};

	const guard = ( event ) => {
		if ( ! blocked || ! event.target?.closest?.( BUTTONS ) ) {
			return;
		}
		if (
			event.type === 'keydown' &&
			event.key !== 'Enter' &&
			event.key !== ' '
		) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
	};
	doc.addEventListener( 'click', guard, true );
	doc.addEventListener( 'keydown', guard, true );

	return {
		isBlocked: () => blocked,
		markButtons,

		/**
		 * Apply the extension data of a cart response.
		 *
		 * @param {Object|undefined} data   `extensions.ccfo`.
		 * @param {string}           method Payment method selected in the Checkout Block.
		 */
		update( data, method = '' ) {
			const checkout = isCheckout();
			const byMethod = data?.orderBlockedByMethod || {};
			let messages = data?.checkoutBlocked;
			if ( checkout ) {
				messages = Object.prototype.hasOwnProperty.call(
					byMethod,
					method
				)
					? byMethod[ method ]
					: data?.orderBlocked;
			}
			messages = messages || [];
			const context = checkout ? 'wc/checkout' : 'wc/cart';
			const notices = dispatch( 'core/notices' );

			if (
				context !== shownContext ||
				messages.join( '\n' ) !== shown.join( '\n' )
			) {
				shown.forEach( ( m, i ) =>
					notices?.removeNotice?.(
						NOTICE_PREFIX + i,
						shownContext || context
					)
				);
				messages.forEach( ( message, i ) =>
					notices?.createNotice?.( 'error', message, {
						id: NOTICE_PREFIX + i,
						context,
						isDismissible: false,
					} )
				);
				shown = [ ...messages ];
				shownContext = context;

				// The Checkout Block does not submit while a validation error exists.
				const validation = dispatch( 'wc/store/validation' );
				if ( checkout && messages.length ) {
					validation?.setValidationErrors?.( {
						[ VALIDATION_KEY ]: {
							message: messages.join( ' ' ),
							hidden: true,
						},
					} );
				} else {
					validation?.clearValidationError?.( VALIDATION_KEY );
				}
			}

			blocked = messages.length > 0;
			doc.body.classList.toggle( 'ccfo-order-blocked', blocked );
			markButtons();
		},
	};
}
