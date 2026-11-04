<?php
/**
 * Plugin Name:       YouTube Bulk Importer
 * Description:       Search YouTube and bulk-import videos as posts. The video is stored as a URL in the post content and rendered by WordPress oEmbed.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       ytbi
 * Domain Path:       /languages
 * License:           GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'YTBI_VERSION', '1.0.0' );
define( 'YTBI_DIR', plugin_dir_path( __FILE__ ) );
define( 'YTBI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load translations.
 */
function ytbi_load_textdomain() {
	load_plugin_textdomain( 'ytbi', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * Write a debug line when WP_DEBUG_LOG is on.
 */
function ytbi_log( $message ) {
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[ytbi] ' . $message );
	}
}

/**
 * Capability required to use the plugin.
 */
function ytbi_capability() {
	return apply_filters( 'ytbi_capability', 'manage_options' );
}

require_once YTBI_DIR . 'includes/class-ytbi-video-source.php';
require_once YTBI_DIR . 'includes/class-ytbi-youtube.php';
require_once YTBI_DIR . 'includes/class-ytbi-importer.php';
require_once YTBI_DIR . 'includes/class-ytbi-container.php';
require_once YTBI_DIR . 'includes/class-ytbi-settings.php';
require_once YTBI_DIR . 'includes/class-ytbi-admin.php';
require_once YTBI_DIR . 'includes/class-ytbi-rest.php';

add_action( 'rest_api_init', array( 'YTBI_REST', 'register_routes' ) );

// Settings are cached per request; drop the cache whenever the option changes, on any request.
foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $ytbi_hook ) {
	add_action( $ytbi_hook . YTBI_Settings::OPTION, array( 'YTBI_Settings', 'flush' ) );
}

if ( is_admin() ) {
	add_action( 'init', 'ytbi_load_textdomain' );
	YTBI_Settings::init();
	YTBI_Admin::init();
}
