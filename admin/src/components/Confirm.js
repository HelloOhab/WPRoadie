import { Button, Flex, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * A small confirmation dialog built from stable components.
 *
 * @param {Object}                    props
 * @param {boolean}                   props.isOpen    Whether the dialog shows.
 * @param {() => void}                props.onConfirm Called on confirm.
 * @param {() => void}                props.onCancel  Called on cancel or close.
 * @param {import("react").ReactNode} props.children  The question.
 */
export default function Confirm( { isOpen, onConfirm, onCancel, children } ) {
	if ( ! isOpen ) {
		return null;
	}
	return (
		<Modal
			title={ __( 'Are you sure?', 'wproadie' ) }
			onRequestClose={ onCancel }
			size="small"
		>
			<p>{ children }</p>
			<Flex justify="flex-end">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'wproadie' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ __( 'Confirm', 'wproadie' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
