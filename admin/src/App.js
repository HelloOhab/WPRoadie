import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from './api';
import Overview from './components/Overview';
import Connect from './components/Connect';
import Access from './components/Access';
import Tools from './components/Tools';
import Activity from './components/Activity';
import SetupCard from './components/SetupCard';

const TABS = [
	{ name: 'overview', title: __( 'Overview', 'wproadie' ) },
	{ name: 'connect', title: __( 'Connect an AI app', 'wproadie' ) },
	{ name: 'access', title: __( 'Who has AI access', 'wproadie' ) },
	{ name: 'tools', title: __( 'Mode & tools', 'wproadie' ) },
	{ name: 'activity', title: __( 'Activity', 'wproadie' ) },
];

// Parameters HQ adds when it sends the browser back (docs/hq-protocol.md).
const RETURN_PARAMS = [ 'wproadie_hq', 'state', 'wproadie_hq_error' ];

/**
 * Reads HQ's return parameters, if any, and removes them from the address
 * bar so a reload does not repeat the request.
 *
 * @return {Object|null} { code, state, error } or null.
 */
function takeReturnParams() {
	const url = new URL( window.location.href );
	const code = url.searchParams.get( 'wproadie_hq' );
	const error = url.searchParams.get( 'wproadie_hq_error' );
	if ( ! code && ! error ) {
		return null;
	}
	const state = url.searchParams.get( 'state' );
	RETURN_PARAMS.forEach( ( name ) => url.searchParams.delete( name ) );
	window.history.replaceState( window.history.state, '', url.toString() );
	return { code, state, error };
}

export default function App() {
	const [ setup, setSetup ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const returned = useRef( false );

	const refresh = useCallback(
		() =>
			api
				.setup()
				.then( ( data ) => {
					setSetup( data );
					if ( ! data.access.has_access ) {
						setStatus( null );
						return null;
					}
					return api.status().then( ( full ) => {
						setStatus( full );
						setSetup( full.setup );
					} );
				} )
				.then( () => setError( null ) )
				.catch( ( err ) => setError( errorMessage( err ) ) ),
		[]
	);

	useEffect( () => {
		if ( returned.current ) {
			return;
		}
		returned.current = true;

		const back = takeReturnParams();
		if ( ! back ) {
			refresh();
			return;
		}
		if ( back.error ) {
			setNotice( {
				status: 'warning',
				text:
					back.error === 'access_denied'
						? __( 'Connection cancelled in HQ.', 'wproadie' )
						: __(
								'HQ could not connect this site. Try again.',
								'wproadie'
							),
			} );
			refresh();
			return;
		}
		api.hqPairFinish( back.code, back.state || '' )
			.then( () =>
				setNotice( {
					status: 'success',
					text: __(
						'Connected to WPRoadie HQ. You now have AI access on this site.',
						'wproadie'
					),
				} )
			)
			.catch( ( err ) =>
				setNotice( { status: 'error', text: errorMessage( err ) } )
			)
			.finally( refresh );
	}, [ refresh ] );

	const saveSettings = useCallback(
		( data ) =>
			api.saveSettings( data ).then( ( full ) => {
				setStatus( full );
				setSetup( full.setup );
			} ),
		[]
	);

	const top = notice && (
		<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
			{ notice.text }
		</Notice>
	);

	if ( error && ! setup ) {
		return (
			<>
				{ top }
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</>
		);
	}
	if ( ! setup ) {
		return (
			<>
				{ top }
				<Spinner />
			</>
		);
	}

	// Without AI access: only the setup steps.
	if ( ! setup.access.has_access ) {
		return (
			<div className="wproadie-app">
				{ top }
				<SetupCard setup={ setup } onChanged={ refresh } />
			</div>
		);
	}
	if ( ! status ) {
		return (
			<>
				{ top }
				<Spinner />
			</>
		);
	}

	const props = { status, refresh, saveSettings };

	return (
		<div className="wproadie-app">
			{ top }
			{ status.disabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WPRoadie is switched off. No AI app can use this site.',
						'wproadie'
					) }
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
