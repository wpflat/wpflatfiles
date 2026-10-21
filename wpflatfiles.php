<?php
/**
 * Plugin Name: WPFlat Files
 * Description: A file manager that looks like File Explorer: folders, versions, previews, sharing, and folder-level access for people and groups. Works in the app and on the front end for your users. Needs WPFlat Core.
 * Version: 0.4.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: wpflatfiles
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFLATFILES_VERSION', '0.4.1' );
define( 'WPFLATFILES_FILE', __FILE__ );
define( 'WPFLATFILES_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFLATFILES_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'WPFLATCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>WPFlat Files</strong> needs the <strong>WPFlat Core</strong> plugin. Install and activate WPFlat Core, then WPFlat Files will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'WPFlatFiles\\' ) ) {
					$path = WPFLATFILES_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		WPFlatFiles\Module::init();
	},
	1
);
