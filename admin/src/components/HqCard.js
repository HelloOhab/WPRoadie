import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';
import Confirm from './Confirm';

/**
 * Connection to WPRoadie HQ: status, connect with a connection string,
 * send a heartbeat now, disconnect.
 *
 * @param {Object}                    props
 * @param {Object}                    props.status       Status from the REST API.
 * @param {(data: Object) => Promise} props.saveSettings Saves settings and refreshes status.
 */
export default function HqCard( { status, saveSettings } ) {
	const hq = status.hq;
	const [ value, setValue ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );

	const save = ( data ) => {
		setBusy( true );
		setError( null );
		return saveSettings( data )
			.then( () => setValue( '' ) )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const last = hq.last;

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
				{ hq.connected ? (
					<>
						<p>
							{ sprintf(
								/* translators: %s: HQ address */
								__(
									'Connected to %s. This site reports its status hourly; HQ can issue and revoke connection passwords for people who already have access here.',
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
												'Last heartbeat accepted %s.',
												'wproadie'
											),
											new Date( last.at ).toLocaleString()
										)
									: sprintf(
											/* translators: 1: date and time, 2: error */
											__(
												'Last heartbeat failed %1$s: %2$s',
												'wproadie'
											),
											new Date(
												last.at
											).toLocaleString(),
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
								{ __( 'Send heartbeat now', 'wproadie' ) }
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
				) : (
					<>
						<p>
							{ __(
								'Paste the connection string HQ showed when this site was added. You can also run `wp wproadie hq connect` over SSH.',
								'wproadie'
							) }
						</p>
						<div className="wproadie-inline-form">
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Connection string', 'wproadie' ) }
								placeholder="wproadie-hq:v1:…"
								value={ value }
								onChange={ setValue }
							/>
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy || ! value.trim() }
								onClick={ () =>
									save( { hq_connect: value.trim() } )
								}
							>
								{ __( 'Connect', 'wproadie' ) }
							</Button>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
}
