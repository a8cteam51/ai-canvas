<?php
/**
 * File storage for canvases: wp-content/uploads/ai-canvas/{post_id}/{index.html,style.css,script.js}.
 *
 * The API is the jail: callers address files by post ID + a fixed enum, never by path.
 *
 * @package Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

class AI_Canvas_Files {

	const FILES = array(
		'html' => 'index.html',
		'css'  => 'style.css',
		'js'   => 'script.js',
	);

	public static function init(): void {
		// Trash keeps the files (the post is restorable); permanent deletion
		// removes them so canvas content doesn't outlive its post.
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_post' ) );
	}

	/**
	 * Remove the file set for a post being permanently deleted. Keyed on the
	 * directory existing (not is_canvas) so files are cleaned up even when the
	 * template was reassigned before deletion. Only known filenames are
	 * removed; a directory holding anything else is left in place.
	 */
	public static function delete_for_post( int $post_id ): void {
		$dir = self::dir( $post_id );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( self::FILES as $filename ) {
			foreach ( array( $dir . '/' . $filename, $dir . '/.' . $filename . '.tmp' ) as $path ) {
				if ( file_exists( $path ) ) {
					@unlink( $path );
				}
			}
		}
		@rmdir( $dir );
	}

	const MAX_BYTES = 2097152; // 2 MB per file.

	const TEMPLATE_SLUG = 'canvas';

	public static function base_dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'ai-canvas';
	}

	public static function dir( int $post_id ): string {
		return self::base_dir() . '/' . $post_id;
	}

	public static function path( int $post_id, string $file ): string|WP_Error {
		if ( ! isset( self::FILES[ $file ] ) ) {
			return new WP_Error( 'ai_canvas_bad_file', 'File must be one of: html, css, js.' );
		}
		return self::dir( $post_id ) . '/' . self::FILES[ $file ];
	}

	public static function url( int $post_id, string $file ): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'ai-canvas/' . $post_id . '/' . self::FILES[ $file ];
	}

	/**
	 * A post is a canvas when the AI-Canvas template is assigned to it.
	 */
	public static function is_canvas( int $post_id ): bool {
		return get_post( $post_id ) instanceof WP_Post
			&& get_page_template_slug( $post_id ) === self::TEMPLATE_SLUG;
	}

	public static function read( int $post_id, string $file ): string|WP_Error {
		$path = self::path( $post_id, $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'ai_canvas_missing', sprintf( 'No %s file exists for post %d.', $file, $post_id ) );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error( 'ai_canvas_read_failed', 'Could not read the file.' );
		}
		return $contents;
	}

	public static function write( int $post_id, string $file, string $contents ): array|WP_Error {
		$path = self::path( $post_id, $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( strlen( $contents ) > self::MAX_BYTES ) {
			return new WP_Error( 'ai_canvas_too_large', sprintf( 'File exceeds the %d byte limit.', self::MAX_BYTES ) );
		}

		$dir = self::dir( $post_id );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ai_canvas_mkdir_failed', 'Could not create the canvas directory.' );
		}

		// Belt-and-braces containment check on top of the enum-based API.
		$real_dir = realpath( $dir );
		$real_base = realpath( self::base_dir() );
		if ( false === $real_dir || false === $real_base || ! str_starts_with( $real_dir, $real_base . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'ai_canvas_jailbreak', 'Resolved path escapes the canvas directory.' );
		}

		// Write to a temp file then rename so a half-written asset is never served.
		$tmp = $dir . '/.' . self::FILES[ $file ] . '.tmp';
		if ( false === file_put_contents( $tmp, $contents ) || ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'ai_canvas_write_failed', 'Could not write the file.' );
		}

		return array(
			'file'  => $file,
			'bytes' => strlen( $contents ),
			'url'   => 'html' === $file ? get_permalink( $post_id ) : self::url( $post_id, $file ),
		);
	}

	public static function scaffold( int $post_id ): void {
		$defaults = array(
			'html' => "<main class=\"ai-canvas\">\n\t<h2>AI-Canvas placeholder</h2>\n\t<p>Write index.html via MCP to replace this.</p>\n</main>\n",
			'css'  => "/* AI-Canvas: styles for post {$post_id} */\n",
			'js'   => "/* AI-Canvas: script for post {$post_id} */\n",
		);
		foreach ( $defaults as $file => $contents ) {
			$path = self::path( $post_id, $file );
			if ( ! is_wp_error( $path ) && ! file_exists( $path ) ) {
				self::write( $post_id, $file, $contents );
			}
		}
	}

	public static function mtimes( int $post_id ): array {
		$mtimes = array();
		foreach ( array_keys( self::FILES ) as $file ) {
			$path            = self::path( $post_id, $file );
			$mtimes[ $file ] = ( ! is_wp_error( $path ) && file_exists( $path ) ) ? (int) filemtime( $path ) : null;
		}
		return $mtimes;
	}
}
