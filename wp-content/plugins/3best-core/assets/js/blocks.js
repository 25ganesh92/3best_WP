/**
 * Editor registration for the 3Best dynamic blocks.
 *
 * Deliberately plain ES5 with wp.element.createElement rather than JSX, so the
 * plugin needs no build step and stays installable by copying a directory.
 *
 * Every block here is SERVER RENDERED: save() returns null and the real markup
 * comes from the PHP render callback, which calls the renderer that already
 * existed. The editor shows a labelled placeholder rather than a live preview,
 * because these components depend on request context (a resolved service and
 * location, a nonce, the throttle) that the editor does not have.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var data = window.threebestBlockData || { services: [] };

	/**
	 * The labelled placeholder every block shows in the editor.
	 *
	 * @param {string} title Block name.
	 * @param {string} note  What it will render on the front end.
	 * @param {Array}  extra Additional children.
	 */
	function placeholder( title, note, extra ) {
		var children = [
			el( 'strong', { key: 't', style: { display: 'block', marginBottom: '4px' } }, title ),
			el( 'span', { key: 'n', style: { color: '#64748b', fontSize: '13px' } }, note )
		];

		if ( extra ) {
			children = children.concat( extra );
		}

		return el(
			'div',
			{
				style: {
					border: '1px dashed #cbd5e1',
					borderRadius: '8px',
					padding: '18px 20px',
					background: '#f8fafc',
					fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
				}
			},
			children
		);
	}

	/**
	 * Registers a server-rendered block whose editor view is a placeholder.
	 *
	 * @param {string}   name     Block name.
	 * @param {string}   title    Human title.
	 * @param {string}   note     Editor explanation.
	 * @param {Function} controls Optional inspector controls factory.
	 */
	function registerPlaceholderBlock( name, title, note, controls ) {
		blocks.registerBlockType( name, {
			edit: function ( props ) {
				var inspector = controls ? controls( props ) : null;

				return el(
					'div',
					useBlockProps(),
					inspector,
					placeholder( title, note )
				);
			},
			save: function () {
				return null;
			}
		} );
	}

	/* ---- Component blocks ------------------------------------------- */

	registerPlaceholderBlock(
		'threebest/search-form',
		__( '3Best search form', '3best-core' ),
		__( 'The service and location search renders here.', '3best-core' ),
		function ( props ) {
			return el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Search form', '3best-core' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Layout', '3best-core' ),
						value: props.attributes.context,
						options: [
							{ label: __( 'Homepage', '3best-core' ), value: 'home' },
							{ label: __( 'Services page', '3best-core' ), value: 'services' }
						],
						onChange: function ( value ) {
							props.setAttributes( { context: value } );
						}
					} )
				)
			);
		}
	);

	registerPlaceholderBlock(
		'threebest/lead-form',
		__( '3Best lead form', '3best-core' ),
		__( 'The request form, with consent and phone verification. On a service/location page it uses that page automatically.', '3best-core' ),
		function ( props ) {
			var options = [ { label: __( '— use the page —', '3best-core' ), value: '' } ];

			( data.services || [] ).forEach( function ( service ) {
				options.push( { label: service.name, value: service.slug } );
			} );

			return el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Lead form', '3best-core' ), initialOpen: true },
					el( 'p', { style: { color: '#64748b', fontSize: '12px' } },
						__( 'Leave both empty on a service/location page: the page supplies them.', '3best-core' ) ),
					el( SelectControl, {
						label: __( 'Service', '3best-core' ),
						value: props.attributes.service,
						options: options,
						onChange: function ( value ) {
							props.setAttributes( { service: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Location slug', '3best-core' ),
						help: __( 'For example newark-nj.', '3best-core' ),
						value: props.attributes.location,
						onChange: function ( value ) {
							props.setAttributes( { location: value } );
						}
					} )
				)
			);
		}
	);

	registerPlaceholderBlock(
		'threebest/services-catalogue',
		__( '3Best services catalogue', '3best-core' ),
		__( 'Every service category and the services inside it.', '3best-core' )
	);

	registerPlaceholderBlock(
		'threebest/media',
		__( '3Best image', '3best-core' ),
		__( 'A bundled theme image.', '3best-core' ),
		function ( props ) {
			return el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Image', '3best-core' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Image key', '3best-core' ),
						help: __( 'For example page-about, or category-home-services.', '3best-core' ),
						value: props.attributes.imageKey,
						onChange: function ( value ) { props.setAttributes( { imageKey: value } ); }
					} ),
					el( SelectControl, {
						label: __( 'Ratio', '3best-core' ),
						value: props.attributes.ratio,
						options: [
							{ label: __( 'Landscape', '3best-core' ), value: 'landscape' },
							{ label: __( 'Square', '3best-core' ), value: 'square' },
							{ label: __( 'Portrait', '3best-core' ), value: 'portrait' }
						],
						onChange: function ( value ) { props.setAttributes( { ratio: value } ); }
					} )
				)
			);
		}
	);

	registerPlaceholderBlock(
		'threebest/hero-carousel',
		__( '3Best hero carousel', '3best-core' ),
		__( 'The rotating hero imagery.', '3best-core' )
	);

	registerPlaceholderBlock(
		'threebest/category-cards',
		__( '3Best category cards', '3best-core' ),
		__( 'The four service categories as linked cards.', '3best-core' )
	);

	registerPlaceholderBlock(
		'threebest/icon-list',
		__( '3Best icon list', '3best-core' ),
		__( 'A checked list. One item per line.', '3best-core' ),
		function ( props ) {
			return el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'List', '3best-core' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Style', '3best-core' ),
						value: props.attributes.variant,
						options: [
							{ label: __( 'Reassurance ticks', '3best-core' ), value: 'reassurance' },
							{ label: __( 'Numbered steps', '3best-core' ), value: 'steps' },
							{ label: __( 'Numbered process list', '3best-core' ), value: 'process' },
							{ label: __( 'Value points', '3best-core' ), value: 'value' }
						],
						onChange: function ( value ) { props.setAttributes( { variant: value } ); }
					} ),
					el( TextareaControl, {
						label: __( 'Items', '3best-core' ),
						help: __( 'One per line. For steps and value points use: Title | description', '3best-core' ),
						rows: 8,
						value: props.attributes.items,
						onChange: function ( value ) { props.setAttributes( { items: value } ); }
					} )
				)
			);
		}
	);

	/* ---- Landing-page blocks ---------------------------------------- */

	var landing = [
		[ 'threebest/landing-hero', __( '3Best landing hero', '3best-core' ), __( 'Badges, headline and intro for the resolved service and location.', '3best-core' ) ],
		[ 'threebest/landing-subcategories', __( '3Best landing service picker', '3best-core' ), __( 'The four subcategories of the resolved service.', '3best-core' ) ],
		[ 'threebest/landing-benefits', __( '3Best landing benefits', '3best-core' ), __( 'Generated reasons to use 3Best for this service.', '3best-core' ) ],
		[ 'threebest/landing-body', __( '3Best landing body copy', '3best-core' ), __( 'Generated paragraphs plus links to sibling services.', '3best-core' ) ],
		[ 'threebest/landing-faq', __( '3Best landing FAQ', '3best-core' ), __( 'Generated questions and answers.', '3best-core' ) ]
	];

	landing.forEach( function ( spec ) {
		registerPlaceholderBlock( spec[ 0 ], spec[ 1 ], spec[ 2 ] );
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
) );
