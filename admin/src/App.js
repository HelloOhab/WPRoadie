import { useCallback, useEffect, useState } from '@wordpress/element';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from './api';
import Overview from './components/Overview';
import Connect from './components/Connect';
import Access from './components/Access';
import Tools from './components/Tools';
import Activity from './components/Activity';

const TABS = [
	{ name: 'overview', title: __( 'Overview', 'wproadie' ) },
	{ name: 'connect', title: __( 'Connect', 'wproadie' ) },
	{ name: 'access', title: __( 'Who can connect', 'wproadie' ) },
	{ name: 'tools', title: __( 'Mode & tools', 'wproadie' ) },
	{ name: 'activity', title: __( 'Activity', 'wproadie' ) },
];

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );

	const refresh = useCallback( () => {
		api.status()
			.then( ( data ) => {
				setStatus( data );
				setError( null );
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) );
	}, [] );

	useEffect( refresh, [ refresh ] );

	const saveSettings = useCallback(
		( data ) => api.saveSettings( data ).then( setStatus ),
		[]
	);

	if ( error && ! status ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! status ) {
		return <Spinner />;
	}

	const props = { status, refresh, saveSettings };

	return (
		<div className="wproadie-app">
			{ status.disabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WPRoadie is switched off. No AI client can use it.',
						'wproadie'
					) }
				</Notice>
			) }
			{ ! status.adapter.available && (
				<Notice status="error" isDismissible={ false }>
					{ `${ __(
						'MCP Adapter is missing or too old. Install version',
						'wproadie'
					) } ${ status.adapter.required }.` }
				</Notice>
			) }
			<TabPanel className="wproadie-tabs" tabs={ TABS }>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'connect':
							return <Connect { ...props } />;
						case 'access':
							return <Access { ...props } />;
						case 'tools':
							return <Tools { ...props } />;
						case 'activity':
							return <Activity { ...props } />;
						default:
							return <Overview { ...props } />;
					}
				} }
			</TabPanel>
		</div>
	);
}
