import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const root = document.getElementById( 'wproadie-admin' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
