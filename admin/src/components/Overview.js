import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import Confirm from './Confirm';
import HqCard from './HqCard';
import SetupCard from './SetupCard';

const SOURCES = {
	constant: __( 'set by WPROADIE_MODE in wp-config.php', 'wproadie' ),
	setting: __( 'set on the Mode & tools tab', 'wproadie' ),
	environment: __( 'detected from the environment type', 'wproadie' ),
};

function adapterState( status ) {
	if ( status.adapter.available ) {
		return status.adapter.version;
	}
	const action = status.setup?.adapter?.action;
	if ( action === 'activate' ) {
		return __( 'Installed, not active', 'wproadie' );
	}
	if ( action === 'update' || action === 'manual' ) {
		return __( 'Too old', 'wproadie' );
	}
	return __( 'Missing', 'wproadie' );
}

export default function Overview( { status, refresh, saveSettings } ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const toggle = ( disabled ) => {
		setBusy( true );
		saveSettings( { disabled } )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => {
				setBusy( false );
				setConfirming( false );
			} );
	};

	const mode = status.mode.modes.find(
		( m ) => m.value === status.mode.current
	);

	const rows = [
		[
			__( 'WPRoadie', 'wproadie' ),
			status.disabled
				? __( 'Off (kill switch is on)', 'wproadie' )
				: __( 'On', 'wproadie' ),
		],
		[
			__( 'MCP Adapter (required plugin)', 'wproadie' ),
			adapterState( status ),
		],
		[
			__( 'Mode', 'wproadie' ),
			`${ mode?.label } — ${ SOURCES[ status.mode.source ] }`,
		],
		[
			__( 'Tools the AI can use', 'wproadie' ),
			status.disabled ? '—' : String( status.exposed ),
		],
	];

	const advanced = [
		[ __( 'WPRoadie version', 'wproadie' ), status.version ],
		[
			__( 'MCP address (endpoint)', 'wproadie' ),
			<code key="e">{ status.endpoint }</code>,
		],
		[
			__( 'Environment type', 'wproadie' ),
			<code key="t">{ status.mode.environment }</code>,
		],
		[
			__( 'Rate limit', 'wproadie' ),
			status.rate_limit
				? sprintf(
						/* translators: %d: calls per minute */
						__( '%d calls per minute per user', 'wproadie' ),
						status.rate_limit
					)
				: __( 'Off', 'wproadie' ),
		],
		[
			__( 'Stealth mode', 'wproadie' ),
			status.stealth ? __( 'On', 'wproadie' ) : __( 'Off', 'wproadie' ),
		],
	];

	const table = ( items ) => (
		<table className="widefat striped wproadie-status">
			<tbody>
				{ items.map( ( [ label, value ] ) => (
					<tr key={ label }>
						<th scope="row">{ label }</th>
						<td>{ value }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);

	return (
		<>
			<SetupCard setup={ status.setup } onChanged={ refresh } />
			<Card>
				<CardHeader>
					<h2>{ __( 'Status', 'wproadie' ) }</h2>
				</CardHeader>
				<CardBody>
					{ error && (
						<Notice
							status="error"
							onRemove={ () => setError( null ) }
						>
							{ error }
						</Notice>
					) }
					{ table( rows ) }
					<details className="wproadie-advanced">
						<summary>{ __( 'Advanced', 'wproadie' ) }</summary>
						{ table( advanced ) }
					</details>
					<p>
						{ status.disabled_by_constant && (
							<em>
								{ __(
									'Locked off by WPROADIE_DISABLED in wp-config.php.',
									'wproadie'
								) }
							</em>
						) }
						{ ! status.disabled_by_constant && status.disabled && (
							<Button
								variant="primary"
								isBusy={ busy }
								onClick={ () => toggle( false ) }
							>
								{ __( 'Turn WPRoadie back on', 'wproadie' ) }
							</Button>
						) }
						{ ! status.disabled && (
							<Button
								variant="secondary"
								isDestructive
								isBusy={ busy }
								onClick={ () => setConfirming( true ) }
							>
								{ __(
									'Kill switch: turn WPRoadie off',
									'wproadie'
								) }
							</Button>
						) }
					</p>
					<Confirm
						isOpen={ confirming }
						onConfirm={ () => toggle( true ) }
						onCancel={ () => setConfirming( false ) }
					>
						{ __(
							'Shut off WPRoadie for every AI app on this site?',
							'wproadie'
						) }
					</Confirm>
				</CardBody>
			</Card>
			<HqCard status={ status } saveSettings={ saveSettings } />
		</>
	);
}
