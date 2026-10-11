import apiFetch from '@wordpress/api-fetch';

const BASE = '/wproadie/v1';

export const api = {
	status: () => apiFetch( { path: `${ BASE }/status` } ),
	saveSettings: ( data ) =>
		apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } ),
	credentials: () => apiFetch( { path: `${ BASE }/credentials` } ),
	createCredential: ( label ) =>
		apiFetch( {
			path: `${ BASE }/credentials`,
			method: 'POST',
			data: { label },
		} ),
	revokeCredential: ( uuid ) =>
		apiFetch( {
			path: `${ BASE }/credentials/${ encodeURIComponent( uuid ) }`,
			method: 'DELETE',
		} ),
	access: () => apiFetch( { path: `${ BASE }/access` } ),
	grant: ( userId ) =>
		apiFetch( {
			path: `${ BASE }/access`,
			method: 'POST',
			data: { user_id: userId },
		} ),
	revoke: ( userId ) =>
		apiFetch( { path: `${ BASE }/access/${ userId }`, method: 'DELETE' } ),
	log: ( params ) => {
		const query = new URLSearchParams();
		Object.entries( params ).forEach( ( [ key, value ] ) => {
			if ( value !== '' && value !== false && value !== undefined ) {
				query.set( key, value );
			}
		} );
		return apiFetch( { path: `${ BASE }/log?${ query.toString() }` } );
	},
	logEntry: ( id ) => apiFetch( { path: `${ BASE }/log/${ id }` } ),
	undo: ( id ) =>
		apiFetch( { path: `${ BASE }/log/${ id }/undo`, method: 'POST' } ),
};

export function errorMessage( error ) {
	return error?.message || String( error );
}
