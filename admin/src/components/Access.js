import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';

/**
 * The HQ sign-in email of one user: their WordPress email unless an
 * administrator linked another one. HQ only issues them passwords if its
 * signed-in person's email matches.
 *
 * @param {Object}                           props        Props.
 * @param {Object}                           props.user   The user row from the access list.
 * @param {(email: string) => Promise<void>} props.onSave Saves an email.
 */
function HqEmail( { user, onSave } ) {
	const [ editing, setEditing ] = useState( false );
	const [ value, setValue ] = useState( user.hq_email || '' );

	if ( ! editing ) {
		return (
			<>
				{ user.hq_email || user.email }
				{ ! user.hq_email && (
					<span className="description">
						{ ' ' }
						{ __( '(WordPress email)', 'wproadie' ) }
					</span>
				) }{ ' ' }
				<Button variant="link" onClick={ () => setEditing( true ) }>
					{ __( 'Change', 'wproadie' ) }
				</Button>
			</>
		);
	}
	return (
		<div className="wproadie-inline-form">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="email"
				label={ __( 'HQ sign-in email', 'wproadie' ) }
				hideLabelFromVision
				placeholder={ user.email }
				value={ value }
				onChange={ setValue }
			/>
			<Button
				variant="secondary"
				onClick={ () =>
					onSave( value ).then( () => setEditing( false ) )
				}
			>
				{ __( 'Save', 'wproadie' ) }
			</Button>
			<Button variant="tertiary" onClick={ () => setEditing( false ) }>
				{ __( 'Cancel', 'wproadie' ) }
			</Button>
		</div>
	);
}

export default function Access() {
	const [ data, setData ] = useState( null );
	const [ selected, setSelected ] = useState( '' );
	const [ error, setError ] = useState( null );

	const handle = ( promise ) =>
		promise
			.then( ( result ) => {
				setData( result );
				setSelected( '' );
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) );

	useEffect( () => {
		handle( api.access() );
	}, [] );

	if ( ! data ) {
		return null;
	}

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Who has AI access', 'wproadie' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Only the administrators listed here can use AI on this site (through WPRoadie HQ, Claude or another AI app) or open this screen. Being an administrator is not enough on its own.',
						'wproadie'
					) }
				</p>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<p className="description">
					{ __(
						'WPRoadie HQ sets up AI access only for the HQ account whose sign-in email matches. If someone signs in to HQ with a different email than their WordPress account here, change their HQ sign-in email.',
						'wproadie'
					) }
				</p>
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Person', 'wproadie' ) }</th>
							<th>{ __( 'HQ sign-in email', 'wproadie' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ data.granted.map( ( user ) => (
							<tr key={ user.id }>
								<td>
									{ user.display_name } ({ user.login })
									{ ! user.is_admin && (
										<em>
											{ ' ' }
											{ __(
												'— no longer an administrator, so access is inactive',
												'wproadie'
											) }
										</em>
									) }
								</td>
								<td>
									<HqEmail
										user={ user }
										onSave={ ( email ) =>
											api
												.setHqEmail( user.id, email )
												.then( setData )
												.catch( ( err ) => {
													setError(
														errorMessage( err )
													);
													throw err;
												} )
										}
									/>
								</td>
								<td>
									{ user.id === data.me ? (
										<em>{ __( 'You', 'wproadie' ) }</em>
									) : (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												handle( api.revoke( user.id ) )
											}
										>
											{ __(
												'Remove access',
												'wproadie'
											) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				{ data.candidates.length > 0 && (
					<div className="wproadie-inline-form">
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Give AI access to', 'wproadie' ) }
							value={ selected }
							options={ [
								{
									value: '',
									label: __(
										'Choose an administrator',
										'wproadie'
									),
								},
								...data.candidates.map( ( user ) => ( {
									value: String( user.id ),
									label: `${ user.display_name } (${ user.login })`,
								} ) ),
							] }
							onChange={ setSelected }
						/>
						<Button
							variant="secondary"
							disabled={ ! selected }
							onClick={ () =>
								handle( api.grant( Number( selected ) ) )
							}
						>
							{ __( 'Give access', 'wproadie' ) }
						</Button>
					</div>
				) }
			</CardBody>
		</Card>
	);
}
