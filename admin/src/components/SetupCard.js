import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import ManualConnect from './ManualConnect';

const ADAPTER_BUTTONS = {
	install: __( 'Install and activate', 'wproadie' ),
	activate: __( 'Activate', 'wproadie' ),
	update: __( 'Update', 'wproadie' ),
};

/**
 * One step of the checklist.
 *
 * @param {Object}  props
 * @param {number}  props.number   Step number.
 * @param {string}  props.title    What to do.
 * @param {boolean} props.done     Whether it is done.
 * @param {Object}  props.children Controls shown while not done.
 */
function Step( { number, title, done, children } ) {
	return (
		<li className={ done ? 'wproadie-step is-done' : 'wproadie-step' }>
			<span className="wproadie-step__mark" aria-hidden="true">
				{ done ? '✓' : number }
			</span>
			<div className="wproadie-step__body">
				<strong>{ title }</strong>
				{ done && (
					<span className="screen-reader-text">
						{ __( '(done)', 'wproadie' ) }
					</span>
				) }
				{ ! done && children }
			</div>
		</li>
	);
}

/**
 * "Get set up": install the MCP Adapter, connect to HQ, give yourself AI
 * access. Collapses to one line once everything is done.
 *
 * @param {Object}     props
 * @param {Object}     props.setup     Setup state from the REST API.
 * @param {() => void} props.onChanged Reloads setup state.
 */
export default function SetupCard( { setup, onChanged } ) {
	const [ hqUrl, setHqUrl ] = useState( setup.hq.default_url || '' );
	const [ manual, setManual ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );

	if ( setup.complete ) {
		return (
			<p className="wproadie-setup-done">
				<span aria-hidden="true">✓ </span>
				{ __(
					'All set: the MCP Adapter is active, this site is connected to WPRoadie HQ and you have AI access.',
					'wproadie'
				) }
			</p>
		);
	}

	const run = ( key, promise, then ) => {
		setBusy( key );
		setError( null );
		promise.then( then ).catch( ( err ) => {
			setError( errorMessage( err ) );
			setBusy( '' );
		} );
	};

	const adapter = setup.adapter;
	const installAdapter = () =>
		run( 'adapter', api.installAdapter(), () =>
			// WPRoadie starts its AI connection when the page loads.
			window.location.reload()
		);

	const pair = () =>
		run( 'pair', api.hqPair( hqUrl.trim() ), ( result ) =>
			window.location.assign( result.redirect )
		);

	const grant = () =>
		run( 'grant', api.grantSelf(), () => {
			setBusy( '' );
			onChanged();
		} );

	return (
		<Card className="wproadie-setup">
			<CardHeader>
				<h2>{ __( 'Get set up', 'wproadie' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Three steps and Claude (or another AI app) can work on this site, safely and with every change logged.',
						'wproadie'
					) }
				</p>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<ol className="wproadie-steps">
					<Step
						number={ 1 }
						title={ __( 'Install the MCP Adapter', 'wproadie' ) }
						done={ adapter.available }
					>
						<p>
							{ __(
								'A free plugin from the WordPress team that lets AI apps talk to this site. WPRoadie needs it.',
								'wproadie'
							) }
						</p>
						{ adapter.action === 'none' && (
							<Button
								variant="primary"
								onClick={ () => window.location.reload() }
							>
								{ __(
									'Reload the page to finish',
									'wproadie'
								) }
							</Button>
						) }
						{ adapter.action !== 'none' && adapter.can_install && (
							<Button
								variant="primary"
								isBusy={ busy === 'adapter' }
								disabled={ !! busy }
								onClick={ installAdapter }
							>
								{ ADAPTER_BUTTONS[ adapter.action ] ||
									ADAPTER_BUTTONS.install }
							</Button>
						) }
						{ adapter.action !== 'none' &&
							! adapter.can_install && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ adapter.cannot_install_why }
								</Notice>
							) }
					</Step>
					<Step
						number={ 2 }
						title={ __(
							'Connect this site to WPRoadie HQ',
							'wproadie'
						) }
						done={ setup.hq.connected }
					>
						<p>
							{ __(
								'HQ is where your team sees all its sites and connects Claude to them. You approve the connection in HQ; no passwords to copy.',
								'wproadie'
							) }
						</p>
						{ setup.disabled && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'WPRoadie is switched off. Turn it back on to connect to HQ.',
									'wproadie'
								) }
							</Notice>
						) }
						{ ! setup.disabled && ! setup.hq.can_change && (
							<p>
								<em>
									{ __(
										'Ask someone with AI access on this site to connect it.',
										'wproadie'
									) }
								</em>
							</p>
						) }
						{ ! setup.disabled && setup.hq.can_change && (
							<>
								<div className="wproadie-inline-form">
									<TextControl
										__nextHasNoMarginBottom
										type="url"
										label={ __( 'HQ address', 'wproadie' ) }
										placeholder="https://hq.example.com"
										value={ hqUrl }
										onChange={ setHqUrl }
									/>
									<Button
										variant="primary"
										isBusy={ busy === 'pair' }
										disabled={ !! busy || ! hqUrl.trim() }
										onClick={ pair }
									>
										{ __( 'Connect to HQ', 'wproadie' ) }
									</Button>
								</div>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Use a connection string instead',
										'wproadie'
									) }
									checked={ manual }
									onChange={ setManual }
								/>
								{ manual && (
									<ManualConnect onConnected={ onChanged } />
								) }
							</>
						) }
					</Step>
					<Step
						number={ 3 }
						title={ __( 'Give yourself AI access', 'wproadie' ) }
						done={ setup.access.has_access }
					>
						<p>
							{ __(
								'Being an administrator is not enough on its own: each person who uses AI on this site is added by name. Connecting to HQ above does this for you.',
								'wproadie'
							) }
						</p>
						{ setup.access.can_grant && (
							<Button
								variant="secondary"
								isBusy={ busy === 'grant' }
								disabled={ !! busy }
								onClick={ grant }
							>
								{ __( 'Give me access', 'wproadie' ) }
							</Button>
						) }
					</Step>
				</ol>
			</CardBody>
		</Card>
	);
}
