<?php
namespace WPFlatFiles;

use WPFlatCore\Services\Groups;
use WPFlatCore\Services\Portal_Users;

defined( 'ABSPATH' ) || exit;

/**
 * Who may see and do what, per folder.
 *
 * A folder either defines its own rules (inherit = 0) or uses its parent's (inherit = 1). Top-level folders always define
 * their own. A rule gives a level — view, upload, edit, manage — to one of: the whole team ("team"), every portal user
 * ("users"), a group, or one client. A folder nobody's rules match is invisible to that person, and so are its contents.
 *
 * Team members with the WPFlat Files manage capability (and WordPress administrators) can do everything everywhere.
 */
class Access {

	const LEVELS = array( 'view' => 1, 'upload' => 2, 'edit' => 3, 'manage' => 4 );

	/** @var array|null id => folder row (non-deleted) */
	private static $folders = null;

	/** @var array|null definer id => list of rules */
	private static $rules = null;

	public static function reset() {
		self::$folders = null;
		self::$rules   = null;
	}

	/* ───────────────────────── Who is asking ───────────────────────── */

	public static function is_manager() {
		return current_user_can( 'manage_options' ) || ( current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatfiles_manage' ) );
	}

	/** Staff who may open the file manager in the app (they see what the "team" rules and their own manage right allow). */
	public static function is_staff() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_wpflatcore' );
	}

