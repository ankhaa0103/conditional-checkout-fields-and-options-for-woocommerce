/**
 * Decides when the classic checkout must be refreshed on the server.
 *
 * Payment, shipping and tax rules run on the server, which only sees new form
 * values when WooCommerce refreshes the checkout (`update_order_review`), and
 * WooCommerce does that for address fields only. This compares the results of the
 * rules' form conditions with those the server last saw and asks for a refresh only
 * when a result changed — not on every keystroke.
 */

/**
 * Create a refresher.
 *
 * @param {Object}   deps            Dependencies.
 * @param {Object[]} deps.conditions Conditions of server-side rules that read form values.
 * @param {number}   deps.delay      Longest wait in ms while the customer is typing.
 * @param {Function} deps.evaluate   Condition → boolean, from current form values.
 * @param {Function} deps.refresh    Triggers the refresh.
 * @param {Object}   deps.timers     setTimeout/clearTimeout (for tests).
 * @return {{changed: Function, sent: Function, signature: Function}} Refresher.
 */
export function createRefresher( {
	conditions,
	delay,
	evaluate,
	refresh,
	timers = window,
} ) {
	let lastSent = null;
	let timer = null;

	const signature = () =>
		conditions.map( ( c ) => ( evaluate( c ) ? '1' : '0' ) ).join( '' );

	const cancel = () => {
		if ( timer !== null ) {
			timers.clearTimeout( timer );
			timer = null;
		}
	};

	const fire = () => {
		cancel();
		if ( signature() !== lastSent ) {
			lastSent = signature();
			refresh();
		}
	};

	return {
		signature,

		/**
		 * The server has just seen the current form values.
		 */
		sent() {
			cancel();
			lastSent = signature();
		},

		/**
		 * A form value changed.
		 *
		 * @param {boolean} committed The customer left the field or picked a value.
		 */
		changed( committed ) {
			if ( ! conditions.length || signature() === lastSent ) {
				cancel();
				return;
			}
			if ( committed || delay <= 0 ) {
				fire();
				return;
			}
			// Wait at most `delay` from the first change, even if typing goes on.
			if ( timer === null ) {
				timer = timers.setTimeout( fire, delay );
			}
		},
	};
}
