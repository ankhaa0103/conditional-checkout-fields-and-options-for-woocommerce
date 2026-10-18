/**
 * Admin entry.
 */
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const root = document.getElementById( 'ccfo-admin' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
