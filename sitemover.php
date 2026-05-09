<?php
/**
 * Plugin Name: SiteMover
 * Plugin URI:  https://github.com/RnBConversion/sitemover
 * Description: Export your WordPress site (database + wp-content) to a ZIP and import it on another server with automatic URL replacement.
 * Version:     1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:      rolandasb
 * License:     GPL-2.0+
 * Text Domain: sitemover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITEMOVER_VERSION', '1.1.0' );
define( 'SITEMOVER_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SITEMOVER_URL',     plugin_dir_url( __FILE__ ) );

$sitemover_upload_dir = wp_upload_dir();
define( 'SITEMOVER_EXPORT_DIR', $sitemover_upload_dir['basedir'] . '/sitemover-exports/' );
unset( $sitemover_upload_dir );

require_once SITEMOVER_DIR . 'includes/class-sitemover-exporter.php';
require_once SITEMOVER_DIR . 'includes/class-sitemover-importer.php';
require_once SITEMOVER_DIR . 'includes/class-sitemover-admin.php';

add_action( 'plugins_loaded', function () {
	new SiteMover_Admin();
} );

register_activation_hook( __FILE__, function () {
	if ( ! file_exists( SITEMOVER_EXPORT_DIR ) ) {
		wp_mkdir_p( SITEMOVER_EXPORT_DIR );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	$htaccess = SITEMOVER_EXPORT_DIR . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$wp_filesystem->put_contents( $htaccess, 'Deny from all', FS_CHMOD_FILE );
	}
	if ( ! file_exists( SITEMOVER_EXPORT_DIR . 'index.php' ) ) {
		$wp_filesystem->put_contents( SITEMOVER_EXPORT_DIR . 'index.php', '<?php // Silence is golden.', FS_CHMOD_FILE );
	}
} );
