<?php
namespace WPFlatFiles;

use WPFlatCore\Rest\Routes as Core_Routes;

defined( 'ABSPATH' ) || exit;

/** WPFlat Files' REST routes (in WPFlat Core's wpflatcore/v1 namespace, under /fm/). Every route checks folder access itself. */
class Routes {

	private static function reg( $route, $args ) {
		Core_Routes::add( 'files', $route, $args );
	}

	private static function out( $x ) {
		return is_wp_error( $x ) ? $x : rest_ensure_response( $x );
	}

	private static function denied() {
		return new \WP_Error( 'wpflatfiles_denied', 'You do not have access to that.', array( 'status' => 403 ) );
	}

	public static function register() {
		$use  = array( Access::class, 'can_use_api' );
		$m    = function ( $methods, $cb ) use ( $use ) {
			return array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => $use );
		};
		self::reg( '/fm/config', $m( 'GET', array( __CLASS__, 'config' ) ) );
		self::reg( '/fm/prefs', $m( 'POST', array( __CLASS__, 'prefs' ) ) );
		self::reg( '/fm/tree', $m( 'GET', array( __CLASS__, 'tree' ) ) );
		self::reg( '/fm/list', $m( 'GET', array( __CLASS__, 'listing' ) ) );
		self::reg( '/fm/folders', $m( 'POST', array( __CLASS__, 'folder_create' ) ) );
		self::reg( '/fm/folders/(?P<id>\d+)', array(
			$m( 'PATCH', array( __CLASS__, 'folder_update' ) ),
			$m( 'DELETE', array( __CLASS__, 'folder_delete' ) ),
		) );
		self::reg( '/fm/folders/(?P<id>\d+)/copy', $m( 'POST', array( __CLASS__, 'folder_copy' ) ) );
		self::reg( '/fm/links', $m( 'POST', array( __CLASS__, 'link_create' ) ) );
		self::reg( '/fm/folders/(?P<id>\d+)/access', array(
			$m( 'GET', array( __CLASS__, 'access_get' ) ),
			$m( 'PUT', array( __CLASS__, 'access_put' ) ),
		) );
		self::reg( '/fm/upload/init', $m( 'POST', array( __CLASS__, 'upload_init' ) ) );
		self::reg( '/fm/upload/chunk', $m( 'POST', array( __CLASS__, 'upload_chunk' ) ) );
		self::reg( '/fm/upload/finish', $m( 'POST', array( __CLASS__, 'upload_finish' ) ) );
		self::reg( '/fm/upload/(?P<id>[a-f0-9]+)', $m( 'DELETE', array( __CLASS__, 'upload_abort' ) ) );
		self::reg( '/fm/items/(?P<id>\d+)', array(
			$m( 'PATCH', array( __CLASS__, 'item_update' ) ),
			$m( 'DELETE', array( __CLASS__, 'item_delete' ) ),
		) );
		self::reg( '/fm/items/(?P<id>\d+)/copy', $m( 'POST', array( __CLASS__, 'item_copy' ) ) );
		self::reg( '/fm/items/(?P<id>\d+)/versions', $m( 'GET', array( __CLASS__, 'versions' ) ) );
		self::reg( '/fm/items/(?P<id>\d+)/versions/(?P<v>\d+)/restore', $m( 'POST', array( __CLASS__, 'version_restore' ) ) );
		self::reg( '/fm/items/(?P<id>\d+)/shares', array(
			$m( 'GET', array( __CLASS__, 'shares_list' ) ),
			$m( 'POST', array( __CLASS__, 'share_create' ) ),
		) );
		self::reg( '/fm/shares/(?P<id>\d+)', $m( 'DELETE', array( __CLASS__, 'share_revoke' ) ) );
		self::reg( '/fm/trash', $m( 'GET', array( __CLASS__, 'trash' ) ) );
		self::reg( '/fm/trash/restore', $m( 'POST', array( __CLASS__, 'trash_restore' ) ) );
		self::reg( '/fm/trash/purge', $m( 'POST', array( __CLASS__, 'trash_purge' ) ) );
		self::reg( '/fm/pickers', $m( 'GET', array( __CLASS__, 'pickers' ) ) );
		self::reg( '/fm/settings', array(
			$m( 'GET', array( __CLASS__, 'settings_get' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'settings_save' ), 'permission_callback' => array( Core_Routes::class, 'can_admin' ) ),
		) );
	}

	/* ───────────────────────── Row shapes ───────────────────────── */

	public static function file_shape( array $f ) {
		$kind = Store::kind( $f['ext'], $f['mime'] );
		$link = isset( $f['link_url'] ) ? (string) $f['link_url'] : '';
		$up   = (int) $f['uploaded_by'] ? get_userdata( (int) $f['uploaded_by'] ) : null;
		return array(
			'id'           => (int) $f['id'],
			'folder_id'    => (int) $f['folder_id'],
			'name'         => $f['name'],
			'ext'          => $f['ext'],
			'mime'         => $f['mime'],
			'kind'         => $kind,
			'size'         => (int) $f['size'],
			'version'      => (int) $f['version'],
			'description'  => (string) $f['description'],
			'tags'         => (string) $f['tags'],
			'modified'     => $f['updated_at'],
			'created'      => $f['created_at'],
			'uploader'     => $up ? $up->display_name : ( (int) $f['uploaded_by_client'] ? 'Uploaded by a user' : '' ),
			'is_link'      => '' !== $link,
			'link_url'     => $link,
			'embed_url'    => '' !== $link ? Store::embed_url( $link ) : '',
			'inline'       => '' === $link && Store::inline_ok( $f ),
			'view_url'     => '' === $link ? Streamer::url( $f['id'] ) : '',
			'download_url' => '' === $link ? Streamer::url( $f['id'], array( 'dl' => 1 ) ) : '',
			'thumb_url'    => ( '' === $link && 'image' === $kind && 'image/svg+xml' !== $f['mime'] && 'image/heic' !== $f['mime'] && 'image/tiff' !== $f['mime'] ) ? Streamer::url( $f['id'], array( 'thumb' => 1 ) ) : '',
		);
	}

	public static function folder_shape( array $f, array $v, array $counts = array() ) {
		return array(
			'id'          => (int) $f['id'],
			'parent_id'   => (int) $f['parent_id'],
			'name'        => $f['name'],
			'color'       => (string) $f['color'],
			'description' => (string) $f['description'],
			'modified'    => $f['updated_at'],
			'level'       => Access::level( (int) $f['id'], $v ),
			'inherit'     => (bool) (int) $f['inherit'],
			'items'       => isset( $counts[ (int) $f['id'] ] ) ? (int) $counts[ (int) $f['id'] ] : 0,
			'zip_url'     => Streamer::folder_url( (int) $f['id'] ),
		);
	}

	private static function counts( array $folder_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $folder_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT folder_id AS f, COUNT(*) n FROM ' . Install::table( 'files' ) . " WHERE folder_id IN ($in) AND deleted_at IS NULL GROUP BY folder_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['f'] ] = ( $out[ (int) $r['f'] ] ?? 0 ) + (int) $r['n'];
		}
		foreach ( (array) $wpdb->get_results( 'SELECT parent_id AS f, COUNT(*) n FROM ' . Install::table( 'folders' ) . " WHERE parent_id IN ($in) AND deleted_at IS NULL GROUP BY parent_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['f'] ] = ( $out[ (int) $r['f'] ] ?? 0 ) + (int) $r['n'];
		}
		return $out;
	}

	/* ───────────────────────── Config, prefs, tree ───────────────────────── */

	public static function client_config() {
		$v  = Access::viewer();
		$s  = Settings::get();
		$me = wp_get_current_user();
		$theme = (string) get_user_meta( $me->ID, 'wpflatfiles_theme', true );
		$view  = (string) get_user_meta( $me->ID, 'wpflatfiles_view', true );
		return array(
			'themes'         => Settings::THEMES,
			'theme'          => ( $s['theme_switch'] && isset( Settings::THEMES[ $theme ] ) ) ? $theme : $s['theme'],
			'default_theme'  => $s['theme'],
			'theme_switch'   => (bool) $s['theme_switch'],
			'view'           => in_array( $view, array( 'details', 'list', 'tiles', 'icons' ), true ) ? $view : $s['default_view'],
			'max_mb'         => (int) $s['max_mb'],
			'chunk_size'     => Uploads::chunk_size(),
			'users_can_upload' => (bool) $s['users_can_upload'],
			'staff'          => $v['staff'],
			'manager'        => $v['manager'],
			'global_level'   => (int) ( $v['global'] ?? 0 ),
			'simulated'      => ! empty( $v['simulated'] ),
			'user'           => $me->display_name,
			'allowed'        => Store::ALLOWED,
			'front_url'      => Settings::get( 'front_enabled' ) ? Front_Page::url() : '',
			'words'          => array_intersect_key( \WPFlatCore\Terminology::export()['words'], array_flip( array( 'client', 'group', 'team', 'department' ) ) ),
		);
	}

	public static function config() {
		return rest_ensure_response( self::client_config() );
	}

	public static function prefs( \WP_REST_Request $r ) {
		$uid = get_current_user_id();
		if ( $r->get_param( 'theme' ) && isset( Settings::THEMES[ $r->get_param( 'theme' ) ] ) && Settings::get( 'theme_switch' ) ) {
			update_user_meta( $uid, 'wpflatfiles_theme', $r->get_param( 'theme' ) );
		}
		if ( in_array( $r->get_param( 'view' ), array( 'details', 'list', 'tiles', 'icons' ), true ) ) {
			update_user_meta( $uid, 'wpflatfiles_view', $r->get_param( 'view' ) );
		}
		return rest_ensure_response( self::client_config() );
	}

	public static function tree() {
		$v    = Access::viewer();
		$vis  = Access::visible( $v );
		$cnt  = self::counts( array_keys( $vis ) );
		$list = array();
		foreach ( $vis as $f ) {
			$list[] = self::folder_shape( $f, $v, $cnt );
		}
		usort( $list, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		$roots = array();
		foreach ( Access::entry_points( $v ) as $f ) {
			$roots[] = (int) $f['id'];
		}
		return rest_ensure_response( array( 'folders' => $list, 'roots' => $roots ) );
	}

	/* ───────────────────────── Listing and search ───────────────────────── */

	private static function crumbs( $folder_id, array $v ) {
		$out = array();
		foreach ( Access::path_ids( $folder_id ) as $pid ) {
			if ( Access::level( $pid, $v ) > 0 ) {
				$f     = Access::folder( $pid );
				$out[] = array( 'id' => $pid, 'name' => $f['name'] );
			}
		}
		return $out;
	}

	public static function listing( \WP_REST_Request $r ) {
		global $wpdb;
		$v      = Access::viewer();
		$fid    = (int) $r->get_param( 'folder' );
		$q      = trim( (string) $r->get_param( 'q' ) );
		$sort   = in_array( $r->get_param( 'sort' ), array( 'name', 'date', 'size', 'type' ), true ) ? $r->get_param( 'sort' ) : 'name';
		$dir    = 'desc' === $r->get_param( 'dir' ) ? 'desc' : 'asc';
		$vis    = Access::visible( $v );
		$folders = array();
		$files   = array();
		$folder  = null;
		$can     = array( 'upload' => false, 'edit' => false, 'manage' => false, 'create_folder' => false );

		if ( '' !== $q ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			foreach ( $vis as $f ) {
				if ( false !== stripos( $f['name'], $q ) ) {
					$folders[] = $f;
				}
			}
			$ids = array_keys( $vis );
			if ( $ids ) {
				$in   = implode( ',', array_map( 'intval', $ids ) );
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . " WHERE folder_id IN ($in) AND deleted_at IS NULL AND (name LIKE %s OR tags LIKE %s OR description LIKE %s) ORDER BY name LIMIT 200", $like, $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$files = (array) $rows;
			}
		} elseif ( 0 === $fid ) {
			$folders = Access::entry_points( $v );
			$can['create_folder'] = self::may_top( $v );
		} else {
			if ( Access::level( $fid, $v ) < 1 ) {
				return self::denied();
			}
			$folder  = Access::folder( $fid );
			$lv      = Access::level( $fid, $v );
			$can     = array(
				'upload'        => $lv >= Access::LEVELS['upload'] && ( $v['staff'] || Settings::get( 'users_can_upload' ) || (int) ( $v['global'] ?? 0 ) >= Access::LEVELS['upload'] ),
				'edit'          => $lv >= Access::LEVELS['edit'],
				'manage'        => $lv >= Access::LEVELS['manage'],
				'create_folder' => $lv >= Access::LEVELS['edit'],
			);
			foreach ( $vis as $f ) {
				if ( (int) $f['parent_id'] === $fid ) {
					$folders[] = $f;
				}
			}
			$files = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE folder_id=%d AND deleted_at IS NULL ORDER BY name', $fid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}

		$cnt = self::counts( wp_list_pluck( $folders, 'id' ) );
		$fo  = array_map( function ( $f ) use ( $v, $cnt, $vis ) {
			$s = self::folder_shape( $f, $v, $cnt );
			$path = array();
			foreach ( Access::path_ids( (int) $f['parent_id'] ) as $pid ) {
				if ( isset( $vis[ $pid ] ) ) {
					$path[] = $vis[ $pid ]['name'];
				}
			}
			$s['path'] = implode( ' / ', $path );
			return $s;
		}, $folders );
		$fi = array_map( function ( $f ) use ( $vis ) {
			$s = self::file_shape( $f );
			$path = array();
			foreach ( Access::path_ids( (int) $f['folder_id'] ) as $pid ) {
				if ( isset( $vis[ $pid ] ) ) {
					$path[] = $vis[ $pid ]['name'];
				}
			}
			$s['path'] = implode( ' / ', $path );
			return $s;
		}, $files );

		$cmp = function ( $a, $b, $key ) {
			return is_numeric( $a[ $key ] ) ? $a[ $key ] <=> $b[ $key ] : strcasecmp( (string) $a[ $key ], (string) $b[ $key ] );
		};
		$key = array( 'name' => 'name', 'date' => 'modified', 'size' => 'size', 'type' => 'ext' )[ $sort ];
		usort( $fi, function ( $a, $b ) use ( $cmp, $key, $dir ) {
			$c = $cmp( $a, $b, $key );
			return 'desc' === $dir ? -$c : $c;
		} );
		usort( $fo, function ( $a, $b ) use ( $dir, $sort ) {
			$c = 'date' === $sort ? strcmp( $a['modified'], $b['modified'] ) : strcasecmp( $a['name'], $b['name'] );
			return 'desc' === $dir ? -$c : $c;
		} );

		return rest_ensure_response( array(
			'folder'  => $folder ? array_merge( self::folder_shape( $folder, $v ), array( 'path' => self::crumbs( $fid, $v ) ) ) : null,
			'can'     => $can,
			'folders' => $fo,
			'files'   => $fi,
			'query'   => $q,
		) );
	}

	/* ───────────────────────── Folders ───────────────────────── */

	/** May this person create or remove top-level folders? Managers, and anyone whose role lets them create and delete everywhere. */
	private static function may_top( array $v ) {
		return ! empty( $v['manager'] ) || ( isset( $v['global'] ) && (int) $v['global'] >= Access::LEVELS['edit'] );
	}

	public static function folder_create( \WP_REST_Request $r ) {
		$v      = Access::viewer();
		$parent = (int) $r->get_param( 'parent_id' );
		if ( ( $parent && ! Access::can( $parent, 'edit', $v ) ) || ( ! $parent && ! self::may_top( $v ) ) ) {
			return self::denied();
		}
		$res = Store::create_folder( $parent, (string) $r->get_param( 'name' ), array( 'color' => (string) $r->get_param( 'color' ), 'description' => (string) $r->get_param( 'description' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $parent ) { // a new top-level folder starts as team-only until its rules are set
			Access::save_rules( $res['id'], 0, array( array( 'type' => 'team', 'level' => 'manage' ) ) );
		}
		return rest_ensure_response( self::folder_shape( Store::folder_row( $res['id'] ), $v ) );
	}

	public static function folder_update( \WP_REST_Request $r ) {
		$v  = Access::viewer();
		$id = (int) $r['id'];
		if ( ! Access::can( $id, 'edit', $v ) ) {
			return self::denied();
		}
		$in = array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'name', 'description', 'color', 'parent_id' ) ) );
		if ( array_key_exists( 'parent_id', $in ) ) {
			$np = (int) $in['parent_id'];
			if ( ( $np && ! Access::can( $np, 'edit', $v ) ) || ( ! $np && ! self::may_top( $v ) ) ) {
				return self::denied();
			}
		}
		$res = Store::update_folder( $id, $in );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::folder_shape( $res, $v ) );
	}

	public static function folder_delete( \WP_REST_Request $r ) {
		$v  = Access::viewer();
		$id = (int) $r['id'];
		$f  = Access::folder( $id );
		if ( ! $f || ! Access::can( $id, 'edit', $v ) || ( 0 === $f['parent_id'] && ! self::may_top( $v ) ) ) {
			return self::denied();
		}
		return self::out( Store::trash_folder( $id ) ? array( 'ok' => true ) : new \WP_Error( 'wpflatfiles_db', 'Could not delete the folder.' ) );
	}

	public static function folder_copy( \WP_REST_Request $r ) {
		$v    = Access::viewer();
		$id   = (int) $r['id'];
		$body = (array) $r->get_json_params();
		$to   = isset( $body['parent_id'] ) ? (int) $body['parent_id'] : 0;
		if ( ! Access::can( $id, 'view', $v ) || ( $to && ! Access::can( $to, 'edit', $v ) ) || ( ! $to && ! self::may_top( $v ) ) ) {
			return self::denied();
		}
		$res = Store::copy_folder( $id, $to, $v );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::folder_shape( $res, $v ) );
	}

	public static function link_create( \WP_REST_Request $r ) {
		$body   = (array) $r->get_json_params();
		$folder = isset( $body['folder_id'] ) ? (int) $body['folder_id'] : 0;
		if ( ! $folder || ! self::may_upload( $folder ) ) {
			return self::denied();
		}
		$res = Store::add_link( $folder, isset( $body['name'] ) ? $body['name'] : '', isset( $body['url'] ) ? $body['url'] : '', $body );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::file_shape( $res ) );
	}

	public static function access_get( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Access::can( $id, 'manage' ) ) {
			return self::denied();
		}
		$f   = Access::folder( $id );
		$def = Access::definer( $id );
		$own = ! (int) $f['inherit'] || 0 === $f['parent_id'];
		$d   = Access::folder( $def );
		return rest_ensure_response( array(
			'inherit'   => ! $own,
			'top_level' => 0 === $f['parent_id'],
			'rules'     => array_map( array( Access::class, 'describe_rule' ), Access::rules_of( $def ) ),
			'from'      => $d ? array( 'id' => $def, 'name' => $d['name'] ) : null,
			'levels'    => array_keys( Access::LEVELS ),
		) );
	}

	public static function access_put( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Access::can( $id, 'manage' ) ) {
			return self::denied();
		}
		$p   = (array) $r->get_json_params();
		$res = Access::save_rules( $id, ! empty( $p['inherit'] ), isset( $p['rules'] ) && is_array( $p['rules'] ) ? $p['rules'] : array() );
		return is_wp_error( $res ) ? $res : self::access_get( $r );
	}

	/* ───────────────────────── Uploads ───────────────────────── */

	private static function may_upload( $folder_id, $new_version = false ) {
		$v = Access::viewer();
		if ( ! $v['staff'] && ! Settings::get( 'users_can_upload' ) && (int) ( $v['global'] ?? 0 ) < Access::LEVELS['upload'] ) { // the switch is for users; a File Contributor/Manager role always may
			return false;
		}
		return Access::can( (int) $folder_id, $new_version ? 'edit' : 'upload', $v );
	}

	public static function upload_init( \WP_REST_Request $r ) {
		$p       = (array) $r->get_json_params();
		$file_id = isset( $p['file_id'] ) ? (int) $p['file_id'] : 0;
		$folder  = isset( $p['folder_id'] ) ? (int) $p['folder_id'] : 0;
		if ( $file_id ) {
			$f = Store::file_row( $file_id );
			if ( ! $f ) {
				return new \WP_Error( 'wpflatfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
			}
			$folder = (int) $f['folder_id'];
		}
		if ( ! $folder || ! self::may_upload( $folder, (bool) $file_id ) ) {
			return self::denied();
		}
		$p['folder_id'] = $folder;
		return self::out( Uploads::init( $p ) );
	}

	public static function upload_chunk( \WP_REST_Request $r ) {
		$files = $r->get_file_params();
		return self::out( Uploads::chunk( (string) $r->get_param( 'upload_id' ), (int) $r->get_param( 'index' ), isset( $files['chunk'] ) ? $files['chunk'] : array() ) );
	}

	public static function upload_finish( \WP_REST_Request $r ) {
		$p  = (array) $r->get_json_params();
		$v  = Access::viewer();
		$st = Uploads::state( isset( $p['upload_id'] ) ? $p['upload_id'] : '' );
		if ( $st && ! self::may_upload( $st['folder_id'], (bool) $st['file_id'] ) ) {
			return self::denied();
		}
		$res = Uploads::finish( isset( $p['upload_id'] ) ? $p['upload_id'] : '', $v['client_ids'] ? $v['client_ids'][0] : 0 );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::file_shape( $res ) );
	}

	public static function upload_abort( \WP_REST_Request $r ) {
		if ( Uploads::state( $r['id'] ) ) {
			Uploads::abort( $r['id'] );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ───────────────────────── Files ───────────────────────── */

	/** Load a file the person may act on at a level, or an error. */
	private static function item( \WP_REST_Request $r, $need ) {
		$f = Store::file_row( (int) $r['id'] );
		if ( ! $f || null !== $f['deleted_at'] ) {
			return new \WP_Error( 'wpflatfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
		}
		if ( ! Access::can( (int) $f['folder_id'], $need ) ) {
			return self::denied();
		}
		return $f;
	}

	public static function item_update( \WP_REST_Request $r ) {
		$f = self::item( $r, 'edit' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$in = array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'name', 'folder_id', 'description', 'tags', 'link_url' ) ) );
		if ( isset( $in['folder_id'] ) && (int) $in['folder_id'] !== (int) $f['folder_id'] && ! Access::can( (int) $in['folder_id'], 'edit' ) ) {
			return self::denied();
		}
		$res = Store::rename_or_move( $f['id'], $in );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::file_shape( $res ) );
	}

	public static function item_delete( \WP_REST_Request $r ) {
		$f = self::item( $r, 'edit' );
		return is_wp_error( $f ) ? $f : self::out( Store::trash_file( $f['id'] ) ? array( 'ok' => true ) : new \WP_Error( 'wpflatfiles_db', 'Could not delete the file.' ) );
	}

	public static function item_copy( \WP_REST_Request $r ) {
		$f = self::item( $r, 'view' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$body = (array) $r->get_json_params();
		$to   = isset( $body['folder_id'] ) ? (int) $body['folder_id'] : 0;
		if ( ! $to || ! self::may_upload( $to ) ) {
			return self::denied();
		}
		$res = Store::copy_file( $f['id'], $to );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::file_shape( $res ) );
	}

	public static function versions( \WP_REST_Request $r ) {
		$f = self::item( $r, 'view' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		return rest_ensure_response( array_map( function ( $v ) use ( $f ) {
			$v['download_url'] = Streamer::url( $f['id'], array( 'v' => (int) $v['version'], 'dl' => 1 ) );
			$v['current']      = (int) $v['version'] === (int) $f['version'];
			return $v;
		}, Store::versions( $f['id'] ) ) );
	}

	public static function version_restore( \WP_REST_Request $r ) {
		$f = self::item( $r, 'edit' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$res = Store::restore_version( $f['id'], (int) $r['v'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::file_shape( $res ) );
	}

	/* ───────────────────────── Share links ───────────────────────── */

	public static function shares_list( \WP_REST_Request $r ) {
		global $wpdb;
		$f = self::item( $r, 'edit' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		return rest_ensure_response( array_map( function ( $s ) {
			return array( 'id' => (int) $s['id'], 'url' => Streamer::share_url( $s['token'] ), 'expires_at' => $s['expires_at'], 'protected' => '' !== $s['password_hash'], 'downloads' => (int) $s['downloads'], 'max_downloads' => (int) $s['max_downloads'], 'active' => true === Streamer::share_state( $s ), 'created_at' => $s['created_at'] );
		}, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'shares' ) . ' WHERE file_id=%d ORDER BY id DESC', (int) $f['id'] ), ARRAY_A ) ) ); // phpcs:ignore WordPress.DB
	}

	public static function share_create( \WP_REST_Request $r ) {
		$f = self::item( $r, 'edit' );
		return is_wp_error( $f ) ? $f : self::out( Streamer::create_share( $f['id'], (array) $r->get_json_params() ) );
	}

	public static function share_revoke( \WP_REST_Request $r ) {
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'shares' ) . ' WHERE id=%d', (int) $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$f = $s ? Store::file_row( $s['file_id'] ) : null;
		if ( ! $f || ! Access::can( (int) $f['folder_id'], 'edit' ) ) {
			return self::denied();
		}
		$wpdb->update( Install::table( 'shares' ), array( 'revoked' => 1 ), array( 'id' => (int) $s['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ───────────────────────── Recycle bin ───────────────────────── */

	public static function trash() {
		return rest_ensure_response( array( 'items' => Store::trash_list(), 'days' => (int) Settings::get( 'trash_days' ) ) );
	}

	private static function trash_item_allowed( $type, $id ) {
		$v = Access::viewer();
		if ( $v['manager'] ) {
			return true;
		}
		foreach ( Store::trash_list( $v ) as $i ) {
			if ( $i['type'] === $type && (int) $i['id'] === (int) $id ) {
				return true;
			}
		}
		return false;
	}

	public static function trash_restore( \WP_REST_Request $r ) {
		$p = (array) $r->get_json_params();
		if ( ! self::trash_item_allowed( (string) ( $p['type'] ?? '' ), (int) ( $p['id'] ?? 0 ) ) ) {
			return self::denied();
		}
		$res = Store::restore( (string) $p['type'], (int) $p['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	public static function trash_purge( \WP_REST_Request $r ) {
		$p = (array) $r->get_json_params();
		if ( ! empty( $p['all'] ) ) {
			if ( ! Access::viewer()['manager'] ) {
				return self::denied();
			}
			$n = 0;
			foreach ( Store::trash_list() as $i ) {
				true === Store::purge( $i['type'], $i['id'] ) && $n++;
			}
			return rest_ensure_response( array( 'purged' => $n ) );
		}
		if ( ! self::trash_item_allowed( (string) ( $p['type'] ?? '' ), (int) ( $p['id'] ?? 0 ) ) ) {
			return self::denied();
		}
		$res = Store::purge( (string) $p['type'], (int) $p['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	/* ───────────────────────── Pickers and settings ───────────────────────── */

	/** Groups and clients to choose from in the access dialog (people who can manage some folder). */
	public static function pickers( \WP_REST_Request $r ) {
		global $wpdb;
		$v = Access::viewer();
		if ( ! $v['staff'] ) {
			return self::denied();
		}
		$type = 'client' === $r->get_param( 'type' ) ? 'client' : 'group';
		$q    = '%' . $wpdb->esc_like( (string) $r->get_param( 'q' ) ) . '%';
		$tbl  = $wpdb->prefix . ( 'client' === $type ? 'wpflat_clients' : 'wpflat_groups' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM $tbl WHERE name LIKE %s ORDER BY name LIMIT 30", $q ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return rest_ensure_response( array_map( function ( $x ) use ( $type ) {
			$o = array( 'id' => (int) $x['id'], 'name' => $x['name'] );
			if ( 'group' === $type ) { // groups tied to roles say so (the access dialog shows it as a badge)
				$o['roles'] = array_map( function ( $r ) {
					return \WPFlatCore\Services\Groups::role_info( $r )['label'];
				}, \WPFlatCore\Services\Groups::roles( (int) $x['id'] ) );
			}
			return $o;
		}, (array) $rows ) );
	}

	public static function settings_get() {
		return rest_ensure_response( Settings::get() );
	}

	public static function settings_save( \WP_REST_Request $r ) {
		return rest_ensure_response( Settings::update( (array) $r->get_json_params() ) );
	}
}
