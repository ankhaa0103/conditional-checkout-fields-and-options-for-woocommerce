/**
 * Checkout Block: sends the selected payment method to the server for tax and
 * field rules that read it (`extensionCartUpdate`).
 *
 * - Nothing is sent while the method equals the one the server already uses
 *   (`extensions.ccfo.paymentMethod`; with nothing chosen yet the server assumes
 *   the first available method, as the block does). So nothing is sent while the
 *   page loads and the customer starts typing.
 * - The cart response of `extensionCartUpdate` replaces address input that
 *   WooCommerce has not sent to the server yet; WooCommerce sends it a few seconds
 *   after the last change. So the method is sent only after `quietMs` without
 *   address changes and while no address update runs — at the latest after
 *   `maxWaitMs`, so it is never lost.
 */

/**
 * Create the sync.
 *
 * @param {Object}   deps           Dependencies.
 * @param {Function} deps.select    `@wordpress/data` select.
 * @param {Function} deps.send      Sends the method (extensionCartUpdate).
 * @param {Object}   deps.timers    setTimeout/clearTimeout.
 * @param {Function} deps.now       Clock in ms.
 * @param {number}   deps.quietMs   Time without address changes before sending.
 * @param {number}   deps.retryMs   Check interval while waiting.
 * @param {number}   deps.maxWaitMs Longest wait.
 * @return {{check: Function}} Sync.
 */
export function createPaymentSync( {
	select,
	send,
	timers = window,
	now = Date.now,
	quietMs = 3500,
	retryMs = 300,
	maxWaitMs = 10000,
} ) {
	let sent = null;
	let timer = null;
	let waitingSince = null;
	let lastCustomer = null;
	let changedAt = -Infinity;

	const observe = () => {
		const customer = select( 'wc/store/cart' )?.getCustomerData?.();
		if ( customer && customer !== lastCustomer ) {
			if ( lastCustomer !== null ) {
				changedAt = now();
			}
			lastCustomer = customer;
		}
	};

	const run = () => {
		timer = null;
		const cartStore = select( 'wc/store/cart' );
		const method = select( 'wc/store/payment' )?.getActivePaymentMethod?.();
		const server =
			cartStore?.getCartData?.()?.extensions?.ccfo?.paymentMethod;
		if ( ! method || method === sent || method === server ) {
			if ( method && method === server ) {
				sent = method;
			}
			waitingSince = null;
			return;
		}

		if ( waitingSince === null ) {
			waitingSince = now();
		}
		const busy =
			cartStore?.isCustomerDataUpdating?.() ||
			now() - changedAt < quietMs;
		if ( busy && now() - waitingSince < maxWaitMs ) {
			timer = timers.setTimeout( run, retryMs );
			return;
		}

		sent = method;
		waitingSince = null;
		send( method );
	};

	return {
		/**
		 * Call on every store change.
		 */
		check() {
			observe();
			if ( timer === null ) {
				run();
			}
		},
	};
}
