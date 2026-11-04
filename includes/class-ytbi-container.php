<?php
/**
 * Minimal service locator
 */

defined( 'ABSPATH' ) || exit;

final class YTBI_Container {

	/**
	 * API client bound to the configured key, or a WP_Error when no key is set.
	 */
	public static function youtube() {
		$key = (string) YTBI_Settings::get( 'api_key' );
		if ( '' === $key ) {
			return new WP_Error(
				'ytbi_no_key',
				__( 'Add a YouTube API key in YT Importer → Settings first.', 'ytbi' ),
				array( 'status' => 400 )
			);
		}
		return new YTBI_YouTube( $key );
	}

	/**
	 * Importer given video source.
	 */
	public static function importer( YTBI_Video_Source $source ) {
		return new YTBI_Importer( $source );
	}
}
