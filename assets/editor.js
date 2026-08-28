/**
 * Editor representation of the ai-canvas/content plumbing block: a card
 * explaining that the area is AI-controlled. The block renders index.html on
 * the front end; nothing here is editable.
 */
( function ( wp ) {
	var el = wp.element.createElement;

	wp.blocks.registerBlockType( 'ai-canvas/content', {
		title: 'AI Canvas content',
		icon: 'art',
		category: 'design',
		description: 'Served from this page’s AI-written index.html, style.css, and script.js.',
		supports: { inserter: false, html: false, multiple: false, lock: false },
		edit: function () {
			return el(
				'div',
				{
					style: {
						border: '2px dashed #949494',
						borderRadius: '8px',
						padding: '48px 32px',
						margin: '16px 0',
						textAlign: 'center',
						color: '#50575e',
						background: 'repeating-linear-gradient(45deg, #fafafa, #fafafa 12px, #f3f3f3 12px, #f3f3f3 24px)',
					},
				},
				el( 'div', { style: { fontSize: '32px', lineHeight: 1 } }, '🤖' ),
				el(
					'h3',
					{ style: { margin: '12px 0 4px', color: '#1e1e1e' } },
					'AI-controlled canvas'
				),
				el(
					'p',
					{ style: { margin: 0, maxWidth: '40em', marginLeft: 'auto', marginRight: 'auto' } },
					'This area is served from index.html, style.css, and script.js files written by an AI agent through the AI-Canvas MCP endpoint. Edit it via your connected agent — changes made in this editor do not appear on the page.'
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
