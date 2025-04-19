<?php
/**
 * Constants for WP Seamless Update plugin
 *
 * @package WP_Seamless_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WPSU_PLUGIN_SLUG', 'wp-seamless-update' );
define( 'WPSU_OPTION_GROUP', 'wpsu_options_group' );
define( 'WPSU_OPTION_NAME', 'wpsu_settings' );
define( 'WPSU_BACKUP_DIR_BASE', 'wpsu-backups' ); // Relative to wp-content/uploads
define( 'WPSU_TEMP_UPDATE_DIR_BASE', 'wpsu-temp-update' ); // Relative to wp-content/uploads
define( 'WPSU_DEFAULT_BACKUPS_TO_KEEP', 3 );
define( 'WPSU_PLUGIN_FILE', plugin_basename( dirname( dirname( __FILE__ ) ) . '/wp-seamless-update.php' ) );
