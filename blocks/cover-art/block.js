( function ( blocks, element, blockEditor, i18n ) {
	const el            = element.createElement;
	const useBlockProps = blockEditor.useBlockProps;
	const __            = i18n.__;

	blocks.registerBlockType( 'scrobbble/cover-art', {
		edit: ( props ) => {
			return el( 'div', useBlockProps(),
				el( blockEditor.BlockControls ),
				el( 'p', {}, __( 'Cover Art', 'scrobbble' ) )
			);
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
