/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	Badge,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { runAbility } from './api';

const noop = () => {};

export function SharedLinkPageEditor( {
	identityType,
	identityId,
	identityName,
	initialStatus,
	onDirtyChange,
} ) {
	const dirtyChange = onDirtyChange || noop;
	const mountId = `ec-events-link-page-${ identityType }-${ identityId }`;
	useEffect( () => {
		const runtime = window.ExtraChillLinkPageEditor;
		const target = document.getElementById( mountId );
		if ( ! runtime?.mount || ! target ) {
			return undefined;
		}
		const field = `${ identityType }_term_id`;
		const adapterKey = `extrachill-events-link-pages-${ identityType }-${ identityId }`;
		const mapDocument = ( document ) => ( {
			identity: {
				id: identityId,
				name: document[ identityType ]?.title || identityName,
				image_url: document[ identityType ]?.snapshot?.image_url || '',
			},
			link_page: document.link_page,
			socials: document[ identityType ]?.snapshot?.social_links || [],
		} );
		window.ecLinkPageEditorAdapters[ adapterKey ] = {
			read: async () =>
				mapDocument(
					await runAbility(
						`extrachill/get-${ identityType }-link-page`,
						{
							[ field ]: identityId,
						}
					)
				),
			provision: async () =>
				mapDocument(
					await runAbility(
						`extrachill/provision-${ identityType }-link-page`,
						{
							[ field ]: identityId,
						}
					)
				),
			save: async ( ignored, draft ) => {
				const settings = {};
				[
					'link_expiration_enabled',
					'redirect_enabled',
					'redirect_target_url',
					'youtube_embed_enabled',
					'meta_pixel_id',
					'google_tag_id',
					'google_tag_manager_id',
					'social_icons_position',
					'profile_image_shape',
				].forEach( ( key ) => {
					if (
						Object.prototype.hasOwnProperty.call(
							draft.page.settings,
							key
						)
					) {
						settings[ key ] = draft.page.settings[ key ];
					}
				} );
				const document = await runAbility(
					`extrachill/save-${ identityType }-link-page`,
					{
						[ field ]: identityId,
						links: draft.page.links,
						css_vars: draft.page.styles,
						settings,
						bio: draft.page.bio,
						background_image_id: draft.page.backgroundImageId || 0,
					}
				);
				return mapDocument( document );
			},
			onDirtyChange: dirtyChange,
		};
		const unmount = runtime.mount( target, {
			adapter: adapterKey,
			identities: [ { id: identityId, label: identityName } ],
			initialIdentity: identityId,
			status: initialStatus,
		} );
		return () => {
			unmount?.();
			delete window.ecLinkPageEditorAdapters[ adapterKey ];
		};
	}, [
		dirtyChange,
		identityId,
		identityName,
		identityType,
		initialStatus,
		mountId,
	] );
	if ( ! window.ExtraChillLinkPageEditor?.mount ) {
		return (
			<InlineStatus tone="warning">
				Link Page management is unavailable.
			</InlineStatus>
		);
	}
	return <div id={ mountId } className="ec-events-link-page-editor" />;
}

export function ManagedIdentitySelector( {
	identities,
	selectedReference,
	onChange,
} ) {
	const venueIdentities = identities.filter(
		( item ) => item.type === 'venue'
	);
	const promoterIdentities = identities.filter(
		( item ) => item.type === 'promoter'
	);
	const selectedIsCurrent = identities.some(
		( identity ) => identity.reference === selectedReference
	);
	return (
		<section
			className="ec-managed-identity"
			aria-labelledby="ec-managed-identity-heading"
		>
			<div>
				<h2 id="ec-managed-identity-heading">Active identity</h2>
				<p>
					Choose who you are managing. This selection changes
					workspace context, not account permissions.
				</p>
			</div>
			<label htmlFor="ec-managed-identity-select">
				<span className="screen-reader-text">
					Active managed identity
				</span>
				<select
					id="ec-managed-identity-select"
					value={ selectedReference }
					onChange={ ( event ) => onChange( event.target.value ) }
				>
					<option value="">All managed venues</option>
					{ selectedReference && ! selectedIsCurrent && (
						<option value={ selectedReference } disabled>
							Unavailable selected identity
						</option>
					) }
					{ venueIdentities.length > 0 && (
						<optgroup label="Venue identities">
							{ venueIdentities.map( ( identity ) => (
								<option
									key={ identity.reference }
									value={ identity.reference }
								>
									{ identity.name }
								</option>
							) ) }
						</optgroup>
					) }
					{ promoterIdentities.length > 0 && (
						<optgroup label="Promoter organizations">
							{ promoterIdentities.map( ( identity ) => (
								<option
									key={ identity.reference }
									value={ identity.reference }
								>
									{ identity.name }
								</option>
							) ) }
						</optgroup>
					) }
				</select>
			</label>
			{ promoterIdentities.length === 0 && (
				<p className="ec-managed-identity__empty">
					No active promoter identities are available for this
					account.
				</p>
			) }
		</section>
	);
}

