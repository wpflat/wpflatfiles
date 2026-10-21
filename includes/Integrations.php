<?php
namespace WPFlatFiles;

use WPFlatCore\Links;
use WPFlatCore\Registry;

defined( 'ABSPATH' ) || exit;

/** How WPFlat Files shows up in WPFlat Core's shared places: links, search, clean-up when clients or groups go away. */
class Integrations {

	public static function init() {
		add_filter( 'wpflatcore_search', array( __CLASS__, 'search' ), 10, 2 );
		add_filter( 'wpflatcore_meta', array( __CLASS__, 'meta' ) );
		add_action( 'wpflatcore_client_deleted', array( __CLASS__, 'client_deleted' ) );
		add_action( 'wpflatcore_group_deleted', array( __CLASS__, 'group_deleted' ) );
		add_filter( 'wpflatcore_home', array( __CLASS__, 'home' ) );
	}

	private static function on() {
		return Registry::enabled( 'files' );
	}

	public static function meta( $m ) {
		if ( ! self::on() ) {
			return $m;
		}
		$m['settings'] = array_merge( isset( $m['settings'] ) ? (array) $m['settings'] : array(), array( 'files_theme' => Settings::get( 'theme' ) ) );
		return $m;
	}

	public static function client_deleted( $client_id ) {
		Access::forget_subject( 'client', $client_id );
	}

	public static function group_deleted( $group_id ) {
		Access::forget_subject( 'group', $group_id );
	}

	public static function link_types() {
		Links::register_type( 'file', array( 'module' => 'files', 'label' => 'File', 'plural' => 'Files', 'resolve' => array( __CLASS__, 'resolve_file' ), 'search' => array( __CLASS__, 'search_files' ) ) );
	}

	public static function resolve_file( $id ) {
		$f = Store::file_row( $id );
		return ( $f && null === $f['deleted_at'] ) ? array( 'title' => $f['name'], 'url' => '#/files?folder=' . (int) $f['folder_id'], 'sub' => size_format( (int) $f['size'] ) ) : null;
	}

	public static function search_files( $q, $n ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, size FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NULL AND name LIKE %s ORDER BY id DESC LIMIT %d', '%' . $wpdb->esc_like( $q ) . '%', $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'title' => $r['name'], 'sub' => size_format( (int) $r['size'] ) );
		}, (array) $rows );
	}

	public static function search( $out, $q ) {
		global $wpdb;
		if ( ! self::on() || ! Access::can_use_api() ) {
			return $out;
		}
		$v    = Access::viewer();
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, folder_id, name, size FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NULL AND name LIKE %s ORDER BY id DESC LIMIT 20', $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( Access::level( (int) $r['folder_id'], $v ) < 1 ) {
				continue;
			}
			$out[] = array( 'type' => 'file', 'kind' => 'File', 'id' => (int) $r['id'], 'label' => $r['name'], 'sub' => size_format( (int) $r['size'] ), 'route' => '/files?folder=' . (int) $r['folder_id'] );
			if ( count( array_filter( $out, function ( $x ) {
				return 'file' === $x['type'];
			} ) ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	public static function home( $home ) {
		global $wpdb;
		if ( ! self::on() || ! Access::is_staff() || ! Access::can_use_api() ) {
			return $home;
		}
		$home['stats']['files_total'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NULL' ); // phpcs:ignore WordPress.DB
		return $home;
	}
}
