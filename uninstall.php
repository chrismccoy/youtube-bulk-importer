<?php
/**
 * Uninstall: remove settings only. Imported posts, terms, and meta stay.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ytbi_settings' );
