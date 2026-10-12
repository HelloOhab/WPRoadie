import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';

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
				<h2>{ __( 'Who can connect', 'wproadie' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Only administrators added here can connect AI clients or open this screen. Being an administrator is not enough on its own.',
						'wproadie'
					) }
				</p>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<table className="widefat striped">
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
							label={ __( 'Give access to', 'wproadie' ) }
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
