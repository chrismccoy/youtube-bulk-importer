<?php

defined( 'ABSPATH' ) || exit;

/**
 * Settings API page for the ytbi_settings option.
 */
class YTBI_Settings {

	const OPTION = 'ytbi_settings';
	const PAGE   = 'ytbi-settings';

	/**
	 * Page hook suffix.
	 */
	private static $hook = '';

	/**
	 * Per-request cache of merged settings, invalidated on save.
	 */
	private static $cache = null;

	/**
	 * Default values.
	 */
	public static function defaults() {
		return array(
			'api_key'           => '',
			'per_page'          => 24,
			'post_status'       => 'draft',
			'post_type'         => 'post',
			'category_taxonomy' => 'category',
			'tag_taxonomy'      => 'post_tag',
			'import_description' => true,
			'strip_hashtags'     => true,
			'strip_emojis'       => true,
			'strip_mentions'     => true,
			'import_tags'       => false,
			'max_tags'          => 4,
			'featured_image'    => true,
			'featured_image_size' => 'best',
			'content_format'    => 'auto',
			'store_duration'    => false,
		);
	}

	/**
	 * Saved values merged over defaults. Memoized per request.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Drop the cache. Called automatically when the option is added, updated, or deleted.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * One setting.
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Allowed post statuses for imports.
	 */
	public static function statuses() {
		return array( 'draft', 'publish', 'pending', 'private' );
	}

	/**
	 * Status => translated label.
	 */
	public static function status_choices() {
		$choices = array();
		foreach ( self::statuses() as $status ) {
			$object             = get_post_status_object( $status );
			$choices[ $status ] = $object ? $object->label : $status;
		}
		return $choices;
	}

