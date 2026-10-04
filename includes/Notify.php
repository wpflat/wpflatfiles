<?php
namespace BizyFiles;

use BizyCore\Portal;
use BizyCore\Services\Groups;
use BizyCore\Services\Notifications as N;

defined( 'ABSPATH' ) || exit;

/** A person hears about a new file in a folder shared with their group or with them; the team hears about uploads from users. */
class Notify {

	public static function init() {
		add_action( 'bizyfiles_file_uploaded', array( __CLASS__, 'uploaded' ), 10, 2 );
	}

	public static function types() {
		N::register( 'client_file_new', array( 'label' => 'File', 'audience' => 'client', 'group' => 'Files', 'description' => 'A new file is shared with you', 'email' => 'off', 'external' => true ) );
		N::register( 'team_file_uploaded', array( 'label' => 'File', 'audience' => 'team', 'group' => 'Files', 'description' => 'A user uploaded a file', 'email' => 'digest' ) );
	}

	/** Client ids named by the folder's rules (a specific client, or everyone in a group). */
	private static function audience( $folder_id ) {
		$clients = array();
		foreach ( Access::rules_of( Access::definer( (int) $folder_id ) ) as $r ) {
			if ( 'client' === $r['type'] ) {
				$clients[] = $r['id'];
			} elseif ( 'group' === $r['type'] ) {
				$clients = array_merge( $clients, Groups::members( $r['id'] ) );
			}
		}
		return array_values( array_unique( array_map( 'intval', $clients ) ) );
	}

	public static function uploaded( $file, $is_version ) {
		if ( $is_version ) {
			return;
		}
		$v = Access::viewer();
		if ( ! $v['staff'] ) {
			N::emit( 'team_file_uploaded', N::users_with( 'bizyfiles_manage' ), 'File uploaded: ' . $file['name'], 'A user added a file.', '/files?folder=' . (int) $file['folder_id'], 0, 'file', (int) $file['id'], 'fup:' . $file['id'] );
			return;
		}
		// People who hold a role linked to a group the folder is shared with
		foreach ( Access::rules_of( Access::definer( (int) $file['folder_id'] ) ) as $r ) {
			if ( 'group' === $r['type'] ) {
				$users = array_slice( Groups::member_users( $r['id'] ), 0, 200 );
				if ( $users ) {
					N::emit( 'client_file_new', $users, 'New file: ' . $file['name'], 'A file was added to a folder shared with you.', home_url( '/' . Settings::get( 'front_slug' ) . '/' ), 0, 'file', (int) $file['id'], 'fnewr:' . $file['id'] . ':' . $r['id'] );
				}
			}
		}
		$n = 0;
		foreach ( self::audience( (int) $file['folder_id'] ) as $cid ) {
			if ( ++$n > 200 ) {
				break;
			}
			N::emit( 'client_file_new', N::client_users( $cid ), 'New file: ' . $file['name'], 'A file was added to a folder shared with you.', Portal::url( 'files' ), $cid, 'file', (int) $file['id'], 'fnew:' . $file['id'] . ':' . $cid );
		}
	}
}
