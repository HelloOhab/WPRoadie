import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import Confirm from './Confirm';

function pretty( json ) {
	try {
		return JSON.stringify( JSON.parse( json ), null, 2 );
	} catch {
		return json;
	}
}

export default function Activity() {
	const [ filters, setFilters ] = useState( {
		ability: '',
		status: '',
		undoable: false,
	} );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ detail, setDetail ] = useState( null );
	const [ confirmUndo, setConfirmUndo ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( () => {
		api.log( { ...filters, page, per_page: 25 } )
			.then( setData )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: errorMessage( err ) } )
			);
	}, [ filters, page ] );

	useEffect( load, [ load ] );

	const undo = ( id ) => {
		setConfirmUndo( null );
		api.undo( id )
			.then( ( result ) => {
				setNotice(
					result.errors.length
						? {
								status: 'warning',
								text: sprintf(
									/* translators: 1: reverted count, 2: errors */
									__(
										'Partly undone (%1$d steps). Problems: %2$s',
										'wproadie'
									),
									result.reverted,
									result.errors.join( ' ' )
								),
							}
						: {
								status: 'success',
								text: __( 'Change undone.', 'wproadie' ),
							}
				);
				load();
			} )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: errorMessage( err ) } )
			);
	};

	const setFilter = ( key ) => ( value ) => {
		setPage( 1 );
		setFilters( { ...filters, [ key ]: value } );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'AI activity', 'wproadie' ) }</h2>
			</CardHeader>
			<CardBody>
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.text }
					</Notice>
				) }
				<div className="wproadie-inline-form">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Tool', 'wproadie' ) }
						placeholder="wproadie/update-post"
						value={ filters.ability }
						onChange={ setFilter( 'ability' ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Status', 'wproadie' ) }
						value={ filters.status }
						options={ [
							{ value: '', label: __( 'Any', 'wproadie' ) },
							{
								value: 'success',
								label: __( 'Succeeded', 'wproadie' ),
							},
							{
								value: 'error',
								label: __( 'Failed', 'wproadie' ),
							},
						] }
						onChange={ setFilter( 'status' ) }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Can be undone', 'wproadie' ) }
						checked={ filters.undoable }
						onChange={ setFilter( 'undoable' ) }
					/>
				</div>
				{ data && data.entries.length === 0 && (
					<p>{ __( 'No activity yet.', 'wproadie' ) }</p>
				) }
				{ data && data.entries.length > 0 && (
					<table className="widefat striped wproadie-log">
						<thead>
							<tr>
								<th>{ __( 'Time', 'wproadie' ) }</th>
								<th>{ __( 'User', 'wproadie' ) }</th>
								<th>{ __( 'Tool', 'wproadie' ) }</th>
								<th>{ __( 'Details', 'wproadie' ) }</th>
								<th>{ __( 'Via', 'wproadie' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ data.entries.map( ( entry ) => (
								<tr
									key={ entry.id }
									className={
										entry.status === 'error'
											? 'is-error'
											: ''
									}
								>
									<td>
										{ new Date(
											entry.time
										).toLocaleString() }
									</td>
									<td>{ entry.user }</td>
									<td>
										<code>{ entry.ability }</code>
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												api
													.logEntry( entry.id )
													.then( setDetail )
											}
										>
											{ entry.summary ||
												__( 'View', 'wproadie' ) }
										</Button>
									</td>
									<td>
										{ entry.transport } { entry.ip }
									</td>
									<td>
										{ entry.undoable && (
											<Button
												variant="secondary"
												size="small"
												onClick={ () =>
													setConfirmUndo( entry )
												}
											>
												{ __( 'Undo', 'wproadie' ) }
											</Button>
										) }
										{ entry.undone_at && (
											<em>
												{ __( 'Undone', 'wproadie' ) }
											</em>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ data && data.total_pages > 1 && (
					<p className="wproadie-pager">
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => setPage( page - 1 ) }
						>
							{ __( 'Newer', 'wproadie' ) }
						</Button>
						{ sprintf(
							/* translators: 1: page, 2: total pages */
							__( 'Page %1$d of %2$d', 'wproadie' ),
							page,
							data.total_pages
						) }
						<Button
							variant="secondary"
							disabled={ page >= data.total_pages }
							onClick={ () => setPage( page + 1 ) }
						>
							{ __( 'Older', 'wproadie' ) }
						</Button>
					</p>
				) }
				<Confirm
					isOpen={ !! confirmUndo }
					onConfirm={ () => undo( confirmUndo.id ) }
					onCancel={ () => setConfirmUndo( null ) }
				>
					{ confirmUndo &&
						sprintf(
							/* translators: %s: tool name */
							__(
								'Undo this %s call? Anything changed on the same items since will be overwritten.',
								'wproadie'
							),
							confirmUndo.ability
						) }
				</Confirm>
				{ detail && (
					<Modal
						title={ `${ detail.ability } #${ detail.id }` }
						onRequestClose={ () => setDetail( null ) }
						size="large"
					>
						<p>
							{ detail.user } ·{ ' ' }
							{ new Date( detail.time ).toLocaleString() } ·{ ' ' }
							{ detail.status } · { detail.duration_ms } ms
						</p>
						<h3>{ __( 'Input', 'wproadie' ) }</h3>
						<pre className="wproadie-pre">
							{ pretty( detail.input ) }
						</pre>
						<h3>{ __( 'Result', 'wproadie' ) }</h3>
						<pre className="wproadie-pre">
							{ pretty( detail.result ) }
						</pre>
					</Modal>
				) }
			</CardBody>
		</Card>
	);
}
