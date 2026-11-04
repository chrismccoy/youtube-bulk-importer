<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds post content, inserts the post, sets terms, meta, and featured image.
 */
class YTBI_Importer {

	const META_ID       = '_ytbi_video_id';
	const META_DURATION = '_ytbi_duration';
	const LOCK_PREFIX   = 'ytbi_lock_';
	const LOCK_TTL      = 300;
	const THUMB_TIMEOUT = 20;

	/**
	 * API client.
	 */
	private $youtube;

	/**
	 * Constructor.
	 */
	public function __construct( YTBI_Video_Source $youtube ) {
		$this->youtube = $youtube;
	}

	/**
	 * Import one video.
	 */
	public function import( $video_id, array $edits = array() ) {
		$lock = self::LOCK_PREFIX . $video_id;
		if ( ! self::acquire_lock( $lock ) ) {
			return new WP_Error(
				'ytbi_duplicate',
				__( 'This video is being imported right now.', 'ytbi' ),
				array(
					'status'   => 409,
					'post_id'  => null,
					'edit_url' => null,
				)
			);
		}

		try {
			return $this->import_unlocked( $video_id, $edits );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Take a per-video lock.
	 */
	private static function acquire_lock( $name ) {
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $name );
		if ( $since && time() - $since < self::LOCK_TTL ) {
			return false;
		}
		update_option( $name, time(), false );
		return true;
	}

	/**
	 * Single-line plain text: valid UTF-8, whitespace collapsed
	 */
	private static function plain_line( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_check_invalid_utf8( (string) $text ) ) );
	}

	/**
	 * Clean a title per settings: emoji are removed; hashtags and @mentions at the end
	 * are removed with any connector word ("by", "ft.", ...); inside the sentence only
	 * the # or @ sign is dropped so the words still read.
	 */
	public static function clean_title( $title, array $settings ) {
		$clean = (string) $title;

		if ( ! empty( $settings['strip_emojis'] ) ) {
			$clean = self::strip_emojis( $clean );
		}
		$clean = self::tidy_title( $clean );

		$inline_only = $clean;
		$clean       = self::strip_trailing_tags( $clean, $settings );
		$clean       = self::unwrap_tags( $clean, $settings );
		$inline_only = self::unwrap_tags( $inline_only, $settings );

		foreach ( array( $clean, $inline_only ) as $candidate ) {
			$candidate = self::tidy_title( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return (string) $title;
	}

	/**
	 * Regex for a hashtag token, capturing the word without the # sign.
	 */
	private static function hashtag_pattern() {
		return '(?<![\p{L}\p{N}_&])#(?=[\p{N}_]*\p{L})([\p{L}\p{N}_]+)';
	}

	/**
	 * Regex for an @mention token, capturing the handle without the @ sign.
	 */
	private static function mention_pattern() {
		return '(?<![\p{L}\p{N}_.])@([\p{L}\p{N}_](?:[\p{L}\p{N}_.-]*[\p{L}\p{N}_])?)';
	}

	/**
	 * Replace emoji and symbol ranges with a space.
	 */
	private static function strip_emojis( $text ) {
		return (string) preg_replace(
			'/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u',
			' ',
			$text
		);
	}

	/**
	 * Build the list of regex tokens enabled by settings (hashtag, mention
	 * with optional connector word).
	 */
	private static function enabled_tokens( array $settings ) {
		$tokens = array();
		if ( ! empty( $settings['strip_hashtags'] ) ) {
			$tokens[] = self::hashtag_pattern();
		}
		if ( ! empty( $settings['strip_mentions'] ) ) {
			$tokens[] = '(?:(?:by|with|ft\.?|feat\.?|x|via)\s+)?' . self::mention_pattern();
		}
		return $tokens;
	}

	/**
	 * Removes a trailing run of enabled hashtags/mentions (optionally preceded
	 * by a connector word like "by" or "ft.") along with their separators.
	 */
	private static function strip_trailing_tags( $text, array $settings ) {
		$tokens = self::enabled_tokens( $settings );
		if ( ! $tokens ) {
			return $text;
		}

		$token   = '(?:' . implode( '|', $tokens ) . ')';
		$pattern = '/(?:[\s|,·:–—-]*' . $token . ')+[\s|,·:–—-]*$/iu';

		return (string) preg_replace( $pattern, '', $text );
	}

	/**
	 * Drops the leading # or @ sign from any remaining enabled tags so the
	 * words still read naturally inline.
	 */
	private static function unwrap_tags( $text, array $settings ) {
		if ( ! empty( $settings['strip_hashtags'] ) ) {
			$text = (string) preg_replace( '/' . self::hashtag_pattern() . '/u', '$1', $text );
		}
		if ( ! empty( $settings['strip_mentions'] ) ) {
			$text = (string) preg_replace( '/' . self::mention_pattern() . '/u', '$1', $text );
		}
		return $text;
	}

	/**
	 * Collapse spaces, drop spaces before punctuation, trim separators at the ends.
	 */
	private static function tidy_title( $text ) {
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = preg_replace( '/\s+([,.!?;:])/u', '$1', (string) $text );
		return trim( (string) $text, " \t|-–—·:,/" );
	}

	/**
	 * Import without the lock.
	 */
	private function import_unlocked( $video_id, array $edits ) {
		$existing = self::find_existing( array( $video_id ) );
		if ( isset( $existing[ $video_id ] ) ) {
			return new WP_Error(
				'ytbi_duplicate',
				__( 'This video is already imported.', 'ytbi' ),
				array(
					'status'   => 409,
					'post_id'  => $existing[ $video_id ],
					'edit_url' => get_edit_post_link( $existing[ $video_id ], 'raw' ),
				)
			);
		}

		$settings = YTBI_Settings::all();

		$needs_description = $settings['import_description'] && ! isset( $edits['description'] );
		$video             = $this->youtube->get_video( $video_id, $needs_description );
		if ( is_wp_error( $video ) ) {
			return $video;
		}

		$title = isset( $edits['title'] ) ? self::plain_line( $edits['title'] ) : '';
		if ( '' === $title ) {
			$title = self::plain_line( $video['title'] );
		}
		$title = self::clean_title( $title, $settings );

		$description = '';
		if ( $settings['import_description'] ) {
			$description = isset( $edits['description'] ) ? (string) $edits['description'] : (string) $video['description'];
			$description = wp_check_invalid_utf8( $description );
		}

		$status = isset( $edits['status'] ) && in_array( $edits['status'], YTBI_Settings::statuses(), true )
			? $edits['status']
			: $settings['post_status'];

		$post_type = $settings['post_type'];
		$content   = self::build_content( $video['url'], $description, self::resolve_format( $post_type ) );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => esc_html( $title ),
					'post_content' => $content,
					'post_status'  => 'draft',
					'post_type'    => $post_type,
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			ytbi_log( 'Insert failed for ' . $video_id . ': ' . $post_id->get_error_message() );
			return $post_id;
		}

		update_post_meta( $post_id, self::META_ID, $video['id'] );
		if ( $settings['store_duration'] ) {
			update_post_meta( $post_id, self::META_DURATION, (int) $video['duration_s'] );
		}

		if ( post_type_supports( $post_type, 'post-formats' ) ) {
			set_post_format( $post_id, 'video' );
		}

		$category = isset( $edits['category'] ) ? absint( $edits['category'] ) : 0;
		if ( $category ) {
			wp_set_object_terms( $post_id, array( $category ), $settings['category_taxonomy'] );
		}

		if ( $settings['import_tags'] ) {
			$tags = isset( $edits['tags'] ) && is_array( $edits['tags'] ) ? $edits['tags'] : $video['tags'];
			$tags = self::clean_tags( $tags, (int) $settings['max_tags'] );
			if ( $tags ) {
				wp_set_object_terms( $post_id, $tags, $settings['tag_taxonomy'] );
			}
		}

		$warnings = array();
		$thumb_url = $video['thumb_url'];
		if ( 'standard' === $settings['featured_image_size'] && ! empty( $video['thumb_url_standard'] ) ) {
			$thumb_url = $video['thumb_url_standard'];
		}
		if ( $settings['featured_image'] && '' !== $thumb_url ) {
			$warning = self::set_featured_image( $post_id, $thumb_url, $title, $video['id'] );
			if ( '' !== $warning ) {
				$warnings[] = $warning;
			}
		}

		if ( 'draft' !== $status ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);
		}

		return array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'view_url' => get_permalink( $post_id ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Posts that already hold video IDs. Trash and auto-drafts are ignored.
	 */
	public static function find_existing( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value IN ($placeholders)
				AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				ORDER BY pm.post_id DESC",
				array_merge( array( self::META_ID ), $ids )
			)
		);

		$found = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $found[ $row->meta_value ] ) ) {
				$found[ $row->meta_value ] = (int) $row->post_id;
			}
		}
		return $found;
	}

	/**
	 * 'block' or 'classic' for this post type
	 */
	public static function resolve_format( $post_type ) {
		$format = YTBI_Settings::get( 'content_format' );
		if ( 'block' === $format || 'classic' === $format ) {
			return $format;
		}
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return use_block_editor_for_post_type( $post_type ) ? 'block' : 'classic';
	}

	/**
	 * Post content: the embed URL plus the description.
	 */
	public static function build_content( $url, $description, $format ) {
		$description = trim( str_replace( array( "\r\n", "\r" ), "\n", $description ) );

		if ( 'classic' === $format ) {
			return '' === $description ? $url : $url . "\n\n" . self::linkify( $description );
		}

		$attrs = array(
			'url'              => $url,
			'type'             => 'video',
			'providerNameSlug' => 'youtube',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
		);

		$content  = '<!-- wp:embed ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . " -->\n";
		$content .= '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n";
		$content .= $url . "\n";
		$content .= "</div></figure>\n<!-- /wp:embed -->";

		if ( '' === $description ) {
			return $content;
		}

		foreach ( preg_split( '/\n\s*\n/', $description ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			$content .= "\n\n<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', self::linkify( $paragraph ) ) . "</p>\n<!-- /wp:paragraph -->";
		}

		return $content;
	}

	/**
	 * Escape plain text and turn URLs into links. A bare URL alone on a line or in
	 * its own paragraph would otherwise be auto-embedded by core oEmbed.
	 */
	private static function linkify( $text ) {
		return make_clickable( esc_html( $text ) );
	}

	public static function clean_tags( array $tags, $max ) {
		$out  = array();
		$seen = array();
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );
			if ( '' === $tag || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tag;
			if ( $max > 0 && count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Download the thumbnail (short timeout) and set it as featured image.
	 */
	private static function set_featured_image( $post_id, $url, $title, $video_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, self::THUMB_TIMEOUT );
		if ( ! is_wp_error( $tmp ) ) {
			$file          = array(
				'name'     => sanitize_file_name( $video_id . '-' . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ),
				'tmp_name' => $tmp,
			);
			$attachment_id = media_handle_sideload( $file, $post_id, $title );
			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
				return '';
			}
			wp_delete_file( $tmp );
			$tmp = $attachment_id;
		}

		ytbi_log( 'Thumbnail failed for post ' . $post_id . ': ' . $tmp->get_error_message() );
		return sprintf( __( 'Featured image failed: %s', 'ytbi' ), $tmp->get_error_message() );
	}
}
