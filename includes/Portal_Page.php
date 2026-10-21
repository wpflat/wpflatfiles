<?php
namespace WPFlatFiles;

use WPFlatCore\Assets;
use WPFlatCore\Portal;
use WPFlatCore\Urls;

defined( 'ABSPATH' ) || exit;

/** The front end: the "Files" page of the portal is the file explorer, for the person who is signed in. */
class Portal_Page {

	public static function register() {
		Portal::register_page( 'files', array(
			'module'   => 'files',
			'label'    => 'Files',
			'order'    => 35,
			'render'   => array( __CLASS__, 'render' ),
			'overview' => array( __CLASS__, 'overview' ),
		) );
	}

	/** One overview card: how many files this person can open. */
	public static function overview( $ctx ) {
		global $wpdb;
		$v   = Access::viewer();
		$ids = array_keys( Access::visible( $v ) );
		$n   = 0;
		if ( $ids ) {
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NULL AND folder_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB
		}
		return array(
			'cards'     => '<a class="stat" href="' . esc_url( Portal::link( 'files' ) ) . '"><span class="stat__l">Files</span><span class="stat__v">' . (int) $n . '</span><span class="stat__s">' . ( 1 === count( $ids ) ? '1 folder' : count( $ids ) . ' folders' ) . '</span></a>',
			'attention' => '',
			'panels'    => '',
		);
	}

	/** The mount point plus what the explorer needs to start. The explorer is one script that brings its own Vue. */
	public static function mount_html( $mode, array $extra = array() ) {
		$boot = array_merge( array(
			'mode'    => $mode,
			'api'     => untrailingslashit( Urls::api() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'config'  => Routes::client_config(),
			'brand'   => (string) \WPFlatCore\Settings::get( 'business_name' ),
		), $extra );
		$js  = Assets::module_url( 'files', 'wpflatfiles-explorer.js' );
		$css = Assets::module_url( 'files', 'wpflatfiles-explorer.css' );
		return '<link rel="stylesheet" href="' . esc_url( $css ) . '"><div id="wpflatfiles-root" class="wpflatfiles-root wpflatfiles-' . esc_attr( $mode ) . '"></div>'
			. '<script>window.WPFlatFilesBoot=' . wp_json_encode( $boot ) . ';</script>'
			. '<script src="' . esc_url( $js ) . '" defer></script>';
	}

	public static function render( $ctx, $id, $sub ) {
		$extra = array();
		if ( ! empty( $ctx['preview'] ) ) {
			$_GET['bd_as'] = (int) $ctx['client']['id']; // this request, too, is computed as that client
			$extra['as'] = (int) $ctx['client']['id']; // staff previewing the portal as this client: the explorer sends it with every request
		}
		return '<h1 style="margin-bottom:12px">Files</h1>' . self::mount_html( 'portal', $extra );
	}
}
