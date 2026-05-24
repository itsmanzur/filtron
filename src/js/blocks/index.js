/**
 * Filtron Gutenberg blocks (editor script).
 *
 * @package Filtron
 */

( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice = wp.components.Notice;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;

	/**
	 * Resolve selected Filtron container group post_type for current child block.
	 *
	 * @param {string} clientId Current block client id.
	 * @returns {string}
	 */
	function resolveGroupPostType( clientId ) {
		var resolved = resolveContainerGroup( clientId );
		if ( resolved && resolved.postType ) {
			return resolved.postType;
		}
		return 'post';
	}

	/**
	 * Resolve parent Filtron container context for current child block.
	 *
	 * @param {string} clientId Current block client id.
	 * @returns {{ groupId: number, postType: string, insideContainer: boolean }}
	 */
	function resolveContainerGroup( clientId ) {
		if ( ! clientId || ! wp.data || ! wp.data.select ) {
			return { groupId: 0, postType: 'post', insideContainer: false };
		}

		var be = wp.data.select( 'core/block-editor' );
		if ( ! be || ! be.getBlockParents || ! be.getBlock ) {
			return { groupId: 0, postType: 'post', insideContainer: false };
		}

		var parentIds = be.getBlockParents( clientId ) || [];
		if ( ! parentIds.length ) {
			return { groupId: 0, postType: 'post', insideContainer: false };
		}

		var containerGroupId = 0;
		var insideContainer = false;
		parentIds.some( function ( parentId ) {
			var blk = be.getBlock( parentId );
			if ( blk && blk.name === 'filtron/container' ) {
				insideContainer = true;
				containerGroupId = parseInt( blk.attributes && blk.attributes.groupId, 10 ) || 0;
				return true;
			}
			return false;
		} );

		if ( containerGroupId < 1 || ! Array.isArray( groupsData ) ) {
			return { groupId: containerGroupId, postType: 'post', insideContainer: insideContainer };
		}

		var groupRow = groupsData.find( function ( g ) {
			return parseInt( g && g.id, 10 ) === containerGroupId;
		} );

		return {
			groupId: containerGroupId,
			postType: ( groupRow && groupRow.post_type ) ? groupRow.post_type : 'post',
			insideContainer: insideContainer,
		};
	}

	/**
	 * Inspector for checkbox / range / search / select.
	 *
	 * @param {Object} props          Block props.
	 * @param {string} props.filterType checkbox|range|search|select
	 */
	function FiltronFilterInspector( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var context = props.context || {};
		var containerContext = resolveContainerGroup( props.clientId );
		var postType = resolveGroupPostType( props.clientId ) || context.postType || 'post';
		var filterType = props.filterType || 'checkbox';
		var hasValidGroup = containerContext.insideContainer && containerContext.groupId > 0;

		var keysState = useState( [] );
		var keyList = keysState[ 0 ];
		var setKeyList = keysState[ 1 ];

		var loadState = useState( false );
		var isLoading = loadState[ 0 ];
		var setLoading = loadState[ 1 ];

		useEffect(
			function () {
				if ( ! attributes.sourceType ) {
					return;
				}
				setLoading( true );
				var path =
					'/filtron/v1/editor-source-keys?post_type=' +
					encodeURIComponent( postType ) +
					'&source_type=' +
					encodeURIComponent( attributes.sourceType );
				apiFetch( { path: path } )
					.then( function ( res ) {
						setKeyList( res.keys || [] );
					} )
					.catch( function () {
						setKeyList( [] );
					} )
					.finally( function () {
						setLoading( false );
					} );
			},
			[ postType, attributes.sourceType ]
		);

		var keyOptions = [
			{
				label: isLoading ? __( 'Loading…', 'filtron' ) : __( 'Select key…', 'filtron' ),
				value: '',
			},
		].concat(
			keyList.map( function ( k ) {
				return { label: k, value: k };
			} )
		);

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Filter', 'filtron' ), initialOpen: true },
				el( TextControl, {
					label: __( 'Filter label', 'filtron' ),
					value: attributes.label,
					onChange: function ( v ) {
						setAttributes( { label: v } );
					},
				} ),
				el( SelectControl, {
					label: __( 'Source type', 'filtron' ),
					value: attributes.sourceType,
					options: [
						{ label: __( 'Taxonomy', 'filtron' ), value: 'taxonomy' },
						{ label: __( 'Meta', 'filtron' ), value: 'meta' },
					],
					onChange: function ( v ) {
						setAttributes( { sourceType: v, sourceKey: '' } );
					},
				} ),
				el( SelectControl, {
					label: __( 'Source key', 'filtron' ),
					value: attributes.sourceKey,
					options: keyOptions,
					onChange: function ( v ) {
						setAttributes( { sourceKey: v } );
					},
					disabled: isLoading || ! hasValidGroup,
				} ),
				! containerContext.insideContainer &&
					el( Notice, { status: 'warning', isDismissible: false }, __( 'Place this filter block inside a Filtron Container block.', 'filtron' ) ),
				containerContext.insideContainer &&
					! hasValidGroup &&
					el( Notice, { status: 'warning', isDismissible: false }, __( 'Select a filter group in the parent Filtron Container first.', 'filtron' ) ),
				hasValidGroup &&
					! isLoading &&
					attributes.sourceType &&
					0 === keyList.length &&
					el( Notice, { status: 'info', isDismissible: false }, __( 'No keys found for this source type and group post type.', 'filtron' ) ),
				el( ToggleControl, {
					label: __( 'Show counts', 'filtron' ),
					help:
						filterType === 'checkbox' || filterType === 'select'
							? ''
							: __( 'Reserved for checkbox and select filters; no effect on this block yet.', 'filtron' ),
					checked: !! attributes.showCount,
					onChange: function ( v ) {
						setAttributes( { showCount: v } );
					},
				} ),
				filterType === 'select' &&
					el( TextControl, {
						label: __( 'Placeholder', 'filtron' ),
						value: attributes.placeholder || '',
						placeholder: __( 'Any', 'filtron' ),
						onChange: function ( v ) {
							setAttributes( { placeholder: v } );
						},
					} ),
				filterType === 'checkbox' &&
					el( SelectControl, {
						label: __( 'Logic', 'filtron' ),
						value: attributes.logic === 'AND' ? 'AND' : 'OR',
						options: [
							{ label: __( 'OR (any)', 'filtron' ), value: 'OR' },
							{ label: __( 'AND (all)', 'filtron' ), value: 'AND' },
						],
						onChange: function ( v ) {
							setAttributes( { logic: v } );
						},
					} )
			)
		);
	}

	/**
	 * Checkbox preview: load distinct values from Filtron index (REST).
	 *
	 * @param {Object} p Block props subset + blockProps + titleFallback.
	 */
	function FiltronCheckboxFacetPreview( p ) {
		var attributes = p.attributes;
		var blockProps = p.blockProps;
		var titleFallback = p.titleFallback;
		var clientId = p.clientId;

		var postType = resolveGroupPostType( clientId );

		var facetState = useState( { status: 'loading', items: [] } );
		var facet = facetState[ 0 ];
		var setFacet = facetState[ 1 ];

		useEffect(
			function () {
				if ( ! attributes.sourceKey || ! attributes.sourceType ) {
					setFacet( { status: 'empty', items: [] } );
					return;
				}
				setFacet( { status: 'loading', items: [] } );
				var path =
					'/filtron/v1/editor-facet-preview?post_type=' +
					encodeURIComponent( postType ) +
					'&source_type=' +
					encodeURIComponent( attributes.sourceType ) +
					'&source_key=' +
					encodeURIComponent( attributes.sourceKey ) +
					'&limit=30';
				var cancelled = false;
				apiFetch( { path: path } )
					.then( function ( res ) {
						if ( cancelled ) {
							return;
						}
						var items = res && res.items ? res.items : [];
						setFacet( {
							status: items.length ? 'ready' : 'empty',
							items: items,
						} );
					} )
					.catch( function () {
						if ( cancelled ) {
							return;
						}
						setFacet( { status: 'error', items: [] } );
					} );
				return function () {
					cancelled = true;
				};
			},
			[ postType, attributes.sourceType, attributes.sourceKey ]
		);

		var label = attributes.label || titleFallback;
		var sourceRow = el(
			'div',
			{ className: 'filtron-editor-preview__source' },
			el( 'span', { className: 'filtron-editor-preview__tag' }, attributes.sourceType ),
			' ',
			attributes.sourceKey
		);

		var listContent;
		if ( facet.status === 'loading' ) {
			listContent = el(
				'ul',
				{ className: 'filtron-editor-preview__options' },
				el(
					'li',
					{ className: 'filtron-editor-preview__option filtron-editor-preview__option--muted' },
					__( 'Loading options…', 'filtron' )
				)
			);
		} else if ( facet.status === 'error' ) {
			listContent = el(
				'p',
				{ className: 'filtron-editor-preview__hint' },
				__( 'Could not load preview values.', 'filtron' )
			);
		} else if ( facet.status === 'empty' || ! facet.items.length ) {
			listContent = el(
				'p',
				{ className: 'filtron-editor-preview__hint' },
				__( 'No indexed values for this key yet. Rebuild the Filtron index if you expect data here.', 'filtron' )
			);
		} else {
			listContent = el(
				'ul',
				{ className: 'filtron-editor-preview__options' },
				facet.items.map( function ( row, i ) {
					return el(
						'li',
						{ key: 'f' + i, className: 'filtron-editor-preview__option' },
						el( 'input', { type: 'checkbox', tabIndex: -1, disabled: true, defaultChecked: i === 0 } ),
						el( 'span', null, row.label || row.value ),
						attributes.showCount && typeof row.count !== 'undefined'
							? el( 'span', { className: 'filtron-editor-preview__count' }, '(' + row.count + ')' )
							: null
					);
				} )
			);
		}

		return el(
			'div',
			blockProps,
			el(
				'div',
				{ className: 'filtron-editor-preview filtron-editor-preview--checkbox' },
				el(
					'div',
					{ className: 'filtron-editor-preview__head' },
					el( 'span', { className: 'filtron-editor-preview__title' }, label ),
					el(
						'span',
						{ className: 'filtron-editor-preview__badge' },
						attributes.logic === 'AND' ? __( 'AND', 'filtron' ) : __( 'OR', 'filtron' )
					)
				),
				listContent,
				sourceRow
			)
		);
	}

	/**
	 * Select preview: load distinct values from Filtron index (REST).
	 *
	 * @param {Object} p Block props subset + blockProps + titleFallback.
	 */
	function FiltronSelectFacetPreview( p ) {
		var attributes = p.attributes;
		var blockProps = p.blockProps;
		var titleFallback = p.titleFallback;
		var clientId = p.clientId;

		var postType = resolveGroupPostType( clientId );

		var facetState = useState( { status: 'loading', items: [] } );
		var facet = facetState[ 0 ];
		var setFacet = facetState[ 1 ];

		useEffect(
			function () {
				if ( ! attributes.sourceKey || ! attributes.sourceType ) {
					setFacet( { status: 'empty', items: [] } );
					return;
				}
				setFacet( { status: 'loading', items: [] } );
				var path =
					'/filtron/v1/editor-facet-preview?post_type=' +
					encodeURIComponent( postType ) +
					'&source_type=' +
					encodeURIComponent( attributes.sourceType ) +
					'&source_key=' +
					encodeURIComponent( attributes.sourceKey ) +
					'&limit=30';
				var cancelled = false;
				apiFetch( { path: path } )
					.then( function ( res ) {
						if ( cancelled ) {
							return;
						}
						var items = res && res.items ? res.items : [];
						setFacet( {
							status: items.length ? 'ready' : 'empty',
							items: items,
						} );
					} )
					.catch( function () {
						if ( cancelled ) {
							return;
						}
						setFacet( { status: 'error', items: [] } );
					} );
				return function () {
					cancelled = true;
				};
			},
			[ postType, attributes.sourceType, attributes.sourceKey ]
		);

		var label = attributes.label || titleFallback;
		var sourceRow = el(
			'div',
			{ className: 'filtron-editor-preview__source' },
			el( 'span', { className: 'filtron-editor-preview__tag' }, attributes.sourceType ),
			' ',
			attributes.sourceKey
		);

		var selectContent;
		if ( facet.status === 'loading' ) {
			selectContent = el(
				'p',
				{ className: 'filtron-editor-preview__hint' },
				__( 'Loading options...', 'filtron' )
			);
		} else if ( facet.status === 'error' ) {
			selectContent = el(
				'p',
				{ className: 'filtron-editor-preview__hint' },
				__( 'Could not load preview values.', 'filtron' )
			);
		} else if ( facet.status === 'empty' || ! facet.items.length ) {
			selectContent = el(
				'p',
				{ className: 'filtron-editor-preview__hint' },
				__( 'No indexed values for this key yet. Rebuild the Filtron index if you expect data here.', 'filtron' )
			);
		} else {
			selectContent = el(
				'select',
				{
					className: 'filtron-editor-preview__select',
					disabled: true,
					'aria-label': __( 'Select field preview', 'filtron' ),
				},
				el( 'option', { value: '' }, attributes.placeholder || __( 'Any', 'filtron' ) ),
				facet.items.map( function ( row, i ) {
					var optionLabel = row.label || row.value;
					if ( attributes.showCount && typeof row.count !== 'undefined' ) {
						optionLabel += ' (' + row.count + ')';
					}
					return el( 'option', { key: 's' + i, value: row.value }, optionLabel );
				} )
			);
		}

		return el(
			'div',
			blockProps,
			el(
				'div',
				{ className: 'filtron-editor-preview filtron-editor-preview--select' },
				el(
					'div',
					{ className: 'filtron-editor-preview__head' },
					el( 'span', { className: 'filtron-editor-preview__title' }, label ),
					el( 'span', { className: 'filtron-editor-preview__badge' }, __( 'Select', 'filtron' ) )
				),
				selectContent,
				sourceRow
			)
		);
	}

	/**
	 * In-canvas preview (styled like the storefront widget; checkbox/select use live index data).
	 *
	 * @param {Object} props          Block props.
	 * @param {string} titleFallback Default title when label empty.
	 * @param {string} filterType    checkbox|range|search|select
	 */
	function filtronFilterPreview( props, titleFallback, filterType ) {
		var attributes = props.attributes;
		var blockProps = useBlockProps( { className: 'filtron-block-preview' } );
		var containerContext = resolveContainerGroup( props.clientId );
		var wrapPlaceholder = function ( message ) {
			return el(
				'div',
				blockProps,
				el(
					'div',
					{ className: 'filtron-editor-preview filtron-editor-preview--placeholder' },
					el( 'p', { className: 'filtron-editor-preview__hint' }, message )
				)
			);
		};
		if ( ! containerContext.insideContainer ) {
			return wrapPlaceholder( __( 'Move this block inside a Filtron Container.', 'filtron' ) );
		}
		if ( containerContext.groupId < 1 ) {
			return wrapPlaceholder( __( 'Select a filter group in the parent container.', 'filtron' ) );
		}
		if ( ! attributes.sourceKey ) {
			return wrapPlaceholder( __( 'Pick a source key in the sidebar (Filter → Source key).', 'filtron' ) );
		}

		var label = attributes.label || titleFallback;
		var sourceRow = el(
			'div',
			{ className: 'filtron-editor-preview__source' },
			el( 'span', { className: 'filtron-editor-preview__tag' }, attributes.sourceType ),
			' ',
			attributes.sourceKey
		);

		if ( filterType === 'range' ) {
			return el(
				'div',
				blockProps,
				el(
					'div',
					{ className: 'filtron-editor-preview filtron-editor-preview--range' },
					el(
						'div',
						{ className: 'filtron-editor-preview__head' },
						el( 'span', { className: 'filtron-editor-preview__title' }, label ),
						el( 'span', { className: 'filtron-editor-preview__badge' }, __( 'Range', 'filtron' ) )
					),
					el(
						'div',
						{ className: 'filtron-editor-preview__readout' },
						el( 'span', null, __( 'Min', 'filtron' ) ),
						el( 'span', null, __( 'Max', 'filtron' ) )
					),
					el( 'div', { className: 'filtron-editor-preview__track', 'aria-hidden': 'true' } ),
					sourceRow
				)
			);
		}

		if ( filterType === 'search' ) {
			return el(
				'div',
				blockProps,
				el(
					'div',
					{ className: 'filtron-editor-preview filtron-editor-preview--search' },
					el(
						'div',
						{ className: 'filtron-editor-preview__head' },
						el( 'span', { className: 'filtron-editor-preview__title' }, label ),
						el( 'span', { className: 'filtron-editor-preview__badge' }, __( 'Search', 'filtron' ) )
					),
					el( 'input', {
						type: 'text',
						className: 'filtron-editor-preview__input',
						readOnly: true,
						defaultValue: '',
						placeholder: __( 'Visitors type here to search…', 'filtron' ),
						'aria-label': __( 'Search field preview', 'filtron' ),
					} ),
					sourceRow
				)
			);
		}

		if ( filterType === 'select' ) {
			return el( FiltronSelectFacetPreview, {
				attributes: attributes,
				blockProps: blockProps,
				titleFallback: titleFallback,
				clientId: props.clientId,
			} );
		}

		/* checkbox (default): live facet values from index */
		return el( FiltronCheckboxFacetPreview, {
			attributes: attributes,
			blockProps: blockProps,
			titleFallback: titleFallback,
			clientId: props.clientId,
		} );
	}

	var groupsData =
		typeof filtronBlocksData !== 'undefined' && filtronBlocksData.groups
			? filtronBlocksData.groups
			: [];

	registerBlockType( 'filtron/container', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'filtron-container-block' } );
			var groupOptions = [
				{ label: __( '— Select group —', 'filtron' ), value: '0' },
			].concat(
				groupsData.map( function ( g ) {
					return {
						label: g.name + ' (' + g.post_type + ')',
						value: String( g.id ),
					};
				} )
			);
			var gid = parseInt( attributes.groupId, 10 ) || 0;
			var activeGroup = groupsData.find( function ( g ) {
				return parseInt( g && g.id, 10 ) === gid;
			} );
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Filtron group', 'filtron' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Filter group', 'filtron' ),
							value: String( attributes.groupId || 0 ),
							options: groupOptions,
							onChange: function ( v ) {
								setAttributes( { groupId: parseInt( v, 10 ) || 0 } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					gid > 0 &&
						activeGroup &&
						el(
							'div',
							{ className: 'filtron-editor-preview filtron-editor-preview--container-bar' },
							el( 'span', { className: 'filtron-editor-preview__title' }, __( 'Filtron container', 'filtron' ) ),
							el(
								'span',
								{ className: 'filtron-editor-preview__badge' },
								activeGroup.name + ' · ' + activeGroup.post_type
							)
						),
					( ! attributes.groupId || parseInt( attributes.groupId, 10 ) < 1 ) &&
						el(
							'p',
							{ className: 'filtron-block-placeholder' },
							__( 'Select a filter group from the block sidebar to activate this container.', 'filtron' )
						),
					el( InnerBlocks, {
						allowedBlocks: [ 'filtron/checkbox', 'filtron/range', 'filtron/search', 'filtron/select' ],
						renderAppender:
							InnerBlocks.ButtonBlockAppender || InnerBlocks.DefaultBlockAppender,
					} )
				)
			);
		},
		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );

	registerBlockType( 'filtron/checkbox', {
		edit: function ( props ) {
			return el(
				Fragment,
				null,
				el( FiltronFilterInspector, Object.assign( {}, props, { filterType: 'checkbox' } ) ),
				filtronFilterPreview( props, __( 'Checkbox filter', 'filtron' ), 'checkbox' )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'filtron/range', {
		edit: function ( props ) {
			return el(
				Fragment,
				null,
				el( FiltronFilterInspector, Object.assign( {}, props, { filterType: 'range' } ) ),
				filtronFilterPreview( props, __( 'Range filter', 'filtron' ), 'range' )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'filtron/search', {
		edit: function ( props ) {
			return el(
				Fragment,
				null,
				el( FiltronFilterInspector, Object.assign( {}, props, { filterType: 'search' } ) ),
				filtronFilterPreview( props, __( 'Search filter', 'filtron' ), 'search' )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'filtron/select', {
		edit: function ( props ) {
			return el(
				Fragment,
				null,
				el( FiltronFilterInspector, Object.assign( {}, props, { filterType: 'select' } ) ),
				filtronFilterPreview( props, __( 'Select filter', 'filtron' ), 'select' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
