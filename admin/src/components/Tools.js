import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../api';

/**
 * What the current mode makes of a group: all of its tools, some (e.g.
 * updates but not installs on a live site), or none.
 *
 * @param {Object} group A group from the status endpoint.
 * @return {string} Label.
 */
const allowanceLabel = ( group ) => {
	if ( group.partly ) {
		return __( 'Partly', 'wproadie' );
	}
	return group.allowed
		? __( 'Allowed', 'wproadie' )
		: __( 'Blocked', 'wproadie' );
};

const RISK_LABELS = {
	read: __( 'Read', 'wproadie' ),
	write: __( 'Write', 'wproadie' ),
	maintain: __( 'Maintain', 'wproadie' ),
	admin: __( 'Admin', 'wproadie' ),
	dangerous: __( 'Dangerous', 'wproadie' ),
};

export default function Tools( { status, saveSettings } ) {
	const [ error, setError ] = useState( null );
	const [ rateLimit, setRateLimit ] = useState( String( status.rate_limit ) );

	const save = ( data ) =>
		saveSettings( data ).catch( ( err ) =>
			setError( errorMessage( err ) )
		);

	const detected = status.mode.modes.find(
		( m ) => m.value === status.mode.detected
	);

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Card>
				<CardHeader>
					<h2>{ __( 'Mode', 'wproadie' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Mode', 'wproadie' ) }
						value={ status.mode.stored }
						disabled={ status.mode.source === 'constant' }
						options={ [
							{
								value: '',
								label: sprintf(
									/* translators: %s: detected mode */
									__( 'Automatic (%s)', 'wproadie' ),
									detected?.label
								),
							},
							...status.mode.modes,
						] }
						onChange={ ( mode ) => save( { mode } ) }
						help={ __(
							'Staging allows every enabled tool. Production allows reading plus content and settings edits. Read-only allows reading only.',
							'wproadie'
						) }
					/>
					{ status.mode.source === 'constant' && (
						<p>
							<em>
								{ __(
									'Locked by WPROADIE_MODE in wp-config.php.',
									'wproadie'
								) }
							</em>
						</p>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2>{ __( 'Tool groups', 'wproadie' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A tool the AI cannot use is never shown to it. A tool is shown when its group is on, the current mode allows its risk, and this host can run it.',
							'wproadie'
						) }
					</p>
					<table className="widefat striped wproadie-groups">
						<thead>
							<tr>
								<th>{ __( 'Group', 'wproadie' ) }</th>
								<th>{ __( 'Risk', 'wproadie' ) }</th>
								<th>{ __( 'In this mode', 'wproadie' ) }</th>
								<th>{ __( 'Tools', 'wproadie' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ status.groups.map( ( group ) => (
								<tr key={ group.key }>
									<td>
										<ToggleControl
											__nextHasNoMarginBottom
											label={ group.label }
											checked={ group.enabled }
											onChange={ ( on ) =>
												save( {
													groups: {
														[ group.key ]: on,
													},
												} )
											}
										/>
									</td>
									<td>
										<span
											className={ `wproadie-risk wproadie-risk-${ group.risk }` }
										>
											{ RISK_LABELS[ group.risk ] }
										</span>
									</td>
									<td>{ allowanceLabel( group ) }</td>
									<td>
										{ group.abilities.length === 0 &&
											__( '(none yet)', 'wproadie' ) }
										{ group.abilities.map( ( ability ) => (
											<span
												key={ ability.name }
												className={
													ability.exposed
														? 'wproadie-tool is-exposed'
														: 'wproadie-tool'
												}
												title={
													ability.reason ||
													ability.name
												}
											>
												{ ability.label }
												{ ability.type === 'prompt' &&
													` (${ __( 'prompt', 'wproadie' ) })` }
												{ ! ability.available &&
													` — ${ __( 'unavailable on this host', 'wproadie' ) }` }
											</span>
										) ) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2>{ __( 'Safety', 'wproadie' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Stealth mode', 'wproadie' ) }
						help={ __(
							'Hides WPRoadie and MCP Adapter from the Plugins screen and Site Health for everyone except people with AI access, and hides the setup steps from other administrators. The AI connection, log and kill switch keep working.',
							'wproadie'
						) }
						checked={ status.stealth }
						onChange={ ( stealth ) => save( { stealth } ) }
					/>
					<div className="wproadie-inline-form">
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							label={ __(
								'Rate limit (calls per minute per user, 0 = off)',
								'wproadie'
							) }
							value={ rateLimit }
							onChange={ setRateLimit }
						/>
						<Button
							variant="secondary"
							onClick={ () =>
								save( { rate_limit: Number( rateLimit ) } )
							}
						>
							{ __( 'Save', 'wproadie' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</>
	);
}
