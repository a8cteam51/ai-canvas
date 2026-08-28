<?php
/**
 * Remove all canvas file sets on plugin uninstall. Canvas posts themselves are
 * left in place (they're regular pages/posts); only the served files go.
 *
 * @package Ai_Canvas
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ai_canvas_base = trailingslashit( wp_upload_dir()['basedir'] ) . 'ai-canvas';
if ( ! is_dir( $ai_canvas_base ) ) {
	return;
}

// Each subdirectory holds at most the three known files (+ temp leftovers);
// anything unexpected is left untouched rather than recursively deleted.
foreach ( glob( $ai_canvas_base . '/*', GLOB_ONLYDIR ) ?: array() as $ai_canvas_dir ) {
	foreach ( array( 'index.html', 'style.css', 'script.js' ) as $ai_canvas_file ) {
		foreach ( array( $ai_canvas_dir . '/' . $ai_canvas_file, $ai_canvas_dir . '/.' . $ai_canvas_file . '.tmp' ) as $ai_canvas_path ) {
			if ( file_exists( $ai_canvas_path ) ) {
				@unlink( $ai_canvas_path );
			}
		}
	}
	@rmdir( $ai_canvas_dir );
}
@rmdir( $ai_canvas_base );
