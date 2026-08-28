<?php
/**
 * Remove all canvas file sets on plugin uninstall. Canvas posts themselves are
 * left in place (they're regular pages/posts); only the served files go.
 *
 * Self-contained by design — the plugin's classes are not loaded here, so the
 * WP_Filesystem bootstrap is repeated rather than shared with AI_Canvas_Files.
 *
 * @package Ai_Canvas
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wp_filesystem;

require_once ABSPATH . 'wp-admin/includes/file.php';

$ai_canvas_uploads = wp_upload_dir();

// delete_plugins() has usually initialised this already; WP-CLI's
// `plugin uninstall` has not, so ask for it either way.
if ( ! WP_Filesystem( false, $ai_canvas_uploads['basedir'], true ) || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
	return;
}

$ai_canvas_base = trailingslashit( $ai_canvas_uploads['basedir'] ) . 'ai-canvas';
if ( ! $wp_filesystem->is_dir( $ai_canvas_base ) ) {
	return;
}

$ai_canvas_entries = $wp_filesystem->dirlist( $ai_canvas_base );
if ( ! is_array( $ai_canvas_entries ) ) {
	return;
}

// Each subdirectory holds at most the three known files (+ temp leftovers);
// anything unexpected is left untouched rather than recursively deleted.
foreach ( $ai_canvas_entries as $ai_canvas_entry ) {
	if ( 'd' !== ( $ai_canvas_entry['type'] ?? '' ) ) {
		continue;
	}
	$ai_canvas_dir = $ai_canvas_base . '/' . $ai_canvas_entry['name'];
	foreach ( array( 'index.html', 'style.css', 'script.js' ) as $ai_canvas_file ) {
		foreach ( array( $ai_canvas_dir . '/' . $ai_canvas_file, $ai_canvas_dir . '/.' . $ai_canvas_file . '.tmp', $ai_canvas_dir . '/.' . $ai_canvas_file . '.prev' ) as $ai_canvas_path ) {
			if ( $wp_filesystem->exists( $ai_canvas_path ) ) {
				$wp_filesystem->delete( $ai_canvas_path, false, 'f' );
			}
		}
	}
	$wp_filesystem->rmdir( $ai_canvas_dir );
}
$wp_filesystem->rmdir( $ai_canvas_base );
