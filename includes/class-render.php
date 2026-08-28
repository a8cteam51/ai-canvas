<?php
/**
 * Front-end rendering: the block template, the internal content block, and asset enqueues.
 *
 * @package Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

class AI_Canvas_Render {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_notice' ) );
		// Registry-provided templates come back keyed by name ("ai-canvas//canvas"),
		// but core reads $templates[0] (e.g. wp_get_post_content_block_attributes()),
		// spraying warnings on the edit screen. Normalize to the documented list shape.
		add_filter( 'get_block_templates', fn( $templates ) => array_values( (array) $templates ) );
		add_filter( 'block_editor_settings_all', array( __CLASS__, 'lock_canvas_editor' ), 10, 2 );
	}

	/**
	 * Canvas post content is exactly one ai-canvas/content block (seeded by
	 * create-canvas); lock the editor so it can't be removed or added to.
	 */
	public static function lock_canvas_editor( array $settings, $context ): array {
		if ( ! empty( $context->post ) && AI_Canvas_Files::is_canvas( $context->post->ID ) ) {
			$settings['template']     = array( array( 'ai-canvas/content' ) );
			$settings['templateLock'] = 'all';
		}
		return $settings;
	}

	public static function register(): void {
		// core/template-part blocks (not PHP block_template_part()) so the theme's
		// wrapper element and attributes are preserved; core injects the theme attribute.
		register_block_template(
			'ai-canvas//' . AI_Canvas_Files::TEMPLATE_SLUG,
			array(
				'title'       => __( 'AI Canvas', 'ai-canvas' ),
				'description' => __( 'Theme header and footer around an AI-written HTML/CSS/JS canvas.', 'ai-canvas' ),
				'post_types'  => array( 'page', 'post' ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:ai-canvas/content /-->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);

		// No template parts at all: the canvas supplies its own header/footer.
		// wp_head/wp_footer still fire, so style.css/script.js enqueues work.
		register_block_template(
			'ai-canvas//' . AI_Canvas_Files::TEMPLATE_SLUG_BLANK,
			array(
				'title'       => __( 'AI Canvas (Blank)', 'ai-canvas' ),
				'description' => __( 'An AI-written HTML/CSS/JS canvas with no theme header or footer; the canvas controls the whole page.', 'ai-canvas' ),
				'post_types'  => array( 'page', 'post' ),
				'content'     => '<!-- wp:ai-canvas/content /-->',
			)
		);

		// Plumbing block: renders index.html on the front end; in editors it shows
		// an "AI-controlled" placeholder card (assets/editor.js). Not inserter-visible.
		wp_register_script(
			'ai-canvas-editor',
			plugins_url( 'assets/editor.js', AI_CANVAS_FILE ),
			array( 'wp-blocks', 'wp-element' ),
			AI_CANVAS_VERSION,
			true
		);
		register_block_type(
			'ai-canvas/content',
			array(
				'api_version'           => 3,
				'render_callback'       => array( __CLASS__, 'render_content' ),
				'editor_script_handles' => array( 'ai-canvas-editor' ),
			)
		);
	}

	/**
	 * Pin a notice in the post editor when the open post is a canvas.
	 */
	public static function enqueue_editor_notice(): void {
		global $post;
		if ( ! $post || ! AI_Canvas_Files::is_canvas( $post->ID ) ) {
			return;
		}
		wp_register_script( 'ai-canvas-editor-notice', false, array( 'wp-data', 'wp-dom-ready', 'wp-notices' ), AI_CANVAS_VERSION, true );
		wp_enqueue_script( 'ai-canvas-editor-notice' );
		wp_add_inline_script(
			'ai-canvas-editor-notice',
			'wp.domReady( function () {
				wp.data.dispatch( "core/notices" ).createNotice(
					"warning",
					"' . esc_js( __( 'AI-controlled page: its content, styles, and scripts live in AI-Canvas files written by an agent over MCP. Nothing typed in this editor appears on the page.', 'ai-canvas' ) ) . '",
					{ id: "ai-canvas-notice", isDismissible: false }
				);
			} );'
		);
	}

	public static function render_content(): string {
		$post_id = get_the_ID();
		if ( ! $post_id || ! AI_Canvas_Files::is_canvas( $post_id ) ) {
			return '';
		}
		$html = AI_Canvas_Files::read( $post_id, 'html' );
		// Deliberately unescaped: AI-Canvas trusts canvas output by design (see README).
		return is_wp_error( $html ) ? '' : $html;
	}

	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! AI_Canvas_Files::is_canvas( $post_id ) ) {
			return;
		}

		$css = AI_Canvas_Files::path( $post_id, 'css' );
		if ( ! is_wp_error( $css ) && file_exists( $css ) ) {
			wp_enqueue_style( 'ai-canvas-' . $post_id, AI_Canvas_Files::url( $post_id, 'css' ), array(), (string) filemtime( $css ) );
		}

		$js = AI_Canvas_Files::path( $post_id, 'js' );
		if ( ! is_wp_error( $js ) && file_exists( $js ) ) {
			wp_enqueue_script( 'ai-canvas-' . $post_id, AI_Canvas_Files::url( $post_id, 'js' ), array(), (string) filemtime( $js ), array( 'in_footer' => true ) );
		}
	}
}
