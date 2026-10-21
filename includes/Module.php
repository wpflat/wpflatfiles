<?php
namespace WPFlatFiles;

use WPFlatCore\Registry;

defined( 'ABSPATH' ) || exit;

/** WPFlat Files' connection to WPFlat Core: it describes itself, then hooks into the shared places. */
class Module {

	public static function init() {
		Install::maybe_upgrade();
		add_action( 'wpflatcore_register', array( __CLASS__, 'register' ) );
		add_action( 'wpflatcore_register_link_types', array( Integrations::class, 'link_types' ) );
		add_action( 'wpflatcore_register_notifications', array( Notify::class, 'types' ) );
		add_action( 'wpflatcore_register_portal', array( Portal_Page::class, 'register' ) );
		add_action( 'wpflatcore_register_routes', array( Routes::class, 'register' ) );
		add_action( 'wpflatcore_daily', array( __CLASS__, 'daily' ), 40 );
		add_filter( 'wpflatcore_restore_integrity', array( __CLASS__, 'restore_integrity' ), 10, 2 );
		Integrations::init();
		Notify::init();
		Demo::init();
		if ( Registry::enabled( 'files' ) || ! did_action( 'wpflatcore_ready' ) ) {
			Streamer::init();
			Front_Page::init();
		}
	}

	public static function register() {
		wpflatcore_register_module(
			array(
				'id'            => 'files',
				'label'         => 'Files',
				'version'       => WPFLATFILES_VERSION,
				'requires_core' => '0.6.0',
				'desc'          => 'A File Explorer-style file manager: folders, versions, previews and sharing, with access by folder for people and groups.',
				'icon'          => 'files',
				'route'         => '/files',
				'routes'        => array( 'files' ),
				'cap'           => 'wpflatfiles_use',
				'caps'          => array( 'wpflatfiles_use', 'wpflatfiles_view_all', 'wpflatfiles_upload', 'wpflatfiles_edit', 'wpflatfiles_manage' ),
				'resources'     => array(),
				'tables'        => array_map( function ( $t ) {
					return 'wpflatfiles_' . $t;
				}, Install::TABLES ),
				'options'       => array( 'wpflatfiles_settings' ),
				'refs'          => array(
					'wpflatfiles_files'    => array( 'folder_id' => 'wpflatfiles_folders' ),
					'wpflatfiles_versions' => array( 'file_id' => 'wpflatfiles_files' ),
					'wpflatfiles_access'   => array( 'folder_id' => 'wpflatfiles_folders' ),
					'wpflatfiles_shares'   => array( 'file_id' => 'wpflatfiles_files' ),
				),
				'file_kinds'    => array( 'files' ),
				'public_paths'  => array( 'share', Front_Page::slug() ),
				'front'         => array( 'url' => function () { return Settings::get( 'front_enabled' ) ? Front_Page::url() : ''; } ),
				'public_params' => array( 'bd_file', 'bd_folder', 'bd_as', 'bd_as_user' ),
				'roles'         => array(
					'wpflat_files_team'       => array( 'label' => 'Files Admin', 'desc' => 'Full control of Files: every folder, who can see what, the recycle bin. Also opens the WPFlat app.', 'caps' => array( 'wpflatfiles_use', 'wpflatfiles_view_all', 'wpflatfiles_upload', 'wpflatfiles_edit', 'wpflatfiles_manage' ) ),
					'wpflat_file_manager'     => array( 'label' => 'File Manager', 'desc' => 'Can create, rename, move, delete and upload folders and files in every folder, and share files. Cannot change who has access. Uses the front-end Files page.', 'caps' => array( 'wpflatfiles_use', 'wpflatfiles_view_all', 'wpflatfiles_upload', 'wpflatfiles_edit' ), 'staff' => false ),
					'wpflat_file_contributor' => array( 'label' => 'File Contributor', 'desc' => 'Can see every folder and add files to any of them. Cannot delete, rename or create folders. Uses the front-end Files page.', 'caps' => array( 'wpflatfiles_use', 'wpflatfiles_view_all', 'wpflatfiles_upload' ), 'staff' => false ),
					'wpflat_file_viewer'      => array( 'label' => 'File Viewer', 'desc' => 'Can only view and download all folders and files. Uses the front-end Files page.', 'caps' => array( 'wpflatfiles_use', 'wpflatfiles_view_all' ), 'staff' => false ),
				),
				'client_manager_caps' => array( 'wpflatfiles_use' ),
				'team_manager_caps'   => array( 'wpflatfiles_use' ),
				'manager_grants'      => array( 'files' => array( 'label' => 'Files', 'caps' => array( 'wpflatfiles_use', 'wpflatfiles_manage' ) ) ),
				'menu'          => array(
					array( 'label' => 'File Explorer', 'route' => '/files' ),
					array( 'label' => 'Settings', 'route' => '/files/settings', 'cap' => 'wpflatfiles_manage' ),
				),
				'assets'        => array( 'script' => 'wpflatfiles.js', 'style' => 'wpflatfiles.css', 'explorer_script' => 'wpflatfiles-explorer.js', 'explorer_style' => 'wpflatfiles-explorer.css' ), // the explorer files are served too; only script and style load in the app
				'assets_dir'    => WPFLATFILES_DIR . 'build',
				'wp'            => array( 'dashicons-portfolio', 30 ),
			)
		);
	}

	public static function daily() {
		if ( ! Registry::enabled( 'files' ) || \WPFlatCore\Backup\Importer::locked() ) {
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
			$warn[] = $missing . ' file(s) in WPFlat Files are listed but their content is not on the server (the backup was made without files, or a file was removed).';
		}
		return $warn;
	}
}
