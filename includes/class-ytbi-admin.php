<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registers the YT Importer top level menu, the import page and its assets,
 * and the Imported Videos view
 */
class YTBI_Admin {

	const SLUG = 'ytbi-import';

	/**
	 * Page hook suffix.
	 */
	private static $hook = '';

	/**
	 * Add hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_imported' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );

		$post_type = YTBI_Settings::get( 'post_type' );
		add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_column' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Posts list URL filtered to imported videos.
	 */
	public static function list_url() {
		return 'edit.php?post_type=' . YTBI_Settings::get( 'post_type' ) . '&ytbi_imported=1';
	}

	/**
	 * True on the posts list screen opened from "Imported Videos".
	 */
	private static function is_list_request() {
		if ( empty( $_GET['ytbi_imported'] ) || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && 'edit' === $screen->base;
	}

	/**
	 * Limit the posts list to imported videos.
	 */
	public static function filter_imported( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! self::is_list_request() ) {
			return;
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => YTBI_Importer::META_ID,
					'compare' => 'EXISTS',
				),
			)
		);
	}

	/**
	 * Keep the YT Importer menu open on the filtered list.
	 */
	public static function parent_file( $parent_file ) {
		return self::is_list_request() ? self::SLUG : $parent_file;
	}

	/**
	 * Highlight "Imported Videos" on the filtered list.
	 */
	public static function submenu_file( $submenu_file ) {
		return self::is_list_request() ? self::list_url() : $submenu_file;
	}

	/**
	 * Add a YouTube column on the filtered list.
	 */
	public static function add_column( $columns ) {
		if ( self::is_list_request() ) {
			$columns['ytbi_video'] = __( 'YouTube', 'ytbi' );
		}
		return $columns;
	}

	/**
	 * Render the YouTube column.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'ytbi_video' !== $column ) {
			return;
		}
		$video_id = (string) get_post_meta( $post_id, YTBI_Importer::META_ID, true );
		if ( '' === $video_id ) {
			return;
		}
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ) ),
			esc_html( $video_id )
		);
	}

	/**
	 * Add the top-level menu: Import Videos, Imported Videos.
	 */
	public static function menu() {
		$capability = ytbi_capability();

		self::$hook = (string) add_menu_page(
			__( 'YouTube Import', 'ytbi' ),
			__( 'YouTube Importer', 'ytbi' ),
			$capability,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-video-alt3',
			26
		);
		add_submenu_page( self::SLUG, __( 'YouTube Import', 'ytbi' ), __( 'Import Videos', 'ytbi' ), $capability, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Imported Videos', 'ytbi' ), __( 'Imported Videos', 'ytbi' ), $capability, self::list_url() );
	}

	/**
	 * Enqueue assets on the plugin page only.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ytbi-admin', YTBI_URL . 'assets/css/admin.css', array(), YTBI_VERSION . '.' . filemtime( YTBI_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'ytbi-import', YTBI_URL . 'assets/js/import.js', array(), YTBI_VERSION . '.' . filemtime( YTBI_DIR . 'assets/js/import.js' ), true );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( ytbi_capability() ) ) {
			return;
		}

		$has_key      = '' !== (string) YTBI_Settings::get( 'api_key' );
		$settings_url = admin_url( 'admin.php?page=' . YTBI_Settings::PAGE );
		$config       = array(
			'root'        => esc_url_raw( rest_url( YTBI_REST::NS . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'settingsUrl' => $settings_url,
			'i18n'        => array(
				'noCategory' => __( '— No category —', 'ytbi' ),
				'noResults'  => __( 'No videos match.', 'ytbi' ),
				'queued'     => __( 'Queued', 'ytbi' ),
				'importing'  => __( 'Importing…', 'ytbi' ),
				'imported'   => __( 'Imported', 'ytbi' ),
				'editPost'   => __( 'Edit post', 'ytbi' ),
				'views'      => __( '%s views', 'ytbi' ),
			),
		);

		include YTBI_DIR . 'includes/view-import.php';
	}
}
