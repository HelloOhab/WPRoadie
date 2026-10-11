import { Button } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Copyable( { text, label } ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( text, () => {
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	} );

	return (
		<div className="wproadie-copyable">
			{ label && <h4>{ label }</h4> }
			<pre>{ text }</pre>
			<Button variant="secondary" size="small" ref={ ref }>
				{ copied
					? __( 'Copied', 'wproadie' )
					: __( 'Copy', 'wproadie' ) }
			</Button>
		</div>
	);
}
