<?php
namespace BizyFiles;

use BizyCore\Registry;

defined( 'ABSPATH' ) || exit;

/** BizyFiles' connection to BizyCore: it describes itself, then hooks into the shared places. */
class Module {

	public static function init() {
		Install::maybe_upgrade();
		add_action( 'bizycore_register', array( __CLASS__, 'register' ) );
		add_action( 'bizycore_register_link_types', array( Integrations::class, 'link_types' ) );
		add_action( 'bizycore_register_notifications', array( Notify::class, 'types' ) );
		add_action( 'bizycore_register_portal', array( Portal_Page::class, 'register' ) );
		add_action( 'bizycore_register_routes', array( Routes::class, 'register' ) );
		add_action( 'bizycore_daily', array( __CLASS__, 'daily' ), 40 );
		add_filter( 'bizycore_restore_integrity', array( __CLASS__, 'restore_integrity' ), 10, 2 );
		Integrations::init();
		Notify::init();
		Demo::init();
		if ( Registry::enabled( 'files' ) || ! did_action( 'bizycore_ready' ) ) {
			Streamer::init();
			Front_Page::init();
		}
	}

	public static function register() {
		bizycore_register_module(
			array(
				'id'            => 'files',
				'label'         => 'Files',
				'version'       => BIZYFILES_VERSION,
				'requires_core' => '0.1.0',
				'desc'          => 'A File Explorer-style file manager: folders, versions, previews and sharing, with access by folder for people and groups.',
				'icon'          => 'files',
				'route'         => '/files',
				'routes'        => array( 'files' ),
				'cap'           => 'bizyfiles_use',
				'caps'          => array( 'bizyfiles_use', 'bizyfiles_manage' ),
				'resources'     => array(),
				'tables'        => array_map( function ( $t ) {
					return 'bizyfiles_' . $t;
				}, Install::TABLES ),
				'options'       => array( 'bizyfiles_settings' ),
				'refs'          => array(
					'bizyfiles_files'    => array( 'folder_id' => 'bizyfiles_folders' ),
					'bizyfiles_versions' => array( 'file_id' => 'bizyfiles_files' ),
					'bizyfiles_access'   => array( 'folder_id' => 'bizyfiles_folders' ),
					'bizyfiles_shares'   => array( 'file_id' => 'bizyfiles_files' ),
				),
				'file_kinds'    => array( 'files' ),
				'public_paths'  => array( 'share', Front_Page::slug() ),
				'public_params' => array( 'bd_file', 'bd_folder', 'bd_as', 'bd_as_user' ),
				'roles'         => array(
					'bizy_files_team' => array( 'label' => 'Bizy Files Team', 'desc' => 'Manages every folder, file and who can see what.', 'caps' => array( 'bizyfiles_use', 'bizyfiles_manage' ) ),
				),
				'client_manager_caps' => array( 'bizyfiles_use' ),
				'team_manager_caps'   => array( 'bizyfiles_use' ),
				'manager_grants'      => array( 'files' => array( 'label' => 'Files', 'caps' => array( 'bizyfiles_use', 'bizyfiles_manage' ) ) ),
				'menu'          => array(
					array( 'label' => 'File Explorer', 'route' => '/files' ),
					array( 'label' => 'Settings', 'route' => '/files/settings', 'cap' => 'bizyfiles_manage' ),
				),
				'assets'        => array( 'script' => 'bizyfiles.js', 'style' => 'bizyfiles.css', 'explorer_script' => 'bizyfiles-explorer.js', 'explorer_style' => 'bizyfiles-explorer.css' ), // the explorer files are served too; only script and style load in the app
				'assets_dir'    => BIZYFILES_DIR . 'build',
				'wp'            => array( 'dashicons-portfolio', 30 ),
			)
		);
	}

	public static function daily() {
		if ( ! Registry::enabled( 'files' ) || \BizyCore\Backup\Importer::locked() ) {
			return;
		}
		Store::auto_purge();
		Uploads::sweep();
	}

	/** After a restore: say which stored files are missing from the server. */
	public static function restore_integrity( $warn, $mods ) {
		global $wpdb;
		if ( ! Registry::enabled( 'files' ) ) {
			return $warn;
		}
		$missing = 0;
		foreach ( (array) $wpdb->get_results( 'SELECT stored_name FROM ' . Install::table( 'files' ), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
			if ( ! is_file( Store::dir() . '/' . basename( $f['stored_name'] ) ) ) {
				$missing++;
			}
		}
		if ( $missing ) {
			$warn[] = $missing . ' file(s) in BizyFiles are listed but their content is not on the server (the backup was made without files, or a file was removed).';
		}
		return $warn;
	}
}
