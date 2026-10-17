import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import Confirm from './Confirm';

/**
 * Connection to WPRoadie HQ once connected: status, send a heartbeat now,
 * disconnect. Connecting is a step of the setup checklist.
 *
 * @param {Object}                    props
 * @param {Object}                    props.status       Status from the REST API.
 * @param {(data: Object) => Promise} props.saveSettings Saves settings and refreshes status.
 */
export default function HqCard( { status, saveSettings } ) {
	const hq = status.hq;
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );

	const save = ( data ) => {
		setBusy( true );
		setError( null );
		return saveSettings( data )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const last = hq.last;
	if ( ! hq.connected ) {
		return null;
	}

	const answerMoved = ( keep ) => {
		setBusy( true );
		setError( null );
		return api
			.hqMoved( keep )
			.then( () => saveSettings( {} ) )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	if ( hq.moved ) {
		return (
			<Card>
				<CardHeader>
					<h2>{ __( 'WPRoadie HQ', 'wproadie' ) }</h2>
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
					<Notice status="warning" isDismissible={ false }>
						<p>
							{ sprintf(
								/* translators: 1: this site's address, 2: the address the connection was made on */
								__(
									'This WordPress is at %1$s, but its HQ connection was made at %2$s. That usually means this is a copy of that site, for example a staging site refreshed from live. Until you choose below, this site does not report to HQ, so HQ keeps showing the original site correctly.',
									'wproadie'
								),
								hq.moved.to,
								hq.moved.from
							) }
						</p>
					</Notice>
					<div className="wproadie-inline-form">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => answerMoved( false ) }
						>
							{ __(
								'This is a copy: forget the connection',
								'wproadie'
							) }
						</Button>
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => answerMoved( true ) }
						>
							{ __(
								'The site moved here: keep the connection',
								'wproadie'
							) }
						</Button>
					</div>
					<p className="description">
						{ __(
							'After forgetting the connection, use “Connect to HQ” in the setup steps to add this site to HQ as its own site.',
							'wproadie'
						) }
					</p>
				</CardBody>
			</Card>
		);
	}

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'WPRoadie HQ', 'wproadie' ) }</h2>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<>
					<p>
						{ sprintf(
							/* translators: %s: HQ address */
							__(
								'Connected to %s. This site reports its status to HQ every hour. HQ can connect people who already have AI access here, and disconnect them.',
								'wproadie'
							),
							hq.url
						) }
					</p>
					{ last && (
						<p>
							{ last.ok
								? sprintf(
										/* translators: %s: date and time */
										__(
											'Last status report accepted %s.',
											'wproadie'
										),
										new Date( last.at ).toLocaleString()
									)
								: sprintf(
										/* translators: 1: date and time, 2: error */
										__(
											'Last status report failed %1$s: %2$s',
											'wproadie'
										),
										new Date( last.at ).toLocaleString(),
										last.error
									) }
						</p>
					) }
					<div className="wproadie-inline-form">
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => save( { hq_heartbeat: true } ) }
						>
							{ __( 'Report status now', 'wproadie' ) }
						</Button>
						{ hq.from_constants ? (
							<em>
								{ __(
									'Configured by constants in wp-config.php.',
									'wproadie'
								) }
							</em>
						) : (
							<Button
								variant="link"
								isDestructive
								onClick={ () => setConfirming( true ) }
							>
								{ __( 'Disconnect', 'wproadie' ) }
							</Button>
						) }
					</div>
					<Confirm
						isOpen={ confirming }
						onConfirm={ () => {
							setConfirming( false );
							save( { hq_disconnect: true } );
						} }
						onCancel={ () => setConfirming( false ) }
					>
						{ __(
							'Stop reporting to HQ? HQ will show this site as offline until it is connected again.',
							'wproadie'
						) }
					</Confirm>
				</>
			</CardBody>
		</Card>
	);
}
