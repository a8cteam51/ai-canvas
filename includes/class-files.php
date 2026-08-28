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
			foreach ( array( $dir . '/' . $filename, $dir . '/.' . $filename . '.tmp', $dir . '/.' . $filename . '.prev' ) as $path ) {
				if ( file_exists( $path ) ) {
					@unlink( $path );
				}
			}
		}
		@rmdir( $dir );
	}

	const MAX_BYTES = 2097152; // 2 MB per file.

	const TEMPLATE_SLUG       = 'canvas';
	const TEMPLATE_SLUG_BLANK = 'canvas-blank';
	const TEMPLATE_SLUGS      = array( self::TEMPLATE_SLUG, self::TEMPLATE_SLUG_BLANK );

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
	 * A post is a canvas when either AI-Canvas template is assigned to it.
	 */
	public static function is_canvas( int $post_id ): bool {
		return get_post( $post_id ) instanceof WP_Post
			&& in_array( get_page_template_slug( $post_id ), self::TEMPLATE_SLUGS, true );
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
		if ( false === file_put_contents( $tmp, $contents ) ) {
			@unlink( $tmp );
			return new WP_Error( 'ai_canvas_write_failed', 'Could not write the file.' );
		}

		// Retain the outgoing version as the single rollback slot. An identical
		// write skips the copy so a no-op doesn't burn the slot.
		if ( file_exists( $path ) && file_get_contents( $path ) !== $contents ) {
			@copy( $path, self::prev_path_for( $post_id, $file ) );
		}

		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'ai_canvas_write_failed', 'Could not write the file.' );
		}

		return array(
			'file'  => $file,
			'bytes' => strlen( $contents ),
			'url'   => 'html' === $file ? get_permalink( $post_id ) : self::url( $post_id, $file ),
		);
	}

	/**
	 * Path of the retained previous version. Assumes $file is already a valid
	 * enum key — callers go through path() first.
	 */
	private static function prev_path_for( int $post_id, string $file ): string {
		return self::dir( $post_id ) . '/.' . self::FILES[ $file ] . '.prev';
	}

	/**
	 * Swap the live file with its retained previous version. Symmetric by
	 * design: rolling back twice restores the original, so a rollback is
	 * itself undoable. The live file is replaced via rename() so visitors
	 * never hit a missing asset mid-swap.
	 */
	public static function rollback( int $post_id, string $file ): array|WP_Error {
		$path = self::path( $post_id, $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$prev = self::prev_path_for( $post_id, $file );
		if ( ! file_exists( $prev ) ) {
			return new WP_Error( 'ai_canvas_no_previous', sprintf( 'No previous version of the %s file exists for post %d — nothing has overwritten it yet.', $file, $post_id ) );
		}

		$stash = self::dir( $post_id ) . '/.' . self::FILES[ $file ] . '.tmp';
		if ( file_exists( $path ) && ! copy( $path, $stash ) ) {
			return new WP_Error( 'ai_canvas_rollback_failed', 'Could not stage the current version.' );
		}
		if ( ! rename( $prev, $path ) ) {
			@unlink( $stash );
			return new WP_Error( 'ai_canvas_rollback_failed', 'Could not restore the previous version.' );
		}
		// Failing to arm the redo slot only costs the ability to undo this
		// rollback; the restore itself already succeeded.
		if ( file_exists( $stash ) && ! rename( $stash, $prev ) ) {
			@unlink( $stash );
		}

		return array(
			'file'  => $file,
			'bytes' => (int) filesize( $path ),
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
