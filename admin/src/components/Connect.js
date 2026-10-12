import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import Copyable from './Copyable';

function formatDate( iso ) {
	return iso ? new Date( iso ).toLocaleString() : '—';
}

export default function Connect( { status } ) {
	const [ passwords, setPasswords ] = useState( [] );
	const [ label, setLabel ] = useState( '' );
	const [ created, setCreated ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = () =>
		api
			.credentials()
			.then( setPasswords )
			.catch( ( err ) => setError( errorMessage( err ) ) );

	useEffect( () => {
		load();
	}, [] );

	const create = () => {
		setBusy( true );
		api.createCredential( label )
			.then( ( data ) => {
				setCreated( data );
				setLabel( '' );
				load();
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const revoke = ( uuid ) =>
		api
			.revokeCredential( uuid )
			.then( load )
			.catch( ( err ) => setError( errorMessage( err ) ) );

	if ( ! status.passwords.available ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ status.passwords.reason }
			</Notice>
		);
	}

	return (
		<>
			<Card>
				<CardHeader>
					<h2>{ __( 'Connect an AI client', 'wproadie' ) }</h2>
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
					{ created ? (
						<>
							<Notice status="success" isDismissible={ false }>
								{ __(
									'Password created. Copy it now: it will not be shown again. Keep it in the team password manager, never in chat or shared files.',
									'wproadie'
								) }
							</Notice>
							<Copyable
								label={ __( 'Password', 'wproadie' ) }
								text={ created.password }
							/>
							<Copyable
								label={ __( 'Claude Code', 'wproadie' ) }
								text={ created.claude_code }
							/>
							<Copyable
								label={ __(
									'Claude Desktop or Cursor (claude_desktop_config.json / .cursor/mcp.json, needs Node.js)',
									'wproadie'
								) }
								text={ created.desktop_config }
							/>
							<Button
								variant="secondary"
								onClick={ () => setCreated( null ) }
							>
								{ __( 'Done', 'wproadie' ) }
							</Button>
						</>
					) : (
						<>
							<p>
								{ __(
									'Creates a WordPress Application Password for you and shows the config to paste into your AI client. The AI acts as you, so everything it does is logged under your name.',
									'wproadie'
								) }
							</p>
							<div className="wproadie-inline-form">
								<TextControl
									__nextHasNoMarginBottom
									label={ __( 'Label', 'wproadie' ) }
									placeholder={ __(
										'e.g. "Abdul – Claude Desktop"',
										'wproadie'
									) }
									value={ label }
									onChange={ setLabel }
								/>
								<Button
									variant="primary"
									isBusy={ busy }
									disabled={ busy }
									onClick={ create }
								>
									{ __(
										'Create connection password',
										'wproadie'
									) }
								</Button>
							</div>
						</>
					) }
				</CardBody>
			</Card>
			{ passwords.length > 0 && (
				<Card>
					<CardHeader>
						<h2>{ __( 'Your WPRoadie passwords', 'wproadie' ) }</h2>
					</CardHeader>
					<CardBody>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Name', 'wproadie' ) }</th>
									<th>{ __( 'Created', 'wproadie' ) }</th>
									<th>{ __( 'Last used', 'wproadie' ) }</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ passwords.map( ( item ) => (
									<tr key={ item.uuid }>
										<td>{ item.name }</td>
										<td>{ formatDate( item.created ) }</td>
										<td>
											{ formatDate( item.last_used ) }
											{ item.last_ip
												? ` (${ item.last_ip })`
												: '' }
										</td>
										<td>
											<Button
												variant="link"
												isDestructive
												onClick={ () =>
													revoke( item.uuid )
												}
											>
												{ __( 'Revoke', 'wproadie' ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</CardBody>
				</Card>
			) }
		</>
	);
}