	/**
	 * Sanitize the whole option array.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$old      = self::all();
		$defaults = self::defaults();
		$out      = array();

		$key            = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$out['api_key'] = '' === $key ? $old['api_key'] : $key;

		$out['per_page'] = isset( $input['per_page'] ) ? min( 50, max( 1, (int) $input['per_page'] ) ) : $defaults['per_page'];

		$out['post_status'] = isset( $input['post_status'] ) && in_array( $input['post_status'], self::statuses(), true )
			? $input['post_status']
			: $defaults['post_status'];

		$post_type        = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
		$out['post_type'] = '' !== $post_type && post_type_exists( $post_type ) ? $post_type : $defaults['post_type'];

		foreach ( array( 'category_taxonomy', 'tag_taxonomy' ) as $field ) {
			$taxonomy      = isset( $input[ $field ] ) ? sanitize_key( $input[ $field ] ) : '';
			$out[ $field ] = '' !== $taxonomy && is_object_in_taxonomy( $out['post_type'], $taxonomy ) ? $taxonomy : $defaults[ $field ];
		}

		$out['import_description'] = ! empty( $input['import_description'] );
		foreach ( array( 'strip_hashtags', 'strip_emojis', 'strip_mentions' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}
		$out['import_tags']        = ! empty( $input['import_tags'] );
		$out['max_tags']    = isset( $input['max_tags'] ) ? max( 0, (int) $input['max_tags'] ) : $defaults['max_tags'];

		$out['featured_image'] = ! empty( $input['featured_image'] );
		$out['featured_image_size'] = isset( $input['featured_image_size'] ) && 'standard' === $input['featured_image_size'] ? 'standard' : 'best';

		$out['content_format'] = isset( $input['content_format'] ) && in_array( $input['content_format'], array( 'auto', 'block', 'classic' ), true )
			? $input['content_format']
			: $defaults['content_format'];

		$out['store_duration'] = ! empty( $input['store_duration'] );

		return $out;
	}

	/**
	 * Hook the settings page into admin.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register setting, section, and fields.
	 */
	public static function register() {
		register_setting(
			'ytbi',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		add_settings_section( 'ytbi_main', '', '__return_false', self::PAGE );

		foreach ( self::fields() as $key => $field ) {
			add_settings_field(
				'ytbi_' . $key,
				$field['label'],
				array( __CLASS__, 'render_field' ),
				self::PAGE,
				'ytbi_main',
				array_merge(
					$field,
					array(
						'key'       => $key,
						'label_for' => 'ytbi_' . $key,
					)
				)
			);
		}
	}

	/**
	 * Add the Settings submenu under the plugin's top-level menu.
	 */
	public static function menu() {
		self::$hook = (string) add_submenu_page(
			YTBI_Admin::SLUG,
			__( 'YouTube Bulk Importer Settings', 'ytbi' ),
			__( 'Settings', 'ytbi' ),
			ytbi_capability(),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the API key tester on the settings page only.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ytbi-admin', YTBI_URL . 'assets/css/admin.css', array(), YTBI_VERSION . '.' . filemtime( YTBI_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'ytbi-settings', YTBI_URL . 'assets/js/settings.js', array(), YTBI_VERSION . '.' . filemtime( YTBI_DIR . 'assets/js/settings.js' ), true );
	}

	/**
	 * Field definitions.
	 */
	private static function fields() {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$post_types[ $type->name ] = $type->labels->singular_name;
			}
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$taxonomies[ $taxonomy->name ] = $taxonomy->labels->singular_name . ' (' . $taxonomy->name . ')';
		}

		return array(
			'api_key'           => array(
				'label' => __( 'YouTube API key', 'ytbi' ),
				'type'  => 'api_key',
			),
			'per_page'          => array(
				'label' => __( 'Results per page', 'ytbi' ),
				'type'  => 'number',
				'min'   => 1,
				'max'   => 50,
			),
			'post_status'       => array(
				'label'   => __( 'Default post status', 'ytbi' ),
				'type'    => 'select',
				'choices' => self::status_choices(),
			),
			'post_type'         => array(
				'label'   => __( 'Post type', 'ytbi' ),
				'type'    => 'select',
				'choices' => $post_types,
			),
			'category_taxonomy' => array(
				'label'   => __( 'Category taxonomy', 'ytbi' ),
				'type'    => 'select',
				'choices' => $taxonomies,
			),
			'tag_taxonomy'      => array(
				'label'   => __( 'Tag taxonomy', 'ytbi' ),
				'type'    => 'select',
				'choices' => $taxonomies,
			),
			'strip_hashtags'     => array(
				'label'       => __( 'Strip hashtags from titles', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Removes #tags at the end of video titles; inside a sentence only the # is dropped. #1 and similar numbers stay.', 'ytbi' ),
			),
			'strip_emojis'       => array(
				'label'       => __( 'Strip emojis from titles', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Removes emoji from video titles. Symbols like ™ © ® stay.', 'ytbi' ),
			),
			'strip_mentions'     => array(
				'label'       => __( 'Strip @mentions from titles', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Removes @handles at the end of video titles (with a "by", "ft." or "with" before them); inside a sentence only the @ is dropped.', 'ytbi' ),
			),
			'import_description' => array(
				'label'       => __( 'Import description', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Copy the YouTube description into the post content below the video.', 'ytbi' ),
			),
			'import_tags'       => array(
				'label'       => __( 'Import tags', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Copy the video tags from YouTube into the tag taxonomy.', 'ytbi' ),
			),
			'max_tags'          => array(
				'label'       => __( 'Max tags per video', 'ytbi' ),
				'type'        => 'number',
				'min'         => 0,
				'description' => __( 'Only used when tag import is on. 0 means no limit.', 'ytbi' ),
			),
			'featured_image'    => array(
				'label'       => __( 'Featured image', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Download the YouTube thumbnail and set it as the featured image.', 'ytbi' ),
			),
			'featured_image_size' => array(
				'label'       => __( 'Featured image size', 'ytbi' ),
				'type'        => 'select',
				'choices'     => array(
					'best'     => __( 'Best (1280×720 when available)', 'ytbi' ),
					'standard' => __( 'Standard (480×360), faster imports and smaller files', 'ytbi' ),
				),
				'description' => __( 'WordPress also makes every image size your theme registers, so a smaller source is quicker to process.', 'ytbi' ),
			),
			'content_format'    => array(
				'label'       => __( 'Content format', 'ytbi' ),
				'type'        => 'select',
				'choices'     => array(
					'auto'    => __( 'Auto (match the editor of the post type)', 'ytbi' ),
					'block'   => __( 'Block editor', 'ytbi' ),
					'classic' => __( 'Classic editor', 'ytbi' ),
				),
				'description' => __( 'Both formats render the same player through oEmbed.', 'ytbi' ),
			),
			'store_duration'    => array(
				'label'       => __( 'Store duration', 'ytbi' ),
				'type'        => 'checkbox',
				'description' => __( 'Save the video length in seconds as hidden post meta _ytbi_duration.', 'ytbi' ),
			),
		);
	}

	/**
	 * Render one field.
	 */
	public static function render_field( $args ) {
		$value = self::get( $args['key'] );
		$name  = self::OPTION . '[' . $args['key'] . ']';
		$id    = $args['label_for'];

		switch ( $args['type'] ) {
			case 'api_key':
				$saved       = (string) $value;
				$placeholder = '' === $saved
					? ''
					: sprintf( __( 'Saved key ending in %s. Leave empty to keep it.', 'ytbi' ), substr( $saved, -4 ) );
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $placeholder )
				);
				printf(
					' <button type="button" id="ytbi_test_key" class="button">%1$s</button> <span id="ytbi_test_key_result" class="ytbi-key-result" aria-live="polite"></span>',
					esc_html__( 'Test key', 'ytbi' )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s"%5$s class="small-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $args['min'] ),
					isset( $args['max'] ) ? ' max="' . esc_attr( (string) $args['max'] ) . '"' : ''
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $args['choices'] as $choice => $label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $choice ),
						selected( $value, $choice, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( ytbi_capability() ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'YouTube Bulk Importer Settings', 'ytbi' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'ytbi' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<script type="application/json" id="ytbi-settings-config"><?php echo wp_json_encode( self::script_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
		</div>
		<?php
	}

	/**
	 * Data for settings.js.
	 */
	private static function script_config() {
		return array(
			'root'   => esc_url_raw( rest_url( YTBI_REST::NS . '/' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'hasKey' => '' !== (string) self::get( 'api_key' ),
			'i18n'   => array(
				'testing'    => __( 'Testing…', 'ytbi' ),
				'validTyped' => __( 'Valid. Save the settings to use this key.', 'ytbi' ),
				'validSaved' => __( 'The saved key is valid.', 'ytbi' ),
				'failed'     => __( 'The test could not run. Try again.', 'ytbi' ),
			),
		);
	}
}
