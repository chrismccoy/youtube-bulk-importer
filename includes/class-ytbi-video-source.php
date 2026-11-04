<?php
/**
 * YouTube video source contract.
 */

defined( 'ABSPATH' ) || exit;

interface YTBI_Video_Source {

	/**
	 * Search by keyword, channel, or playlist.
	 */
	public function search( array $args );

	/**
	 * Fetch one video by ID.
	 */
	public function get_video( $id, $needs_description = true );
}
