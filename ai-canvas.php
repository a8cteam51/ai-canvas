<?php
/**
 * Plugin Name:     AI-Canvas
 * Plugin URI:      https://github.com/a8cteam51/ai-canvas
 * Update URI:      https://github.com/a8cteam51/ai-canvas
 * Description:     Per-page AI-writable HTML/CSS/JS canvases rendered between the theme header and footer, exposed to agents via MCP.
 * Author:          Team51
 * Text Domain:     ai-canvas
 * Version:         0.1.0
 * Requires at least: 6.9
 * Requires PHP:    8.0
 * Requires Plugins: mcp-adapter
 *
 * @package         Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

define( 'AI_CANVAS_VERSION', '0.1.0' );
define( 'AI_CANVAS_FILE', __FILE__ );
define( 'AI_CANVAS_DIR', plugin_dir_path( __FILE__ ) );

// The Abilities API ships in core 6.9+; without it there is no MCP surface.
if ( ! function_exists( 'wp_register_ability' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>AI-Canvas requires WordPress 6.9 or newer (Abilities API).</p></div>';
		}
	);
	return;
}

require AI_CANVAS_DIR . 'includes/class-files.php';
require AI_CANVAS_DIR . 'includes/class-render.php';
require AI_CANVAS_DIR . 'includes/class-cache.php';
require AI_CANVAS_DIR . 'includes/class-abilities.php';
require AI_CANVAS_DIR . 'includes/class-mcp.php';

AI_Canvas_Files::init();
AI_Canvas_Render::init();
AI_Canvas_Abilities::init();
AI_Canvas_MCP::init();
