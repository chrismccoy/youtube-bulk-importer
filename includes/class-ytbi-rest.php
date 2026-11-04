<?php

defined( 'ABSPATH' ) || exit;

/**
 * Thin REST layer: validates input, checks capability, calls the client or importer.
 */
class YTBI_REST {

	const NS = 'ytbi/v1';

	/**
	 * Register all routes.
	 */
	public static function register_routes() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_load_textdomain' ), 10, 3 );

		register_rest_route(
			self::NS,
			'/bootstrap',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => self::search_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => self::import_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/test-key',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_key' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'api_key' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => function ( $value ) {
							return trim( sanitize_text_field( (string) $value ) );
						},
					),
				),
			)
		);
	}

	/**
	 * Load translations only for this plugin's routes, not on every REST request.
	 */
	public static function maybe_load_textdomain( $result, $server, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/' ) ) {
			ytbi_load_textdomain();
		}
		return $result;
	}

	/**
	 * Permission check for every route.
	 */
	public static function can_manage() {
		return current_user_can( ytbi_capability() );
	}

	/**
	 * Search route args.
	 */
	private static function search_args() {
		return array(
			'q'          => array(
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'       => array(
				'type'    => 'string',
				'enum'    => array( 'keyword', 'channel', 'playlist' ),
				'default' => 'keyword',
			),
			'order'      => array(
				'type'    => 'string',
				'enum'    => array( 'relevance', 'date', 'viewCount', 'rating', 'title' ),
				'default' => 'relevance',
			),
			'published'  => array(
				'type'    => 'string',
				'enum'    => array( 'any', 'hour', 'day', 'week', 'month', 'year' ),
				'default' => 'any',
			),
			'page_token' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Import route args.
	 */
	private static function import_args() {
		return array(
			'video_id'    => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z0-9_-]{11}$',
			),
			'title'       => array(
				'type' => 'string',
			),
			'description' => array(
				'type' => 'string',
			),
			'tags'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'category'    => array(
				'type'              => 'integer',
				'validate_callback' => array( __CLASS__, 'validate_category' ),
			),
			'status'      => array(
				'type' => 'string',
				'enum' => YTBI_Settings::statuses(),
			),
		);
	}

	/**
	 * Category must be an integer term ID in the configured taxonomy.
	 */
	public static function validate_category( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! term_exists( (int) $value, YTBI_Settings::get( 'category_taxonomy' ) ) ) {
			return new WP_Error( 'ytbi_bad_category', __( 'Unknown category.', 'ytbi' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * GET /bootstrap.
	 */
	public static function bootstrap() {
		$terms = get_terms(
			array(
				'taxonomy'   => YTBI_Settings::get( 'category_taxonomy' ),
				'hide_empty' => false,
				'orderby'    => 'name',
				/**
				 * Max categories in the import toolbar.
				 */
				'number'     => max( 1, (int) apply_filters( 'ytbi_category_limit', 1000 ) ),
			)
		);

		$categories = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$categories[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}

		$statuses = array();
		foreach ( YTBI_Settings::status_choices() as $value => $label ) {
			$statuses[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return rest_ensure_response(
			array(
				'categories'  => $categories,
				'statuses'    => $statuses,
				'post_status' => YTBI_Settings::get( 'post_status' ),
				'per_page'    => (int) YTBI_Settings::get( 'per_page' ),
				'import_description' => (bool) YTBI_Settings::get( 'import_description' ),
				'import_tags' => (bool) YTBI_Settings::get( 'import_tags' ),
				'max_tags'    => (int) YTBI_Settings::get( 'max_tags' ),
				'has_api_key' => '' !== (string) YTBI_Settings::get( 'api_key' ),
			)
		);
	}

	/**
	 * GET /search.
	 */
	public static function search( WP_REST_Request $request ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$args = array();
		foreach ( array( 'q', 'type', 'order', 'published', 'page_token' ) as $key ) {
			$args[ $key ] = $request->get_param( $key );
		}
		$args['per_page'] = (int) YTBI_Settings::get( 'per_page' );

		$result = $client->search( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$existing = YTBI_Importer::find_existing( wp_list_pluck( $result['videos'], 'id' ) );
		$settings = YTBI_Settings::all();
		foreach ( $result['videos'] as $index => $video ) {
			$result['videos'][ $index ]['title'] = YTBI_Importer::clean_title( $video['title'], $settings );
			$post_id                                 = isset( $existing[ $video['id'] ] ) ? $existing[ $video['id'] ] : null;
			$result['videos'][ $index ]['imported'] = null !== $post_id;
			$result['videos'][ $index ]['post_id']  = $post_id;
			$result['videos'][ $index ]['edit_url'] = $post_id ? get_edit_post_link( $post_id, 'raw' ) : null;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /import.
	 */
	public static function import( WP_REST_Request $request ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$edits = array();
		foreach ( array( 'title', 'description', 'tags', 'category', 'status' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$edits[ $key ] = $request->get_param( $key );
			}
		}

		$result = YTBI_Container::importer( $client )->import( $request->get_param( 'video_id' ), $edits );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /test-key. Tests the typed key, or the saved key when none is typed.
	 */
	public static function test_key( WP_REST_Request $request ) {
		$typed  = (string) $request->get_param( 'api_key' );
		$source = '' === $typed ? 'saved' : 'typed';
		$client = 'typed' === $source ? new YTBI_YouTube( $typed ) : self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->test_key();
		$data   = array(
			'valid'  => true,
			'source' => $source,
		);

		if ( is_wp_error( $result ) && 'ytbi_quota' === $result->get_error_code() ) {
			$data['warning'] = $result->get_error_message();
		} elseif ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * API client, or an error when no key is set.
	 */
	private static function client() {
		return YTBI_Container::youtube();
	}
}
