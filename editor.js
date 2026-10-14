/* WPU SEO: the "SEO" panel in the editor sidebar. Plain JS, no build step. */
( function ( wp ) {
	var el = wp.element.createElement;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var c = wp.components;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var types = ( window.wpuSeo && window.wpuSeo.types ) || [];

	if ( ! Panel ) {
		return;
	}

	// Rough limits for what Google usually shows; a guide, not a rule.
	function counter( text, max ) {
		var n = ( text || '' ).length;
		return n + ' / ' + max + ' tecken' + ( n > max ? ' – kan kortas av i Google' : '' );
	}

	function SeoPanel() {
		var postType = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var entity = wp.coreData.useEntityProp( 'postType', postType, 'meta' );
		var meta = entity[ 0 ] || {};
		var setMeta = entity[ 1 ];
		var imageId = meta._wpu_seo_image || 0;
		var image = wp.data.useSelect( function ( select ) {
			return imageId ? select( 'core' ).getMedia( imageId ) : null;
		}, [ imageId ] );

		if ( types.indexOf( postType ) === -1 ) {
			return null;
		}

		function set( key ) {
			return function ( value ) {
				var change = {};
				change[ key ] = value;
				setMeta( Object.assign( {}, meta, change ) );
			};
		}

		return el(
			Panel,
			{ name: 'wpu-seo', title: 'SEO' },
			el( c.TextControl, {
				label: 'SEO-titel',
				value: meta._wpu_seo_title || '',
				onChange: set( '_wpu_seo_title' ),
				help: 'Tomt = rubriken och sajtens namn. ' + counter( meta._wpu_seo_title, 60 ),
				__nextHasNoMarginBottom: true,
			} ),
			el( 'div', { style: { height: 12 } } ),
			el( c.TextareaControl, {
				label: 'Metabeskrivning',
				value: meta._wpu_seo_description || '',
				onChange: set( '_wpu_seo_description' ),
				rows: 4,
				help: 'Tomt = utdraget. ' + counter( meta._wpu_seo_description, 155 ),
				__nextHasNoMarginBottom: true,
			} ),
			el( 'p', { style: { fontWeight: 500, margin: '12px 0 6px' } }, 'Delningsbild' ),
			image && image.source_url ? el( 'img', { src: image.source_url, alt: '', style: { width: '100%', height: 'auto', borderRadius: 2 } } ) : null,
			el( MediaUploadCheck, null,
				el( MediaUpload, {
					onSelect: function ( media ) { set( '_wpu_seo_image' )( media.id ); },
					allowedTypes: [ 'image' ],
					value: imageId,
					render: function ( o ) {
						return el( 'div', { style: { display: 'flex', gap: 8, marginTop: 6 } },
							el( c.Button, { variant: 'secondary', size: 'compact', onClick: o.open }, imageId ? 'Byt bild' : 'Välj bild' ),
							imageId ? el( c.Button, { variant: 'tertiary', size: 'compact', isDestructive: true, onClick: function () { set( '_wpu_seo_image' )( 0 ); } }, 'Ta bort' ) : null
						);
					},
				} )
			),
			el( 'p', { style: { fontSize: 12, opacity: 0.75, margin: '6px 0 12px' } }, 'Tomt = utvald bild. Visas när sidan delas på LinkedIn, Facebook m.fl. Bäst 1200 × 630 px.' ),
			el( c.ToggleControl, {
				label: 'Dölj för sökmotorer (noindex)',
				checked: !! meta._wpu_seo_noindex,
				onChange: set( '_wpu_seo_noindex' ),
				help: 'Sidan tas bort ur Google och ur webbplatskartan.',
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	wp.plugins.registerPlugin( 'wpu-seo', { render: SeoPanel, icon: 'search' } );
} )( window.wp );
