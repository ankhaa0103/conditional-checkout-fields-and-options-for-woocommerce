/**
 * Classic cart and checkout: keeps the order rule messages at the top of the page.
 *
 * WooCommerce refreshes only parts of these pages: the cart totals and the
 * checkout payment section. Those parts carry the current messages as data
 * (`.ccfo-order-notices-data`); this copies them into the container printed after
 * the WooCommerce notices (`.ccfo-order-notices`). WooCommerce's cart script also
 * removes and re-adds `.woocommerce-info` notices when the cart changes, so stray
 * copies are removed first.
 */

const NOTICE_CLASS = 'woocommerce-info ccfo-order-blocked ccfo-order-notice';

/**
 * Create the sync.
 *
 * @param {Object}   deps        Dependencies.
 * @param {Function} deps.$      jQuery.
 * @param {Document} deps.root   Document.
 * @param {Function} deps.scroll Scrolls to an element (WooCommerce's $.scroll_to_notices).
 * @return {{sync: Function}} Sync.
 */
export function createOrderNotices( { $, root, scroll } ) {
	let last = null;

	const read = () => {
		const $data = $( '.ccfo-order-notices-data', root ).last();
		if ( ! $data.length ) {
			return null;
		}
		try {
			const messages = JSON.parse(
				$data.attr( 'data-messages' ) || '[]'
			);
			return Array.isArray( messages ) ? messages.map( String ) : [];
		} catch ( e ) {
			return null;
		}
	};

	const container = () => {
		let $box = $( '.ccfo-order-notices', root ).first();
		if ( $box.length ) {
			return $box;
		}
		$box = $( '<div class="ccfo-order-notices" aria-live="polite"></div>' );
		const $wrapper = $( '.woocommerce-notices-wrapper', root ).first();
		if ( $wrapper.length ) {
			$wrapper.after( $box );
		} else {
			$( 'form.checkout, .woocommerce-cart-form', root )
				.first()
				.before( $box );
		}
		return $box;
	};

	const inView = ( el ) => {
		const rect = el.getBoundingClientRect();
		const height =
			root.defaultView?.innerHeight || root.documentElement.clientHeight;
		return rect.top >= 0 && rect.top < height;
	};

	return {
		/**
		 * Copy the current messages to the top of the page.
		 *
		 * @param {boolean} afterUpdate Called after a WooCommerce refresh: scroll to
		 *                              new messages that are out of view.
		 */
		sync( afterUpdate = false ) {
			const messages = read();
			if ( messages === null ) {
				return;
			}
			const $box = container();
			$( '.ccfo-order-notice', root ).remove();
			messages.forEach( ( message ) =>
				$box.append(
					$( '<div role="status"></div>' )
						.addClass( NOTICE_CLASS )
						.text( message )
				)
			);

			const key = JSON.stringify( messages );
			if (
				afterUpdate &&
				messages.length &&
				key !== last &&
				$box.get( 0 ) &&
				! inView( $box.get( 0 ) )
			) {
				scroll( $box );
			}
			last = key;
		},
	};
}
