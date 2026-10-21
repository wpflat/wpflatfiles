<?php
namespace WPFlatFiles;

use WPFlatCore\Services\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Brings an existing folder tree on the server into WPFlat Files (for example the folders another file-manager plugin made).
 * Folders are recreated, files are moved, hard-linked or copied in, and every step is written to a manifest so a move can be
 * undone. Work is done in time-boxed steps, so a big tree is fine on hosts with short time limits: call step() until done.
 *
 * Only administrators may import, only from inside the site's own folders, and anything that is not an allowed file type
 * (and hidden files such as .tmb thumbnails or Thumbs.db) is skipped and counted.
 */
class Importer {

	const OPTION = 'wpflatfiles_import_jobs';

	/** Names never imported. */
	const SKIP_NAMES = array( 'thumbs.db', 'desktop.ini', '.ds_store', 'index.php', 'index.html', '.htaccess' );

	public static function jobs() {
		$j = get_option( self::OPTION, array() );
		return is_array( $j ) ? $j : array();
	}

	private static function save( array $job ) {
		$all            = self::jobs();
		$all[ $job['id'] ] = $job;
		update_option( self::OPTION, $all, false );
	}

	public static function get( $id ) {
		$all = self::jobs();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Only folders inside this WordPress install, and never WPFlat Files' own storage. */
	public static function source_ok( $dir ) {
		$real = realpath( (string) $dir );
		if ( ! $real || ! is_dir( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		$own  = wp_normalize_path( (string) realpath( Store::dir() ) );
		$root = wp_normalize_path( rtrim( ABSPATH, '/' ) );
		$cont = wp_normalize_path( rtrim( WP_CONTENT_DIR, '/' ) );
		if ( 0 === strpos( $real . '/', $own . '/' ) ) {
			return false;
		}
		return 0 === strpos( $real . '/', $cont . '/' ) || 0 === strpos( $real . '/', $root . '/' );
	}

	private static function skip_reason( $name, $is_dir ) {
		$l = strtolower( $name );
		if ( '.' === $name[0] ) {
			return 'hidden';
		}
		if ( in_array( $l, self::SKIP_NAMES, true ) ) {
			return 'system file';
		}
		if ( ! $is_dir && ! Store::allowed( $name ) ) {
			return 'type not allowed (.' . Store::ext( $name ) . ')';
		}
		return '';
	}

	/** Count what an import would do, without changing anything. */
	public static function scan( $dir ) {
		if ( ! self::source_ok( $dir ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'That folder cannot be imported (it must be inside this site and not WPFlatFiles\' own storage).', array( 'status' => 400 ) );
		}
		$out = array( 'folders' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => array(), 'skipped_count' => 0, 'deepest' => 0 );
		$walk = function ( $d, $depth ) use ( &$walk, &$out ) {
			$out['deepest'] = max( $out['deepest'], $depth );
			foreach ( (array) scandir( $d ) as $n ) {
				if ( '.' === $n || '..' === $n ) {
					continue;
				}
				$p   = $d . '/' . $n;
				$dir = is_dir( $p ) && ! is_link( $p );
				$why = self::skip_reason( $n, $dir );
				if ( '' !== $why ) {
					$out['skipped_count']++;
					$out['skipped'][ $why ] = ( $out['skipped'][ $why ] ?? 0 ) + 1;
					continue;
				}
				if ( $dir ) {
					$out['folders']++;
					$walk( $p, $depth + 1 );
				} elseif ( is_file( $p ) ) {
					$out['files']++;
					$out['bytes'] += (int) filesize( $p );
				}
			}
		};
		$walk( realpath( $dir ), 0 );
		return $out;
	}

	/**
	 * Start an import.
	 *
	 * @param string $dir       Folder to import (its CONTENTS become folders under $parent).
	 * @param int    $parent    WPFlat Files folder to import into (0 = top level).
	 * @param string $mode      move | link | copy
	 * @param array  $opts      top (name for a folder that wraps the whole import; '' = no wrapper)
	 */
	public static function start( $dir, $parent, $mode, array $opts = array() ) {
		if ( ! self::source_ok( $dir ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'That folder cannot be imported.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $mode, array( 'move', 'link', 'copy' ), true ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'Choose move, link or copy.', array( 'status' => 400 ) );
		}
		$id   = bin2hex( random_bytes( 5 ) );
		$root = (int) $parent;
		if ( ! empty( $opts['top'] ) ) {
			$f = Store::create_folder( $root, (string) $opts['top'] );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			if ( ! $root ) {
				Access::save_rules( $f['id'], 0, array( array( 'type' => 'team', 'level' => 'manage' ) ) );
			}
			$root = (int) $f['id'];
		}
		$real = wp_normalize_path( realpath( $dir ) );
		$job  = array(
			'id'       => $id,
			'dir'      => $real,
			'mode'     => $mode,
			'root'     => $root,
			'queue'    => array( array( 'src' => $real, 'folder' => $root, 'offset' => 0 ) ),
			'folders'  => 0,
			'files'    => 0,
			'bytes'    => 0,
			'skipped'  => array(),
			'errors'   => array(),
			'done'     => false,
			'started'  => current_time( 'mysql' ),
			'manifest' => Paths_Helper::manifest( $id ),
			'user'     => get_current_user_id(),
		);
		file_put_contents( $job['manifest'], '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::save( $job );
		return $job;
	}

	private static function log( array $job, array $row ) {
		file_put_contents( $job['manifest'], wp_json_encode( $row ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Do up to $seconds of work. Returns the job (check ['done']). */
	public static function step( $id, $seconds = 20 ) {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job ) {
			return new \WP_Error( 'wpflatfiles_import', 'That import does not exist.', array( 'status' => 404 ) );
		}
		if ( $job['done'] ) {
			return $job;
		}
		@set_time_limit( $seconds + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_set_current_user( (int) $job['user'] );
		$until = microtime( true ) + $seconds;
		$files = Install::table( 'files' );
		$vers  = Install::table( 'versions' );
		while ( $job['queue'] && microtime( true ) < $until ) {
			$cur   = $job['queue'][0];
			$names = array_values( array_diff( (array) scandir( $cur['src'] ), array( '.', '..' ) ) );
			natcasesort( $names );
			$names = array_values( $names );
			$i     = (int) $cur['offset'];
			for ( ; $i < count( $names ); $i++ ) {
				if ( microtime( true ) >= $until ) {
					break;
				}
				$n   = $names[ $i ];
				$p   = $cur['src'] . '/' . $n;
				$dir = is_dir( $p ) && ! is_link( $p );
				$why = self::skip_reason( $n, $dir );
				if ( '' !== $why ) {
					$job['skipped'][ $why ] = ( $job['skipped'][ $why ] ?? 0 ) + 1;
					continue;
				}
				if ( $dir ) {
					$f = Store::create_folder( (int) $cur['folder'], $n, array( 'inherit' => 1 ) );
					if ( is_wp_error( $f ) ) {
						$job['errors'][] = $p . ': ' . $f->get_error_message();
						continue;
					}
					$job['folders']++;
					self::log( $job, array( 't' => 'folder', 'id' => (int) $f['id'], 'src' => $p ) );
					$job['queue'][] = array( 'src' => $p, 'folder' => (int) $f['id'], 'offset' => 0 );
					continue;
				}
				if ( ! is_file( $p ) ) {
					continue;
				}
				$name   = Store::clean_name( $n );
				$ext    = Store::ext( $n );
				$stored = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
				$dest   = Store::dir() . '/' . $stored;
				$size   = (int) filesize( $p );
				$ok     = false;
				if ( 'move' === $job['mode'] ) {
					$ok = @rename( $p, $dest ) || ( @copy( $p, $dest ) && @unlink( $p ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				} elseif ( 'link' === $job['mode'] ) {
					$ok = ( function_exists( 'link' ) && @\link( $p, $dest ) ) || @copy( $p, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				} else {
					$ok = @copy( $p, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				}
				if ( ! $ok || ! is_file( $dest ) ) {
					$job['errors'][] = $p . ': could not be transferred';
					continue;
				}
				@chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				$hash = hash_file( 'sha256', $dest );
				$when = gmdate( 'Y-m-d H:i:s', (int) filemtime( $dest ) );
				$mime = Store::mime_for( $name );
				$wpdb->insert( $files, array( 'folder_id' => (int) $cur['folder'], 'name' => Store::unique_name( (int) $cur['folder'], $name ), 'ext' => $ext, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'version' => 1, 'uploaded_by' => (int) $job['user'], 'created_at' => $when, 'updated_at' => $when ) ); // phpcs:ignore WordPress.DB
				$fid = (int) $wpdb->insert_id;
				if ( ! $fid ) {
					@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
					$job['errors'][] = $p . ': database error';
					continue;
				}
				$wpdb->insert( $vers, array( 'file_id' => $fid, 'version' => 1, 'name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'uploaded_by' => (int) $job['user'], 'created_at' => $when ) ); // phpcs:ignore WordPress.DB
				$job['files']++;
				$job['bytes'] += $size;
				self::log( $job, array( 't' => 'file', 'id' => $fid, 'src' => $p, 'stored' => $stored ) );
			}
			if ( $i >= count( $names ) ) {
				array_shift( $job['queue'] );
			} else {
				$job['queue'][0]['offset'] = $i;
			}
			if ( count( $job['errors'] ) > 200 ) {
				$job['errors'] = array_slice( $job['errors'], 0, 200 );
			}
		}
		if ( ! $job['queue'] ) {
			$job['done']  = true;
			$job['ended'] = current_time( 'mysql' );
			Activity::log( 'folders', (int) $job['root'], 0, 'imported', sprintf( 'Imported %d files in %d folders from a server folder (%s)', $job['files'], $job['folders'], $job['mode'] ) );
		}
		Access::reset();
		self::save( $job );
		return $job;
	}

	/**
	 * Bring in one file that is still in the source tree (for example one added while an import was running), into a WPFlat Files
	 * folder you name. Same rules as the import: allowed types only; $mode is move, link or copy.
	 *
	 * @return array|\WP_Error the file row
	 */
	public static function adopt_file( $path, $folder_id, $mode = 'move' ) {
		global $wpdb;
		$path = wp_normalize_path( (string) realpath( $path ) );
		$dir  = dirname( $path );
		if ( '' === $path || ! is_file( $path ) || ! self::source_ok( $dir ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'That file cannot be imported.', array( 'status' => 400 ) );
		}
		$n = basename( $path );
		if ( '' !== self::skip_reason( $n, false ) || ! Access::folder( $folder_id ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'That file or folder is not allowed.', array( 'status' => 400 ) );
		}
		$name   = Store::clean_name( $n );
		$ext    = Store::ext( $n );
		$stored = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest   = Store::dir() . '/' . $stored;
		if ( 'move' === $mode ) {
			$ok = @rename( $path, $dest ) || ( @copy( $path, $dest ) && @unlink( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		} elseif ( 'link' === $mode ) {
			$ok = ( function_exists( 'link' ) && @\link( $path, $dest ) ) || @copy( $path, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		} else {
			$ok = @copy( $path, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok || ! is_file( $dest ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'The file could not be transferred.', array( 'status' => 500 ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		$hash = hash_file( 'sha256', $dest );
		$size = (int) filesize( $dest );
		$when = gmdate( 'Y-m-d H:i:s', (int) filemtime( $dest ) );
		$mime = Store::mime_for( $name );
		$wpdb->insert( Install::table( 'files' ), array( 'folder_id' => (int) $folder_id, 'name' => Store::unique_name( (int) $folder_id, $name ), 'ext' => $ext, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'version' => 1, 'uploaded_by' => get_current_user_id(), 'created_at' => $when, 'updated_at' => $when ) ); // phpcs:ignore WordPress.DB
		$fid = (int) $wpdb->insert_id;
		if ( ! $fid ) {
			@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'wpflatfiles_db', 'The file could not be recorded.', array( 'status' => 500 ) );
		}
		$wpdb->insert( Install::table( 'versions' ), array( 'file_id' => $fid, 'version' => 1, 'name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'uploaded_by' => get_current_user_id(), 'created_at' => $when ) ); // phpcs:ignore WordPress.DB
		return Store::file_row( $fid );
	}

	/** Reverse an import: a move puts the files back where they came from; every mode removes what it added to WPFlat Files. */
	public static function undo( $id ) {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job || ! is_file( $job['manifest'] ) ) {
			return new \WP_Error( 'wpflatfiles_import', 'That import cannot be undone (no record of it).', array( 'status' => 404 ) );
		}
		$back = 0; $gone = 0; $lost = array();
		$rows = array_reverse( array_filter( array_map( function ( $l ) {
			return json_decode( $l, true );
		}, (array) file( $job['manifest'], FILE_IGNORE_NEW_LINES ) ) ) );
		foreach ( $rows as $r ) {
			if ( 'file' === $r['t'] ) {
				$from = Store::dir() . '/' . basename( $r['stored'] );
				if ( 'move' === $job['mode'] && is_file( $from ) ) {
					wp_mkdir_p( dirname( $r['src'] ) );
					if ( ! file_exists( $r['src'] ) && ( @rename( $from, $r['src'] ) || ( @copy( $from, $r['src'] ) && @unlink( $from ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
						$back++;
					} else {
						$lost[] = $r['src'];
					}
				} elseif ( is_file( $from ) ) {
					@unlink( $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				}
				$wpdb->delete( Install::table( 'versions' ), array( 'file_id' => (int) $r['id'] ) );
				$wpdb->delete( Install::table( 'files' ), array( 'id' => (int) $r['id'] ) );
				$gone++;
			} else {
				$wpdb->delete( Install::table( 'access' ), array( 'folder_id' => (int) $r['id'] ) );
				$wpdb->delete( Install::table( 'folders' ), array( 'id' => (int) $r['id'] ) );
			}
		}
		if ( ! empty( $job['root'] ) ) {
			// the wrapper folder this import made, when it is empty again
			$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::table( 'folders' ) . ' WHERE parent_id=%d', (int) $job['root'] ) ) + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::table( 'files' ) . ' WHERE folder_id=%d', (int) $job['root'] ) ); // phpcs:ignore WordPress.DB
			if ( ! $has ) {
				$wpdb->delete( Install::table( 'access' ), array( 'folder_id' => (int) $job['root'] ) );
				$wpdb->delete( Install::table( 'folders' ), array( 'id' => (int) $job['root'] ) );
			}
		}
		Access::reset();
		$all = self::jobs();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		return array( 'removed' => $gone, 'restored_to_origin' => $back, 'not_restored' => array_slice( $lost, 0, 20 ) );
	}
}

/** Where a job's manifest lives (a private file next to the stored files). */
class Paths_Helper {
	public static function manifest( $id ) {
		return \WPFlatCore\Paths::dir( 'files-import' ) . '/import-' . preg_replace( '/[^a-f0-9]/', '', $id ) . '.ndjson';
	}
}
