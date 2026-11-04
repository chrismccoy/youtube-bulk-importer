<?php

defined( 'ABSPATH' ) || exit;

/**
 * Calls the YouTube Data API
 */
class YTBI_YouTube implements YTBI_Video_Source {

	const BASE = 'https://www.googleapis.com/youtube/v3/';

	// Search pages are cached so repeat searches and "Load more" cost no quota.
	const SEARCH_TTL = 15 * MINUTE_IN_SECONDS;

	const RECENT_KEY = 'ytbi_recent_videos';
	const RECENT_MAX = 300;

	// Handle/username => channel ID, one option row for all lookups.
	const CHANNELS_KEY = 'ytbi_channels';
	const CHANNELS_MAX = 200;

	/**
	 * API key.
	 */
	private $api_key;

	/**
	 * Constructor.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Search by keyword, channel, or playlist.
	 */
	public function search( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'q'          => '',
				'type'       => 'keyword',
				'order'      => 'relevance',
				'published'  => 'any',
				'page_token' => '',
				'per_page'   => 24,
			)
		);
		$max  = min( 50, max( 1, (int) $args['per_page'] ) );

		if ( 'playlist' === $args['type'] ) {
			$endpoint = 'playlistItems';
			$query    = array(
				'part'       => 'contentDetails',
				'playlistId' => self::playlist_id( $args['q'] ),
				'maxResults' => $max,
			);
		} else {
			$endpoint = 'search';
			$query    = array(
				'part'       => 'id',
				'type'       => 'video',
				'maxResults' => $max,
				'order'      => $args['order'],
			);
			if ( 'channel' === $args['type'] ) {
				$channel_id = $this->resolve_channel( $args['q'] );
				if ( is_wp_error( $channel_id ) ) {
					return self::explain_bad_id( $channel_id, 'channel' );
				}
				$query['channelId'] = $channel_id;
			} else {
				$query['q'] = $args['q'];
			}
			if ( in_array( $args['published'], array( 'hour', 'day', 'week', 'month', 'year' ), true ) ) {
				$query['publishedAfter'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 ' . $args['published'] ) );
			}
		}

		if ( '' !== (string) $args['page_token'] ) {
			$query['pageToken'] = $args['page_token'];
		}

		$cache_key = 'ytbi_s_' . md5( $endpoint . '?' . http_build_query( $query, '', '&' ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = $this->request( $endpoint, $query );
		if ( is_wp_error( $data ) ) {
			return self::explain_bad_id( $data, $args['type'] );
		}

		$ids = array();
		foreach ( isset( $data['items'] ) ? $data['items'] : array() as $item ) {
			$id = 'playlist' === $args['type']
				? ( $item['contentDetails']['videoId'] ?? '' )
				: ( $item['id']['videoId'] ?? '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		$videos = $ids ? $this->videos( $ids ) : array();
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		$result = array(
			'videos'          => $videos,
			'next_page_token' => $data['nextPageToken'] ?? null,
			'prev_page_token' => $data['prevPageToken'] ?? null,
		);
		set_transient( $cache_key, $result, self::SEARCH_TTL );
		self::remember( $videos );

		return $result;
	}

	/**
	 * Keep compact copies of search results so an import can skip videos.list.
	 */
	private static function remember( array $videos ) {
		if ( ! $videos ) {
			return;
		}
		$map = get_transient( self::RECENT_KEY );
		$map = is_array( $map ) ? $map : array();
		foreach ( $videos as $video ) {
			unset( $map[ $video['id'] ] );
			$video['description'] = null;
			$map[ $video['id'] ]  = $video;
		}
		if ( count( $map ) > self::RECENT_MAX ) {
			$map = array_slice( $map, -self::RECENT_MAX, null, true );
		}
		set_transient( self::RECENT_KEY, $map, self::SEARCH_TTL );
	}

	/**
	 * Channel ID from a UC… ID, @handle, bare handle, or channel URL
	 */
	private function resolve_channel( $input ) {
		$input = trim( (string) $input );
		if ( preg_match( '~(?:^|/channel/)(UC[A-Za-z0-9_-]{22})(?:[/?#]|$)~', $input, $m ) ) {
			return $m[1];
		}

		if ( preg_match( '~/user/([^/?#]+)~', $input, $m ) ) {
			$by    = 'forUsername';
			$value = rawurldecode( $m[1] );
		} elseif ( preg_match( '~(@[^/?#\s]+)~', $input, $m ) ) {
			$by    = 'forHandle';
			$value = rawurldecode( $m[1] );
		} else {
			$by    = 'forHandle';
			$value = $input;
		}

		$lookup   = $by . ':' . strtolower( $value );
		$channels = get_transient( self::CHANNELS_KEY );
		$channels = is_array( $channels ) ? $channels : array();
		if ( ! empty( $channels[ $lookup ] ) ) {
			return $channels[ $lookup ];
		}

		$data = $this->request(
			'channels',
			array(
				'part' => 'id',
				$by    => $value,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['items'][0]['id'] ) ) {
			return new WP_Error( 'ytbi_not_found', __( 'Channel not found. Check the @handle, channel URL, or channel ID.', 'ytbi' ), array( 'status' => 404 ) );
		}

		$channel_id          = (string) $data['items'][0]['id'];
		$channels[ $lookup ] = $channel_id;
		if ( count( $channels ) > self::CHANNELS_MAX ) {
			$channels = array_slice( $channels, -self::CHANNELS_MAX, null, true );
		}
		set_transient( self::CHANNELS_KEY, $channels, DAY_IN_SECONDS );
		return $channel_id;
	}

	/**
	 * Playlist ID from a playlist ID or any YouTube URL with list=.
	 */
	private static function playlist_id( $input ) {
		$input = trim( (string) $input );
		return preg_match( '~[?&]list=([A-Za-z0-9_-]+)~', $input, $m ) ? $m[1] : $input;
	}

	/**
	 * One video by ID. When the description is not needed, a copy remembered from
	 * a recent search is returned instead of calling the API
	 */
	public function get_video( $id, $needs_description = true ) {
		if ( ! $needs_description ) {
			$map = get_transient( self::RECENT_KEY );
			if ( is_array( $map ) && isset( $map[ $id ] ) ) {
				return $map[ $id ];
			}
		}

		$videos = $this->videos( array( $id ) );
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}
		if ( empty( $videos ) ) {
			return new WP_Error( 'ytbi_video_missing', __( 'Video not found. It may be private or deleted.', 'ytbi' ), array( 'status' => 404 ) );
		}
		return $videos[0];
	}

	/**
	 * Full details for up to 50 IDs, in the order given. Missing videos are skipped.
	 */
	private function videos( array $ids ) {
		$ids  = array_values( array_unique( $ids ) );
		$data = $this->request(
			'videos',
			array(
				'part'       => 'snippet,contentDetails,statistics',
				'id'         => implode( ',', $ids ),
				'maxResults' => 50,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$by_id = array();
		foreach ( isset( $data['items'] ) ? $data['items'] : array() as $item ) {
			if ( isset( $item['id'] ) ) {
				$by_id[ $item['id'] ] = self::normalize( $item );
			}
		}

		$videos = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$videos[] = $by_id[ $id ];
			}
		}
		return $videos;
	}

	/**
	 * Convert a videos.list item into the plugin's video item.
	 */
	public static function normalize( array $item ) {
		$snippet = $item['snippet'] ?? array();
		$thumbs  = $snippet['thumbnails'] ?? array();
		$pick    = function ( array $sizes ) use ( $thumbs ) {
			foreach ( $sizes as $size ) {
				if ( ! empty( $thumbs[ $size ]['url'] ) ) {
					return (string) $thumbs[ $size ]['url'];
				}
			}
			return '';
		};
		$thumb   = $pick( array( 'maxres', 'standard', 'high', 'medium', 'default' ) );

		return array(
			'id'          => (string) $item['id'],
			'title'       => (string) ( $snippet['title'] ?? '' ),
			'description' => (string) ( $snippet['description'] ?? '' ),
			'tags'        => array_values( array_map( 'strval', $snippet['tags'] ?? array() ) ),
			'thumb_url'   => $thumb,
			'thumb_url_standard' => $pick( array( 'high', 'medium', 'default' ) ),
			'duration_s'  => self::parse_duration( $item['contentDetails']['duration'] ?? '' ),
			'published'   => (string) ( $snippet['publishedAt'] ?? '' ),
			'views'       => isset( $item['statistics']['viewCount'] ) ? (int) $item['statistics']['viewCount'] : null,
			'url'         => 'https://www.youtube.com/watch?v=' . rawurlencode( (string) $item['id'] ),
		);
	}

	/**
	 * ISO 8601 duration (PT1H2M3S) to seconds. Unknown formats return 0.
	 */
	public static function parse_duration( $iso ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', (string) $iso, $m ) ) {
			return 0;
		}
		return (int) ( $m[1] ?? 0 ) * 86400
			+ (int) ( $m[2] ?? 0 ) * 3600
			+ (int) ( $m[3] ?? 0 ) * 60
			+ (int) ( $m[4] ?? 0 );
	}

	/**
	 * Check the key with 1 api call
	 */
	public function test_key() {
		$result = $this->request(
			'videos',
			array(
				'part' => 'id',
				'id'   => 'dQw4w9WgXcQ',
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * GET an API endpoint.
	 */
	private function request( $endpoint, array $query ) {
		$query['key'] = $this->api_key;
		$response     = wp_remote_get( self::BASE . $endpoint . '?' . http_build_query( $query, '', '&' ), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			ytbi_log( 'HTTP error: ' . $response->get_error_message() );
			return new WP_Error( 'ytbi_http', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) {
			$error = self::map_error( is_array( $body ) ? $body : array() );
			ytbi_log( 'API error: ' . $error->get_error_code() . ' ' . $error->get_error_message() );
			return $error;
		}

		return $body;
	}

	/**
	 * Map an API error body to a WP_Error.
	 */
	private static function map_error( array $body ) {
		$reasons = array();
		foreach ( array( 'errors', 'details' ) as $list ) {
			foreach ( $body['error'][ $list ] ?? array() as $entry ) {
				if ( ! empty( $entry['reason'] ) ) {
					$reasons[] = $entry['reason'];
				}
			}
		}

		$quota     = array( 'ytbi_quota', 502, __( 'Daily YouTube API quota used up. It resets at midnight Pacific time.', 'ytbi' ) );
		$bad_key   = array( 'ytbi_bad_key', 502, __( 'YouTube rejected the API key. Check the plugin settings.', 'ytbi' ) );
		$not_found = array( 'ytbi_not_found', 404, __( 'Channel or playlist not found.', 'ytbi' ) );
		$blocked   = array( 'ytbi_key_restricted', 502, __( 'The API key has a website (referrer) or IP restriction that blocks requests from this server. Set the key\'s application restriction to None or allow this server\'s IP address.', 'ytbi' ) );
		$disabled  = array( 'ytbi_api_disabled', 502, __( 'YouTube Data API v3 is not enabled for this key\'s Google Cloud project. Enable it in the Google Cloud console.', 'ytbi' ) );
		$map       = array(
			'quotaExceeded'      => $quota,
			'dailyLimitExceeded' => $quota,
			'keyInvalid'         => $bad_key,
			'API_KEY_INVALID'    => $bad_key,
			'API_KEY_HTTP_REFERRER_BLOCKED' => $blocked,
			'API_KEY_IP_ADDRESS_BLOCKED'    => $blocked,
			'accessNotConfigured' => $disabled,
			'SERVICE_DISABLED'    => $disabled,
			'channelNotFound'    => $not_found,
			'playlistNotFound'   => $not_found,
		);

		foreach ( $reasons as $reason ) {
			if ( isset( $map[ $reason ] ) ) {
				list( $code, $status, $message ) = $map[ $reason ];
				return new WP_Error( $code, $message, array( 'status' => $status ) );
			}
		}

		$message = $body['error']['message'] ?? __( 'Unknown YouTube API error.', 'ytbi' );
		return new WP_Error(
			'ytbi_api',
			(string) $message,
			array(
				'status'  => 502,
				'reasons' => $reasons,
			)
		);
	}

	/**
	 * A malformed channel or playlist ID comes back as a generic "invalid" / "badRequest".
	 * Replace that with a message that says what to fix.
	 */
	private static function explain_bad_id( WP_Error $error, $type ) {
		$data    = $error->get_error_data();
		$reasons = isset( $data['reasons'] ) ? $data['reasons'] : array();
		if ( 'ytbi_api' !== $error->get_error_code() || ! array_intersect( $reasons, array( 'invalid', 'badRequest', 'invalidChannelId' ) ) ) {
			return $error;
		}
		if ( 'channel' === $type ) {
			return new WP_Error( 'ytbi_bad_id', __( 'That does not look like a channel. Enter an @handle, a channel URL, or a channel ID (starts with UC).', 'ytbi' ), array( 'status' => 400 ) );
		}
		if ( 'playlist' === $type ) {
			return new WP_Error( 'ytbi_bad_id', __( 'That does not look like a playlist. Enter the playlist URL or its ID (the part after list=).', 'ytbi' ), array( 'status' => 400 ) );
		}
		return $error;
	}
}
