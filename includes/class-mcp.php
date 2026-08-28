<?php
/**
 * MCP server exposing the AI-Canvas abilities at /wp-json/ai-canvas/mcp.
 *
 * Requires the WordPress MCP Adapter plugin (>= 0.6.1). Without it the
 * abilities still register; there is just no MCP endpoint.
 *
 * @package Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

class AI_Canvas_MCP {

	public static function init(): void {
		add_action( 'mcp_adapter_init', array( __CLASS__, 'create_server' ) );
	}

	public static function create_server( $adapter ): void {
		$adapter->create_server(
			'ai-canvas',
			'ai-canvas',
			'mcp',
			'AI-Canvas',
			'Vibe-code landing pages: per-page HTML/CSS/JS canvases rendered between the theme header and footer, plus Media Library access.',
			AI_CANVAS_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			AI_Canvas_Abilities::ABILITIES,
			array(),
			array(),
			// Transport-level gate: without it any logged-in user (Subscribers
			// included) can enumerate tool names/descriptions. Per-tool
			// permission callbacks still apply on top.
			fn() => current_user_can( 'edit_posts' )
		);
	}
}
