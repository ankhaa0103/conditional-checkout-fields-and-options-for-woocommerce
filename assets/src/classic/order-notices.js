/**
 * Classic cart and checkout: order rule messages at the top of the page.
 */
import { createOrderNotices } from './notices';

( function ( $ ) {
	if ( ! $ ) {
		return;
	}

	const notices = createOrderNotices( {
		$,
		root: document,
		scroll: ( $el ) => {
			if ( typeof $.scroll_to_notices === 'function' ) {
				$.scroll_to_notices( $el );
			} else {
				$el.get( 0 )?.scrollIntoView( { block: 'center' } );
			}
		},
	} );

	$( () => notices.sync( false ) );
	$( document.body ).on(
		'updated_checkout updated_cart_totals updated_wc_div wc_fragments_refreshed',
		() => notices.sync( true )
	);
} )( window.jQuery );
