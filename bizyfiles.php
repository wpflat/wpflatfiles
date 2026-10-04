<?php
/**
 * Plugin Name: BizyFiles
 * Description: A file manager that looks like File Explorer: folders, versions, previews, sharing, and folder-level access for people and groups. Works in the app and on the front end for your users. Needs BizyCore.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: bizyfiles
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZYFILES_VERSION', '0.3.0' );
define( 'BIZYFILES_FILE', __FILE__ );
define( 'BIZYFILES_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZYFILES_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'BIZYCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>BizyFiles</strong> needs the <strong>BizyCore</strong> plugin. Install and activate BizyCore, then BizyFiles will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'BizyFiles\\' ) ) {
					$path = BIZYFILES_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 10 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		BizyFiles\Module::init();
	},
	1
);
