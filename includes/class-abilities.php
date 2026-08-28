<?php
/**
 * The MCP tool surface, registered as core Abilities (WP 6.9+).
 *
 * @package Ai_Canvas
 */

defined( 'ABSPATH' ) || exit;

class AI_Canvas_Abilities {

	const ABILITIES = array(
		'ai-canvas/create-canvas',
		'ai-canvas/list-canvases',
		'ai-canvas/read-file',
		'ai-canvas/write-file',
		'ai-canvas/rollback-file',
		'ai-canvas/upload-media',
		'ai-canvas/list-media',
	);

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			'ai-canvas',
			array(
				'label'       => __( 'AI Canvas', 'ai-canvas' ),
				'description' => __( 'Write per-page HTML/CSS/JS canvases and manage media.', 'ai-canvas' ),
			)
		);
	}

	/**
	 * Meta shared by every ability: exposed to MCP, hidden from the abilities REST channel.
	 */
	private static function meta( array $extra = array() ): array {
		return array_merge(
			array(
				'mcp'          => array( 'public' => true ),
				'show_in_rest' => false,
			),
			$extra
		);
	}

	public static function register_abilities(): void {
		wp_register_ability(
			'ai-canvas/create-canvas',
			array(
				'label'               => __( 'Create canvas', 'ai-canvas' ),
				'description'         => __( 'Create a published page or post rendered as an AI canvas: an HTML/CSS/JS file set this agent can write to, wrapped in the theme header and footer (template "theme") or on a completely blank page the canvas fully controls (template "blank"). Returns the new post ID needed by the file tools.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'slug'      => array( 'type' => 'string' ),
						'post_type' => array(
							'type'    => 'string',
							'enum'    => array( 'page', 'post' ),
							'default' => 'page',
						),
						'template'  => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'blank' ),
							'default'     => 'theme',
							'description' => 'theme = canvas between the theme header and footer; blank = no theme header/footer, the canvas controls the whole page.',
						),
					),
				),
				'output_schema'       => self::canvas_schema(),
				'permission_callback' => function ( $input = array() ) {
					$post_type = get_post_type_object( $input['post_type'] ?? 'page' );
					return $post_type
						&& self::is_editor()
						&& current_user_can( $post_type->cap->publish_posts )
						&& current_user_can( 'unfiltered_html' );
				},
				'execute_callback'    => array( __CLASS__, 'create_canvas' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ai-canvas/list-canvases',
			array(
				'label'               => __( 'List canvases', 'ai-canvas' ),
				'description'         => __( 'List all AI canvas pages/posts with their IDs, URLs, and file modification times.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => array( 'type' => 'object', 'properties' => (object) array() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'canvases' => array(
							'type'  => 'array',
							'items' => self::canvas_schema(),
						),
					),
				),
				'permission_callback' => array( __CLASS__, 'is_editor' ),
				'execute_callback'    => array( __CLASS__, 'list_canvases' ),
				'meta'                => self::meta( array( 'annotations' => array( 'readonly' => true ) ) ),
			)
		);

		wp_register_ability(
			'ai-canvas/read-file',
			array(
				'label'               => __( 'Read canvas file', 'ai-canvas' ),
				'description'         => __( 'Read the current contents of a canvas file (html = index.html, css = style.css, js = script.js) for a canvas post.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => self::file_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contents' => array( 'type' => 'string' ),
						'bytes'    => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_edit_target' ),
				'execute_callback'    => array( __CLASS__, 'read_file' ),
				'meta'                => self::meta( array( 'annotations' => array( 'readonly' => true ) ) ),
			)
		);

		wp_register_ability(
			'ai-canvas/write-file',
			array(
				'label'               => __( 'Write canvas file', 'ai-canvas' ),
				'description'         => __( 'Overwrite one canvas file (html = index.html, css = style.css, js = script.js) for a canvas post. The write is live immediately: index.html renders as the page body (between the theme header and footer on the theme template, alone on the blank template), style.css and script.js are enqueued on the page. The overwritten contents are retained as the file\'s single previous version for rollback-file. Max 2 MB per file. The html file may not contain inline JavaScript — no <script> tags, event-handler attributes, or javascript: URLs; page behaviour belongs in the js file, which is enqueued automatically.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => array_merge_recursive(
					self::file_input_schema(),
					array(
						'required'   => array( 'contents' ),
						'properties' => array( 'contents' => array( 'type' => 'string' ) ),
					)
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'file'  => array( 'type' => 'string' ),
						'bytes' => array( 'type' => 'integer' ),
						'url'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_write_target' ),
				'execute_callback'    => array( __CLASS__, 'write_file' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ai-canvas/rollback-file',
			array(
				'label'               => __( 'Roll back canvas file', 'ai-canvas' ),
				'description'         => __( 'Instantly restore a canvas file (html = index.html, css = style.css, js = script.js) to its previous version — the contents it had before the last write. Exactly one previous version is retained per file, and rolling back swaps current and previous, so calling this again undoes the rollback. The restore is live immediately.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => self::file_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'file'  => array( 'type' => 'string' ),
						'bytes' => array( 'type' => 'integer' ),
						'url'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_write_target' ),
				'execute_callback'    => array( __CLASS__, 'rollback_file' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ai-canvas/upload-media',
			array(
				'label'               => __( 'Upload media', 'ai-canvas' ),
				'description'         => __( 'Add a file to the Media Library from a URL or base64 data, and get back the attachment URL to reference from canvas HTML/CSS. For images, the result includes pixel dimensions and the generated smaller sizes — reference the smallest size that covers the display area, and mirror its width/height in the HTML.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'      => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Remote file to sideload. Provide either url or base64.',
						),
						'base64'   => array(
							'type'        => 'string',
							'description' => 'Base64-encoded file contents. Requires filename.',
						),
						'filename' => array( 'type' => 'string' ),
						'title'    => array( 'type' => 'string' ),
						'alt'      => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::attachment_schema(),
				'permission_callback' => fn() => self::is_editor() && current_user_can( 'upload_files' ),
				'execute_callback'    => array( __CLASS__, 'upload_media' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ai-canvas/list-media',
			array(
				'label'               => __( 'List media', 'ai-canvas' ),
				'description'         => __( 'Search the Media Library and get attachment URLs to reference from canvas HTML/CSS. Image results include pixel dimensions and the generated smaller sizes — reference the smallest size that covers the display area, and mirror its width/height in the HTML.', 'ai-canvas' ),
				'category'            => 'ai-canvas',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string' ),
						'limit'  => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'media' => array(
							'type'  => 'array',
							'items' => self::attachment_schema(),
						),
					),
				),
				'permission_callback' => fn() => self::is_editor() && current_user_can( 'upload_files' ),
				'execute_callback'    => array( __CLASS__, 'list_media' ),
				'meta'                => self::meta( array( 'annotations' => array( 'readonly' => true ) ) ),
			)
		);

	}

	private static function file_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'file' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'file'    => array(
					'type' => 'string',
					'enum' => array( 'html', 'css', 'js' ),
				),
			),
		);
	}

	private static function attachment_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
				'mime'          => array( 'type' => 'string' ),
				'title'         => array( 'type' => 'string' ),
				'alt'           => array( 'type' => 'string' ),
				'width'         => array( 'type' => array( 'integer', 'null' ) ),
				'height'        => array( 'type' => array( 'integer', 'null' ) ),
				'sizes'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array( 'type' => 'string' ),
							'url'    => array( 'type' => 'string' ),
							'width'  => array( 'type' => 'integer' ),
							'height' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	private static function canvas_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'   => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'post_type' => array( 'type' => 'string' ),
				'template'  => array(
					'type' => 'string',
					'enum' => array( 'theme', 'blank' ),
				),
				'url'       => array( 'type' => 'string' ),
				'edit_url'  => array( 'type' => 'string' ),
				'files'     => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * Editor-and-above gate, applied to every ability on top of that tool's own
	 * capability check. `edit_others_posts` is what separates an Editor from an
	 * Author across core roles, and it still holds on multisite — unlike
	 * `unfiltered_html`, which narrows to super admins there and so can't carry
	 * this on its own.
	 */
	public static function is_editor(): bool {
		return current_user_can( 'edit_others_posts' );
	}

	public static function can_edit_target( $input = array() ): bool {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		return self::is_editor() && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Canvas writes are unsanitized same-origin HTML/JS, which is exactly what
	 * core reserves unfiltered_html for (Editor+ on single site, super admins
	 * on multisite, nobody when DISALLOW_UNFILTERED_HTML is set).
	 */
	public static function can_write_target( $input = array() ): bool {
		return self::can_edit_target( $input ) && current_user_can( 'unfiltered_html' );
	}

	// --- Execute callbacks -------------------------------------------------.

	public static function create_canvas( $input = array() ) {
		$template = 'blank' === ( $input['template'] ?? 'theme' )
			? AI_Canvas_Files::TEMPLATE_SLUG_BLANK
			: AI_Canvas_Files::TEMPLATE_SLUG;

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $input['title'] ),
				'post_name'    => sanitize_title( $input['slug'] ?? '' ),
				'post_type'    => $input['post_type'] ?? 'page',
				'post_status'  => 'publish',
				// The block gives the editor an "AI-controlled" card instead of a
				// blank canvas (and suppresses the starter-pattern modal). The
				// canvas templates don't render post content, so it's inert on
				// the front end.
				'post_content' => '<!-- wp:ai-canvas/content /-->',
				'meta_input'   => array( '_wp_page_template' => $template ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		AI_Canvas_Files::scaffold( $post_id );

		return self::describe_canvas( $post_id );
	}

	public static function list_canvases() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'meta_key'       => '_wp_page_template',
				'meta_value'     => AI_Canvas_Files::TEMPLATE_SLUGS,
				'meta_compare'   => 'IN',
			)
		);

		// Only surface canvases the caller could actually edit; the edit_pages
		// gate alone doesn't imply access to other users' drafts/private posts.
		$posts = array_filter( $posts, fn( $post ) => current_user_can( 'edit_post', $post->ID ) );

		return array( 'canvases' => array_values( array_map( fn( $post ) => self::describe_canvas( $post->ID ), $posts ) ) );
	}

	public static function read_file( $input = array() ) {
		$post_id = (int) $input['post_id'];
		if ( ! AI_Canvas_Files::is_canvas( $post_id ) ) {
			return self::not_a_canvas( $post_id );
		}
		$contents = AI_Canvas_Files::read( $post_id, $input['file'] );
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}
		return array(
			'contents' => $contents,
			'bytes'    => strlen( $contents ),
		);
	}

	public static function write_file( $input = array() ) {
		$post_id = (int) $input['post_id'];
		if ( ! AI_Canvas_Files::is_canvas( $post_id ) ) {
			return self::not_a_canvas( $post_id );
		}
		$result = AI_Canvas_Files::write( $post_id, $input['file'], $input['contents'] );
		if ( ! is_wp_error( $result ) ) {
			AI_Canvas_Cache::purge( $post_id, AI_Canvas_Files::last_change( $post_id ) );
		}
		return $result;
	}

	public static function rollback_file( $input = array() ) {
		$post_id = (int) $input['post_id'];
		if ( ! AI_Canvas_Files::is_canvas( $post_id ) ) {
			return self::not_a_canvas( $post_id );
		}
		$result = AI_Canvas_Files::rollback( $post_id, $input['file'] );
		if ( ! is_wp_error( $result ) ) {
			AI_Canvas_Cache::purge( $post_id, AI_Canvas_Files::last_change( $post_id ) );
		}
		return $result;
	}

	public static function upload_media( $input = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$max_bytes = wp_max_upload_size();

		if ( ! empty( $input['url'] ) ) {
			$tmp = download_url( $input['url'] );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			// download_url() writes the temp file itself; wp_filesize()/
			// wp_delete_file() are the core-owned accessors for it, and unlike
			// WP_Filesystem they stay correct when the temp dir sits outside
			// the filesystem abstraction's root.
			if ( wp_filesize( $tmp ) > $max_bytes ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ai_canvas_too_large', sprintf( 'Download exceeds the %d byte upload limit.', $max_bytes ) );
			}
			$name = $input['filename'] ?? basename( (string) wp_parse_url( $input['url'], PHP_URL_PATH ) );
		} elseif ( ! empty( $input['base64'] ) && ! empty( $input['filename'] ) ) {
			// Check the encoded length before decoding so an oversized payload
			// never allocates its decoded form (base64 inflates ~4/3).
			if ( strlen( $input['base64'] ) > ( $max_bytes * 4 / 3 ) + 1024 ) {
				return new WP_Error( 'ai_canvas_too_large', sprintf( 'Upload exceeds the %d byte upload limit.', $max_bytes ) );
			}
			$contents = base64_decode( $input['base64'], true );
			if ( false === $contents ) {
				return new WP_Error( 'ai_canvas_bad_base64', 'base64 could not be decoded.' );
			}
			if ( strlen( $contents ) > $max_bytes ) {
				return new WP_Error( 'ai_canvas_too_large', sprintf( 'Upload exceeds the %d byte upload limit.', $max_bytes ) );
			}
			$name = sanitize_file_name( $input['filename'] );
			$tmp  = wp_tempnam( $name );
			if ( ! $tmp ) {
				return new WP_Error( 'ai_canvas_tmp_failed', 'Could not stage the upload.' );
			}
			$fs = AI_Canvas_Files::filesystem();
			if ( is_wp_error( $fs ) ) {
				wp_delete_file( $tmp );
				return $fs;
			}
			// wp_tempnam() has already created the (empty) file; this only
			// fills it, via the same abstraction every other write here uses.
			if ( ! $fs->put_contents( $tmp, $contents, FS_CHMOD_FILE ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ai_canvas_tmp_failed', 'Could not stage the upload.' );
			}
		} else {
			return new WP_Error( 'ai_canvas_bad_input', 'Provide either url, or base64 with filename.' );
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			$input['title'] ?? null
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}

		return self::describe_attachment( $attachment_id );
	}

	public static function list_media( $input = array() ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) ),
			's'              => $input['search'] ?? '',
		);
		// Backstop: the ability gate already requires edit_others_posts, so this
		// never narrows the query today. Kept so loosening that gate can't
		// silently expose other users' uploads.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}
		$attachments = get_posts( $args );

		return array(
			'media' => array_map( fn( $att ) => self::describe_attachment( $att->ID ), $attachments ),
		);
	}

	/**
	 * Attachment shape shared by upload-media and list-media. Dimensions and
	 * generated sizes exist so agents can reference a right-sized variant with
	 * explicit width/height instead of the full-size original.
	 */
	private static function describe_attachment( int $attachment_id ): array {
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = array();
		foreach ( array_keys( $meta['sizes'] ?? array() ) as $size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $src ) {
				$sizes[] = array(
					'name'   => $size,
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'mime'          => (string) get_post_mime_type( $attachment_id ),
			'title'         => get_the_title( $attachment_id ),
			'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'sizes'         => $sizes,
		);
	}

	private static function describe_canvas( int $post_id ): array {
		return array(
			'post_id'   => $post_id,
			'title'     => get_the_title( $post_id ),
			'post_type' => (string) get_post_type( $post_id ),
			'template'  => AI_Canvas_Files::TEMPLATE_SLUG_BLANK === get_page_template_slug( $post_id ) ? 'blank' : 'theme',
			'url'       => get_permalink( $post_id ),
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'files'     => AI_Canvas_Files::mtimes( $post_id ),
		);
	}

	private static function not_a_canvas( int $post_id ): WP_Error {
		return new WP_Error(
			'ai_canvas_not_canvas',
			sprintf( 'Post %d is not an AI canvas. Use create-canvas first, or list-canvases to find one.', $post_id )
		);
	}
}
