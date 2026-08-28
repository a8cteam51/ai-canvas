<?php
/**
 * Page-cache invalidation after a canvas write. Only public visitors see page
 * caches; all of this no-ops on hosts without the relevant layer.
 *
 * @package Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

class AI_Canvas_Cache {

	/**
	 * @param int   $post_id The canvas post.
	 * @param array $change  The recorded change (user, action, file, bytes,
	 *                       sha256, time), passed through to listeners so the
	 *                       action is useful for logging and not just caching.
	 */
	public static function purge( int $post_id, array $change = array() ): void {
		self::purge_batcache( get_permalink( $post_id ) );
		self::purge_pressable_edge();
		do_action( 'ai_canvas_after_write', $post_id, $change );
	}

	/**
	 * Batcache has no purge helper; the canonical single-URL invalidation is
	 * bumping the URL's version key (pattern from the Pressable Cache
	 * Management plugin). The key is always the http form of the URL.
	 */
	private static function purge_batcache( string $url ): void {
		global $batcache, $wp_object_cache;

		if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
			return;
		}

		$batcache->configure_groups();
		$url = apply_filters( 'batcache_manager_link', $url );
		do_action( 'batcache_manager_before_flush', $url );

		$url_key = md5( set_url_scheme( $url, 'http' ) );
		wp_cache_add( "{$url_key}_version", 0, $batcache->group );
		wp_cache_incr( "{$url_key}_version", 1, $batcache->group );

		do_action( 'batcache_manager_after_flush', $url );
	}

	/**
	 * Pressable's edge cache only purges site-wide.
	 */
	private static function purge_pressable_edge(): void {
		if ( class_exists( 'Edge_Cache_Plugin' ) && method_exists( 'Edge_Cache_Plugin', 'get_instance' ) ) {
			$edge = Edge_Cache_Plugin::get_instance();
			if ( method_exists( $edge, 'purge_domain_now' ) ) {
				$edge->purge_domain_now( 'ai-canvas file write' );
			}
		}
	}
}