export function PromoterWorkspacePanel( { workspace, onLinkPageDirtyChange } ) {
	const { selection, promoter, granted_venues: venues } = workspace;
	if ( selection.state === 'stale' ) {
		return (
			<InlineStatus tone="warning" role="status">
				This managed identity is no longer active. Choose another
				identity.
			</InlineStatus>
		);
	}
	if ( selection.state === 'denied' ) {
		return (
			<InlineStatus tone="error" role="alert">
				You do not have access to the selected managed identity.
			</InlineStatus>
		);
	}
	if ( selection.state !== 'active' || ! promoter ) {
		return (
			<InlineStatus tone="info" role="status">
				Choose a promoter identity to open its workspace.
			</InlineStatus>
		);
	}
	return (
		<div
			className="ec-promoter-workspace"
			aria-labelledby="promoter-workspace-heading"
		>
			<Panel depth={ 2 }>
				<PanelHeader
					title={ promoter.name }
					description="Active verified promoter organization"
				/>
				<h1
					id="promoter-workspace-heading"
					className="screen-reader-text"
				>
					{ promoter.name } promoter workspace
				</h1>
				<p>
					<Badge tone="success" variant="solid">
						Active member
					</Badge>{ ' ' }
					{ promoter.is_owner && (
						<Badge tone="info" variant="solid">
							Organization owner
						</Badge>
					) }
				</p>
			</Panel>

			<section aria-labelledby="promoter-venues-heading">
				<h2 id="promoter-venues-heading">Granted venues</h2>
				{ venues.length === 0 ? (
					<InlineStatus tone="info" role="status">
						This promoter has no active venue grants.
					</InlineStatus>
				) : (
					<div className="ec-promoter-workspace__venues">
						{ venues.map( ( venue ) => (
							<Panel compact depth={ 2 } key={ venue.id }>
								<h3>{ venue.name }</h3>
								<p>
									<Badge tone="info" variant="solid">
										{ venue.action_label }
									</Badge>
								</p>
							</Panel>
						) ) }
					</div>
				) }
			</section>

			<SharedLinkPageEditor
				identityType="promoter"
				identityId={ promoter.id }
				identityName={ promoter.name }
				initialStatus={ promoter.link_page.status }
				onDirtyChange={ onLinkPageDirtyChange }
			/>

			<InlineStatus tone="info">
				Promoter access is limited to organizing local support at the
				venues shown here. Venue settings, private booking data, team
				access, and finances remain venue-owned.
			</InlineStatus>
		</div>
	);
}

export function VenuePromoterRelationships( { relationships } ) {
	return (
		<section
			className="ec-venue-collaborations"
			aria-labelledby="venue-promoter-collaborations-heading"
		>
			<h2 id="venue-promoter-collaborations-heading">
				Promoter collaborations
			</h2>
			<p>Read-only delegated relationships for this venue.</p>
			{ relationships.length === 0 ? (
				<p>
					No promoter relationships have been created for this venue.
				</p>
			) : (
				<ul className="ec-venue-settings__records">
					{ relationships.map( ( relationship ) => (
						<li
							key={ `${ relationship.promoter_term_id }:${ relationship.action }` }
						>
							<span>
								<strong>{ relationship.promoter_name }</strong>{ ' ' }
								{ relationship.action_label }
							</span>
							<Badge
								tone={
									relationship.status === 'active'
										? 'success'
										: 'warning'
								}
								variant="solid"
							>
								{ relationship.status }
							</Badge>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