	/** Client id a staff member is previewing the library as (the portal's "view as" mode), or 0. */
	public static function simulated_client() {
		$id = 0;
		if ( ! empty( $_SERVER['HTTP_X_WPFLAT_AS'] ) ) {
			$id = absint( wp_unslash( $_SERVER['HTTP_X_WPFLAT_AS'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_GET['bd_as'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = absint( $_GET['bd_as'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return ( $id && current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatcore_clients' ) ) ? $id : 0;
	}

	/**
	 * What a person's capabilities allow in every folder: 0 none, 1 view everything (wpflatfiles_view_all), 2 also add files
	 * (wpflatfiles_upload), 3 also create, rename, move and delete (wpflatfiles_edit), 4 everything (wpflatfiles_manage or an administrator).
	 * Each higher capability includes the lower ones, so a role only needs its own.
	 */
	public static function global_level( $user_id ) {
		$u = $user_id ? get_userdata( (int) $user_id ) : null;
		if ( ! $u ) {
			return 0;
		}
		if ( user_can( $u, 'manage_options' ) || user_can( $u, 'wpflatfiles_manage' ) ) {
			return 4;
		}
		foreach ( array( 3 => 'wpflatfiles_edit', 2 => 'wpflatfiles_upload', 1 => 'wpflatfiles_view_all' ) as $lv => $cap ) {
			if ( user_can( $u, $cap ) ) {
				return $lv;
			}
		}
		return 0;
	}

	/** A user id staff are previewing the library as (header X-WPFlat-As-User), or 0. */
	public static function simulated_user() {
		$id = 0;
		if ( ! empty( $_SERVER['HTTP_X_WPFLAT_AS_USER'] ) ) {
			$id = absint( wp_unslash( $_SERVER['HTTP_X_WPFLAT_AS_USER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_GET['bd_as_user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = absint( $_GET['bd_as_user'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return ( $id && get_userdata( $id ) && current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatcore_clients' ) ) ? $id : 0;
	}

	/** @return array{user:int,staff:bool,manager:bool,client_ids:int[],group_ids:int[],simulated:bool} */
	public static function viewer( $user_id = 0 ) {
		$as = $user_id ? 0 : self::simulated_client();
		if ( $as ) {
			return array( 'user' => get_current_user_id(), 'staff' => false, 'manager' => false, 'global' => 0, 'client_ids' => array( $as ), 'group_ids' => Groups::for_clients( array( $as ) ), 'simulated' => true );
		}
		$as_user = $user_id ? 0 : self::simulated_user();
		if ( $as_user ) { // staff previewing as one person: what that person's roles and client links would give
			$clients = array_map( 'intval', Portal_Users::client_ids( $as_user ) );
			return array( 'user' => $as_user, 'staff' => false, 'manager' => false, 'global' => self::global_level( $as_user ) < 4 ? self::global_level( $as_user ) : 3, 'client_ids' => $clients, 'group_ids' => array_values( array_unique( array_merge( Groups::for_clients( $clients ), Groups::for_user( $as_user ) ) ) ), 'simulated' => true );
		}
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$u       = $user_id ? get_userdata( $user_id ) : null;
		$staff   = $u && ( user_can( $u, 'manage_options' ) || user_can( $u, 'manage_wpflatcore' ) );
		$mgr     = $u && ( user_can( $u, 'manage_options' ) || ( user_can( $u, 'manage_wpflatcore' ) && user_can( $u, 'wpflatfiles_manage' ) ) );
		$clients = ( $u && ! $staff ) ? array_map( 'intval', Portal_Users::client_ids( $user_id ) ) : array();
		return array(
			'user'       => $user_id,
			'staff'      => (bool) $staff,
			'manager'    => (bool) $mgr,
			'global'     => self::global_level( $user_id ),
			'client_ids' => $clients,
			'group_ids'  => array_values( array_unique( array_merge( Groups::for_clients( $clients ), $user_id ? Groups::for_user( $user_id ) : array() ) ) ), // hand-picked clients' groups + groups tied to the roles the person holds now
			'simulated'  => false,
		);
	}

	/** May this request use the file manager API at all? */
	public static function can_use_api() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$v = self::viewer();
		if ( ! empty( $v['global'] ) ) {
			return true;
		}
		if ( $v['staff'] ) {
			return $v['manager'] || current_user_can( 'wpflatfiles_use' ) || ! empty( $v['group_ids'] );
		}
		return ! empty( $v['client_ids'] ) || ! empty( $v['group_ids'] );
	}

	/* ───────────────────────── Folders and rules ───────────────────────── */

	public static function folders() {
		global $wpdb;
		if ( null === self::$folders ) {
			self::$folders = array();
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Install::table( 'folders' ) . ' WHERE deleted_at IS NULL', ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
				$f['id']        = (int) $f['id'];
				$f['parent_id'] = (int) $f['parent_id'];
				self::$folders[ $f['id'] ] = $f;
			}
		}
		return self::$folders;
	}

	public static function folder( $id ) {
		$all = self::folders();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/** Ids from the top-level folder down to this one. */
	public static function path_ids( $id ) {
		$all  = self::folders();
		$out  = array();
		$guard = 0;
		while ( $id && isset( $all[ $id ] ) && $guard++ < 64 ) {
			array_unshift( $out, (int) $id );
			$id = $all[ $id ]['parent_id'];
		}
		return $out;
	}

	/** The folder whose rules apply to this one. */
	public static function definer( $id ) {
		$all   = self::folders();
		$guard = 0;
		while ( $id && isset( $all[ $id ] ) && $guard++ < 64 ) {
			$f = $all[ $id ];
			if ( 0 === $f['parent_id'] || ! (int) $f['inherit'] ) {
				return (int) $id;
			}
			$id = $f['parent_id'];
		}
		return 0;
	}

	public static function rules_of( $definer_id ) {
		global $wpdb;
		if ( null === self::$rules ) {
			self::$rules = array();
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Install::table( 'access' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				self::$rules[ (int) $r['folder_id'] ][] = array( 'type' => $r['subject_type'], 'id' => (int) $r['subject_id'], 'level' => $r['level'] );
			}
		}
		return isset( self::$rules[ (int) $definer_id ] ) ? self::$rules[ (int) $definer_id ] : array();
	}

	/** Does this rule apply to this person? */
	private static function matches( array $r, array $v ) {
		switch ( $r['type'] ) {
			case 'team':
				return $v['staff'];
			case 'users':
				return ! $v['staff'] && ( ! empty( $v['client_ids'] ) || ! empty( $v['group_ids'] ) ); // anyone signed in who uses Files without being on the team
			case 'group':
				return in_array( $r['id'], $v['group_ids'], true );
			case 'client':
				return in_array( $r['id'], $v['client_ids'], true );
		}
		return false;
	}

	/** 0 = no access, 1 view, 2 upload, 3 edit, 4 manage. */
	public static function level( $folder_id, ?array $v = null ) {
		$v = $v ? $v : self::viewer();
		if ( $v['manager'] ) {
			return 4;
		}
		if ( ! self::folder( $folder_id ) ) {
			return 0;
		}
		$best = isset( $v['global'] ) ? min( 3, (int) $v['global'] ) : 0; // a role's rights apply in every folder, but never reach "manage" (who has access) by themselves
		foreach ( self::rules_of( self::definer( (int) $folder_id ) ) as $r ) {
			if ( self::matches( $r, $v ) ) {
				$best = max( $best, self::LEVELS[ $r['level'] ] ?? 1 );
			}
		}
		return $best;
	}

	/** Level a person has for something (view, upload, edit, manage). */
	public static function can( $folder_id, $need, ?array $v = null ) {
		return self::level( $folder_id, $v ) >= ( self::LEVELS[ $need ] ?? 99 );
	}

	/** Folders this person can see, flat, with their level. */
	public static function visible( ?array $v = null ) {
		$v   = $v ? $v : self::viewer();
		$out = array();
		foreach ( self::folders() as $id => $f ) {
			$lv = self::level( $id, $v );
			if ( $lv > 0 ) {
				$f['level'] = $lv;
				$out[ $id ] = $f;
			}
		}
		return $out;
	}

	/** The top of what this person can see: folders they can open whose parent they cannot. */
	public static function entry_points( ?array $v = null ) {
		$vis = self::visible( $v );
		return array_values( array_filter( $vis, function ( $f ) use ( $vis ) {
			return 0 === $f['parent_id'] || ! isset( $vis[ $f['parent_id'] ] );
		} ) );
	}

	/* ───────────────────────── Saving rules ───────────────────────── */

	public static function sanitize_rules( array $rules ) {
		$out  = array();
		$seen = array();
		foreach ( $rules as $r ) {
			$type  = isset( $r['type'] ) ? (string) $r['type'] : '';
			$level = isset( $r['level'] ) && isset( self::LEVELS[ $r['level'] ] ) ? $r['level'] : 'view';
			$id    = isset( $r['id'] ) ? (int) $r['id'] : 0;
			if ( ! in_array( $type, array( 'team', 'users', 'group', 'client' ), true ) ) {
				continue;
			}
			if ( in_array( $type, array( 'team', 'users' ), true ) ) {
				$id = 0;
			} elseif ( ! $id ) {
				continue;
			}
			$k = $type . ':' . $id;
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = array( 'type' => $type, 'id' => $id, 'level' => $level );
		}
		return $out;
	}

	/** Replace a folder's own rules (and its inherit switch). */
	public static function save_rules( $folder_id, $inherit, array $rules ) {
		global $wpdb;
		$folder_id = (int) $folder_id;
		$f         = self::folder( $folder_id );
		if ( ! $f ) {
			return new \WP_Error( 'wpflatfiles_not_found', 'That folder does not exist.', array( 'status' => 404 ) );
		}
		$inherit = ( 0 === $f['parent_id'] ) ? 0 : ( $inherit ? 1 : 0 );
		$wpdb->update( Install::table( 'folders' ), array( 'inherit' => $inherit, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $folder_id ) );
		$wpdb->delete( Install::table( 'access' ), array( 'folder_id' => $folder_id ) );
		if ( ! $inherit ) {
			foreach ( self::sanitize_rules( $rules ) as $r ) {
				$wpdb->insert( Install::table( 'access' ), array( 'folder_id' => $folder_id, 'subject_type' => $r['type'], 'subject_id' => $r['id'], 'level' => $r['level'] ) );
			}
		}
		self::reset();
		do_action( 'wpflatfiles_access_changed', $folder_id );
		return true;
	}

	/** A rule's subject, with a name for display. */
	public static function describe_rule( array $r ) {
		global $wpdb;
		$name = '';
		if ( 'team' === $r['type'] ) {
			$name = 'Team';
		} elseif ( 'users' === $r['type'] ) {
			$name = 'All users';
		} elseif ( 'group' === $r['type'] ) {
			$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . $wpdb->prefix . 'wpflat_groups WHERE id=%d', $r['id'] ) ); // phpcs:ignore WordPress.DB
			return $r + array( 'name' => $name, 'roles' => array_map( function ( $slug ) {
				return Groups::role_info( $slug )['label'];
			}, Groups::roles( (int) $r['id'] ) ) );
		} elseif ( 'client' === $r['type'] ) {
			$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . $wpdb->prefix . 'wpflat_clients WHERE id=%d', $r['id'] ) ); // phpcs:ignore WordPress.DB
		}
		return $r + array( 'name' => $name );
	}

	/** Remove every rule for a subject (the group or client was deleted). */
	public static function forget_subject( $type, $id ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'access' ), array( 'subject_type' => $type, 'subject_id' => (int) $id ) );
		self::reset();
	}
}
