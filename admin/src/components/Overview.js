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

const SOURCES = {
	constant: __( 'set by WPROADIE_MODE in wp-config.php', 'wproadie' ),
	setting: __( 'set on the Mode & tools tab', 'wproadie' ),
	environment: __( 'detected from the environment type', 'wproadie' ),
};

export default function Overview( { status, saveSettings } ) {
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
		[ __( 'Version', 'wproadie' ), status.version ],
		[
			__( 'MCP Adapter', 'wproadie' ),
			status.adapter.available
				? status.adapter.version
				: __( 'Missing', 'wproadie' ),
		],
		[
			__( 'Mode', 'wproadie' ),
			`${ mode?.label } — ${ SOURCES[ status.mode.source ] } ("${ status.mode.environment }")`,
		],
		[
			__( 'Endpoint', 'wproadie' ),
			<code key="e">{ status.endpoint }</code>,
		],
		[
			__( 'Tools the AI sees', 'wproadie' ),
			status.disabled ? '—' : String( status.exposed ),
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

	return (
		<>
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
					<table className="widefat striped wproadie-status">
						<tbody>
							{ rows.map( ( [ label, value ] ) => (
								<tr key={ label }>
									<th scope="row">{ label }</th>
									<td>{ value }</td>
								</tr>
							) ) }
						</tbody>
					</table>
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
							'Shut off WPRoadie for every AI client on this site?',
							'wproadie'
						) }
					</Confirm>
				</CardBody>
			</Card>
			<HqCard status={ status } saveSettings={ saveSettings } />
		</>
	);
}
