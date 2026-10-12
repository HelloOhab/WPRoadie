import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';

/**
 * Connects to HQ with a connection string pasted from HQ: the manual
 * alternative to the Connect to HQ button.
 *
 * @param {Object}     props
 * @param {() => void} props.onConnected Called after a successful connect.
 */
export default function ManualConnect( { onConnected } ) {
	const [ value, setValue ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const connect = () => {
		setBusy( true );
		setError( null );
		api.hqConnect( value.trim() )
			.then( () => {
				setValue( '' );
				onConnected();
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="wproadie-manual-connect">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<p>
				{ __(
					'Paste the connection string HQ showed when this site was added there. Over SSH you can also run `wp wproadie hq connect`.',
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
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || ! value.trim() }
					onClick={ connect }
				>
					{ __( 'Connect', 'wproadie' ) }
				</Button>
			</div>
		</div>
	);
}
