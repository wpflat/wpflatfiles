<?php
namespace BizyFiles;

use BizyCore\Paths;
use BizyCore\Services\Activity;

defined( 'ABSPATH' ) || exit;

/** Folders and files: create, rename, move, copy, version, trash and purge. Access checks are the caller's job (Routes). */
class Store {

	/** Extensions that may be uploaded. Nothing that can run on the server, and no pages that could run script in the browser. */
	const ALLOWED = array(
		'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'md', 'csv', 'json', 'xml', 'epub',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'heic', 'tif', 'tiff', 'psd', 'ai', 'eps',
		'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'opus',
		'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv', 'mpg', 'mpeg',
		'zip', '7z', 'rar', 'gz', 'tar', 'key', 'pages', 'numbers',
	);

	/** MIME types the browser may show inline. Everything else is a download. */
	const INLINE = array( 'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/flac', 'audio/aac', 'audio/opus', 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'text/plain', 'text/csv', 'text/markdown' );

	public static function dir() {
		return Paths::dir( 'files' );
	}

	public static function now() {
		return current_time( 'mysql' );
	}

	/* ───────────────────────── Names and types ───────────────────────── */

	public static function clean_name( $name ) {
		$name = trim( wp_strip_all_tags( wp_unslash( (string) $name ) ) );
		$name = preg_replace( '#[\\\\/:*?"<>|\x00-\x1f]#u', '', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ), ' .' );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 180 ) : substr( $name, 0, 180 );
	}

	public static function ext( $name ) {
		return strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	}

	public static function mime_for( $name ) {
		static $extra = array(
			'md' => 'text/markdown', 'csv' => 'text/csv', 'webm' => 'video/webm', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
			'opus' => 'audio/opus', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'oga' => 'audio/ogg', 'ogv' => 'video/ogg', 'heic' => 'image/heic',
			'epub' => 'application/epub+zip', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar', 'webp' => 'image/webp',
		);
		$ext = self::ext( $name );
		if ( isset( $extra[ $ext ] ) ) {
			return $extra[ $ext ];
		}
		$t = wp_check_filetype( $name );
		return $t['type'] ? $t['type'] : 'application/octet-stream';
	}

	/** A coarse kind for icons and previews. */
	public static function kind( $ext, $mime = '' ) {
		if ( 'link' === strtolower( (string) $ext ) ) {
			return 'link';
		}
		$ext = strtolower( (string) $ext );
		$map = array(
			'image'   => array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'heic', 'tif', 'tiff', 'psd', 'ai', 'eps' ),
			'video'   => array( 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv', 'mpg', 'mpeg' ),
			'audio'   => array( 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'opus' ),
			'pdf'     => array( 'pdf' ),
			'doc'     => array( 'doc', 'docx', 'odt', 'rtf', 'pages', 'epub' ),
			'sheet'   => array( 'xls', 'xlsx', 'ods', 'csv', 'numbers' ),
			'slides'  => array( 'ppt', 'pptx', 'odp', 'key' ),
			'text'    => array( 'txt', 'md', 'json', 'xml' ),
			'archive' => array( 'zip', '7z', 'rar', 'gz', 'tar' ),
		);
		foreach ( $map as $kind => $list ) {
			if ( in_array( $ext, $list, true ) ) {
				return $kind;
			}
		}
		return 'other';
	}

	public static function allowed( $name ) {
		return in_array( self::ext( $name ), self::ALLOWED, true );
	}

	public static function inline_ok( array $file ) {
		return in_array( $file['mime'], self::INLINE, true );
	}

	/** A name that does not clash with a sibling: "Report.pdf" → "Report (2).pdf". */
	public static function unique_name( $folder_id, $name, $ignore_file = 0 ) {
		global $wpdb;
		$ext  = self::ext( $name );
		$base = '' !== $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name;
		$try  = $name;
		$n    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Install::table( 'files' ) . ' WHERE folder_id=%d AND name=%s AND deleted_at IS NULL AND id<>%d LIMIT 1', (int) $folder_id, $try, (int) $ignore_file ) ) ) { // phpcs:ignore WordPress.DB
			$try = $base . ' (' . $n++ . ')' . ( '' !== $ext ? '.' . $ext : '' );
		}
		return $try;
	}

	public static function unique_folder_name( $parent_id, $name, $ignore = 0 ) {
		global $wpdb;
		$try = $name;
		$n   = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Install::table( 'folders' ) . ' WHERE parent_id=%d AND name=%s AND deleted_at IS NULL AND id<>%d LIMIT 1', (int) $parent_id, $try, (int) $ignore ) ) ) { // phpcs:ignore WordPress.DB
			$try = $name . ' (' . $n++ . ')';
		}
		return $try;
	}

	/* ───────────────────────── Folders ───────────────────────── */

	public static function create_folder( $parent_id, $name, array $extra = array() ) {
		global $wpdb;
		$parent_id = (int) $parent_id;
		$name      = self::clean_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'bizyfiles_invalid', 'Give the folder a name.', array( 'status' => 400 ) );
		}
		if ( $parent_id && ! Access::folder( $parent_id ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'The parent folder does not exist.', array( 'status' => 404 ) );
		}
		$now = self::now();
		$ok  = $wpdb->insert( Install::table( 'folders' ), array(
			'parent_id'     => $parent_id,
			'name'          => self::unique_folder_name( $parent_id, $name ),
			'description'   => isset( $extra['description'] ) ? sanitize_textarea_field( $extra['description'] ) : '',
			'color'         => isset( $extra['color'] ) ? sanitize_text_field( $extra['color'] ) : '',
			'inherit'       => $parent_id ? ( isset( $extra['inherit'] ) ? (int) (bool) $extra['inherit'] : 1 ) : 0,
			'department_id' => isset( $extra['department_id'] ) ? (int) $extra['department_id'] : 0,
			'created_by'    => get_current_user_id(),
			'created_at'    => $now,
			'updated_at'    => $now,
		) );
		if ( ! $ok ) {
			return new \WP_Error( 'bizyfiles_db', 'The folder could not be created.', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		Access::reset();
		Activity::log( 'folders', $id, 0, 'created', 'Folder created: ' . $name );
		return self::folder_row( $id );
	}

	public static function folder_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'folders' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function update_folder( $id, array $in ) {
		global $wpdb;
		$f = Access::folder( $id );
		if ( ! $f ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That folder does not exist.', array( 'status' => 404 ) );
		}
		$data = array( 'updated_at' => self::now() );
		if ( isset( $in['name'] ) ) {
			$n = self::clean_name( $in['name'] );
			if ( '' === $n ) {
				return new \WP_Error( 'bizyfiles_invalid', 'Give the folder a name.', array( 'status' => 400 ) );
			}
			$data['name'] = self::unique_folder_name( $f['parent_id'], $n, $id );
		}
		if ( isset( $in['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $in['description'] );
		}
		if ( isset( $in['color'] ) ) {
			$data['color'] = preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $in['color'] ) ? $in['color'] : '';
		}
		if ( array_key_exists( 'parent_id', $in ) ) {
			$np = (int) $in['parent_id'];
			if ( $np === (int) $id || ( $np && in_array( (int) $id, Access::path_ids( $np ), true ) ) ) {
				return new \WP_Error( 'bizyfiles_invalid', 'A folder cannot be moved into itself.', array( 'status' => 400 ) );
			}
			if ( $np && ! Access::folder( $np ) ) {
				return new \WP_Error( 'bizyfiles_not_found', 'The destination folder does not exist.', array( 'status' => 404 ) );
			}
			if ( $np !== $f['parent_id'] ) {
				$data['parent_id'] = $np;
				$data['inherit']   = $np ? $f['inherit'] : 0;
				if ( ! $np ) {
					$data['inherit'] = 0;
				}
				$data['name'] = self::unique_folder_name( $np, isset( $data['name'] ) ? $data['name'] : $f['name'], $id );
			}
		}
		$wpdb->update( Install::table( 'folders' ), $data, array( 'id' => (int) $id ) );
		Access::reset();
		return self::folder_row( $id );
	}

	/** Ids of a folder and everything below it (including trashed ones when $all). */
	public static function subtree_ids( $id, $all = false ) {
		global $wpdb;
		$ids   = array( (int) $id );
		$front = array( (int) $id );
		$guard = 0;
		while ( $front && $guard++ < 64 ) {
			$in    = implode( ',', array_map( 'intval', $front ) );
			$kids  = (array) $wpdb->get_col( 'SELECT id FROM ' . Install::table( 'folders' ) . " WHERE parent_id IN ($in)" . ( $all ? '' : ' AND deleted_at IS NULL' ) ); // phpcs:ignore WordPress.DB
			$front = array_values( array_diff( array_map( 'intval', $kids ), $ids ) );
			$ids   = array_merge( $ids, $front );
		}
		return $ids;
	}

	/** Folder and everything in it goes to the recycle bin together. */
	public static function trash_folder( $id ) {
		global $wpdb;
		$f = Access::folder( $id );
		if ( ! $f ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That folder does not exist.', array( 'status' => 404 ) );
		}
		$ids = implode( ',', self::subtree_ids( $id ) );
		$now = self::now();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'folders' ) . " SET deleted_at=%s WHERE id IN ($ids)", $now ) ); // phpcs:ignore WordPress.DB
		return self::finish_trash_folder( $id, $ids, $now );
	}

	private static function finish_trash_folder( $id, $ids, $now ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'files' ) . " SET deleted_at=%s WHERE folder_id IN ($ids) AND deleted_at IS NULL", $now ) ); // phpcs:ignore WordPress.DB
		$wpdb->update( Install::table( 'folders' ), array( 'trash_root' => 1 ), array( 'id' => (int) $id ) );
		Access::reset();
		Activity::log( 'folders', (int) $id, 0, 'trashed', 'Folder moved to the recycle bin' );
		return true;
	}

	/* ───────────────────────── Files ───────────────────────── */

	public static function file_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function path( array $row ) {
		return '' !== (string) $row['stored_name'] ? self::dir() . '/' . basename( $row['stored_name'] ) : '';
	}

	/**
	 * Add a file whose bytes are already in a temporary file we own.
	 *
	 * @param int    $folder_id Destination folder.
	 * @param string $name      Display name.
	 * @param string $tmp_path  File to take over (it is moved).
	 * @param array  $meta      mime, description, tags, file_id (add a new version of this file), note, client_id.
	 * @return array|\WP_Error The file row.
	 */
	public static function add_file( $folder_id, $name, $tmp_path, array $meta = array() ) {
		global $wpdb;
		$folder_id = (int) $folder_id;
		$name      = self::clean_name( $name );
		if ( '' === $name || ! self::allowed( $name ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'bizyfiles_type', 'That kind of file is not allowed here.', array( 'status' => 400 ) );
		}
		if ( ! is_file( $tmp_path ) ) {
			return new \WP_Error( 'bizyfiles_upload', 'The upload is missing.', array( 'status' => 400 ) );
		}
		$ext    = self::ext( $name );
		$stored = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest   = self::dir() . '/' . $stored;
		if ( ! @rename( $tmp_path, $dest ) && ! ( @copy( $tmp_path, $dest ) && @unlink( $tmp_path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'bizyfiles_upload', 'The file could not be saved on the server.', array( 'status' => 500 ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		$size = (int) filesize( $dest );
		$hash = hash_file( 'sha256', $dest );
		$mime = ! empty( $meta['mime'] ) && preg_match( '#^[a-z]+/[a-z0-9.+-]+$#i', $meta['mime'] ) ? strtolower( $meta['mime'] ) : self::mime_for( $name );
		$now  = self::now();
		$uid  = get_current_user_id();

		if ( ! empty( $meta['file_id'] ) ) { // a new version of an existing file
			$file = self::file_row( $meta['file_id'] );
			if ( ! $file ) {
				@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'bizyfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
			}
			$v = (int) $file['version'] + 1;
			$wpdb->insert( Install::table( 'versions' ), array( 'file_id' => (int) $file['id'], 'version' => $v, 'name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'note' => isset( $meta['note'] ) ? sanitize_text_field( $meta['note'] ) : '', 'uploaded_by' => $uid, 'created_at' => $now ) );
			$wpdb->update( Install::table( 'files' ), array( 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'version' => $v, 'ext' => $ext, 'updated_at' => $now ), array( 'id' => (int) $file['id'] ) );
			Activity::log( 'files', (int) $file['id'], 0, 'version', sprintf( 'New version (v%d) of %s', $v, $file['name'] ) );
			do_action( 'bizyfiles_file_uploaded', self::file_row( $file['id'] ), true );
			return self::file_row( $file['id'] );
		}

		$ok = $wpdb->insert( Install::table( 'files' ), array(
			'folder_id'          => $folder_id,
			'name'               => self::unique_name( $folder_id, $name ),
			'ext'                => $ext,
			'stored_name'        => $stored,
			'mime'               => $mime,
			'size'               => $size,
			'sha256'             => $hash,
			'version'            => 1,
			'description'        => isset( $meta['description'] ) ? sanitize_textarea_field( $meta['description'] ) : '',
			'tags'               => isset( $meta['tags'] ) ? mb_substr( sanitize_text_field( $meta['tags'] ), 0, 250 ) : '',
			'uploaded_by'        => $uid,
			'uploaded_by_client' => isset( $meta['client_id'] ) ? (int) $meta['client_id'] : 0,
			'created_at'         => $now,
			'updated_at'         => $now,
		) );
		if ( ! $ok ) {
			@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'bizyfiles_db', 'The file could not be recorded.', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->insert( Install::table( 'versions' ), array( 'file_id' => $id, 'version' => 1, 'name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'uploaded_by' => $uid, 'created_at' => $now ) );
		Activity::log( 'files', $id, isset( $meta['client_id'] ) ? (int) $meta['client_id'] : 0, 'uploaded', 'File added: ' . $name );
		$row = self::file_row( $id );
		do_action( 'bizyfiles_file_uploaded', $row, false );
		return $row;
	}

	public static function rename_or_move( $id, array $in ) {
		global $wpdb;
		$f = self::file_row( $id );
		if ( ! $f ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
		}
		$data   = array( 'updated_at' => self::now() );
		$folder = isset( $in['folder_id'] ) ? (int) $in['folder_id'] : (int) $f['folder_id'];
		$name   = $f['name'];
		if ( isset( $in['name'] ) ) {
			$n = self::clean_name( $in['name'] );
			if ( '' === $n ) {
				return new \WP_Error( 'bizyfiles_invalid', 'Give the file a name.', array( 'status' => 400 ) );
			}
			if ( '' === (string) $f['link_url'] && self::ext( $n ) !== self::ext( $f['name'] ) && ! self::allowed( $n ) ) {
				return new \WP_Error( 'bizyfiles_type', 'That kind of file is not allowed here.', array( 'status' => 400 ) );
			}
			$name = $n;
		}
		if ( $folder !== (int) $f['folder_id'] && ! Access::folder( $folder ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'The destination folder does not exist.', array( 'status' => 404 ) );
		}
		$data['name']      = self::unique_name( $folder, $name, $id );
		$data['folder_id'] = $folder;
		$data['ext']       = self::ext( $data['name'] );
		if ( isset( $in['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $in['description'] );
		}
		if ( isset( $in['tags'] ) ) {
			$data['tags'] = mb_substr( sanitize_text_field( $in['tags'] ), 0, 250 );
		}
		if ( isset( $in['link_url'] ) && '' !== (string) $f['link_url'] ) {
			$u = esc_url_raw( trim( (string) $in['link_url'] ), array( 'http', 'https' ) );
			if ( '' === $u ) {
				return new \WP_Error( 'bizyfiles_invalid', 'Enter a web address that starts with http:// or https://.', array( 'status' => 400 ) );
			}
			$data['link_url'] = $u;
		}
		$wpdb->update( Install::table( 'files' ), $data, array( 'id' => (int) $id ) );
		return self::file_row( $id );
	}

	/** Copy a file (its current version) into a folder. */
	public static function copy_file( $id, $folder_id ) {
		$f = self::file_row( $id );
		if ( $f && '' !== (string) $f['link_url'] ) {
			return self::add_link( $folder_id, $f['name'], $f['link_url'], array( 'description' => $f['description'] ) );
		}
		if ( ! $f || ! is_readable( self::path( $f ) ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
		}
		if ( ! Access::folder( $folder_id ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'The destination folder does not exist.', array( 'status' => 404 ) );
		}
		$tmp = self::dir() . '/.copy-' . bin2hex( random_bytes( 6 ) );
		if ( ! @copy( self::path( $f ), $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'bizyfiles_upload', 'The file could not be copied.', array( 'status' => 500 ) );
		}
		return self::add_file( $folder_id, $f['name'], $tmp, array( 'mime' => $f['mime'], 'description' => $f['description'], 'tags' => $f['tags'] ) );
	}

	public static function trash_file( $id ) {
		global $wpdb;
		$f = self::file_row( $id );
		if ( ! $f ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
		}
		$wpdb->update( Install::table( 'files' ), array( 'deleted_at' => self::now(), 'trash_root' => 1 ), array( 'id' => (int) $id ) );
		Activity::log( 'files', (int) $id, 0, 'trashed', 'File moved to the recycle bin: ' . $f['name'] );
		return true;
	}


	/* ───────────────────────── Links (video and files hosted elsewhere) ───────────────────────── */

	/**
	 * A safe address to show a link inside the page, for the sites people host video on; '' for anything else (it then opens in a new tab).
	 * Only these hosts are ever embedded.
	 */
	public static function embed_url( $url ) {
		$u = wp_parse_url( (string) $url );
		if ( empty( $u['host'] ) || empty( $u['scheme'] ) || ! in_array( $u['scheme'], array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '/^www\./', '', $u['host'] ) );
		$path = isset( $u['path'] ) ? $u['path'] : '';
		parse_str( isset( $u['query'] ) ? $u['query'] : '', $q );
		if ( in_array( $host, array( 'youtube.com', 'm.youtube.com', 'youtube-nocookie.com' ), true ) ) {
			if ( ! empty( $q['v'] ) && preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $q['v'] ) ) {
				return 'https://www.youtube-nocookie.com/embed/' . $q['v'];
			}
			if ( preg_match( '#^/(embed|shorts|live)/([A-Za-z0-9_-]{6,20})#', $path, $m ) ) {
				return 'https://www.youtube-nocookie.com/embed/' . $m[2];
			}
		}
		if ( 'youtu.be' === $host && preg_match( '#^/([A-Za-z0-9_-]{6,20})#', $path, $m ) ) {
			return 'https://www.youtube-nocookie.com/embed/' . $m[1];
		}
		if ( in_array( $host, array( 'vimeo.com', 'player.vimeo.com' ), true ) && preg_match( '#/(\d{5,12})(?:/([a-f0-9]{8,12}))?#', $path, $m ) ) {
			return 'https://player.vimeo.com/video/' . $m[1] . ( ! empty( $m[2] ) ? '?h=' . $m[2] : '' );
		}
		if ( 'loom.com' === $host && preg_match( '#^/(?:share|embed)/([a-f0-9]{16,40})#', $path, $m ) ) {
			return 'https://www.loom.com/embed/' . $m[1];
		}
		if ( 'drive.google.com' === $host && preg_match( '#^/file/d/([A-Za-z0-9_-]{10,80})#', $path, $m ) ) {
			return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
		}
		if ( in_array( $host, array( 'iframe.mediadelivery.net' ), true ) && preg_match( '#^/(?:embed|play)/(\d+)/([a-f0-9-]{20,40})#', $path, $m ) ) {
			return 'https://iframe.mediadelivery.net/embed/' . $m[1] . '/' . $m[2]; // Bunny Stream
		}
		return '';
	}

	/** Add a link as an item in a folder. @return array|\WP_Error the file row */
	public static function add_link( $folder_id, $name, $url, array $meta = array() ) {
		global $wpdb;
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		if ( '' === $url || strlen( $url ) > 500 ) {
			return new \WP_Error( 'bizyfiles_invalid', 'Enter a web address that starts with http:// or https://.', array( 'status' => 400 ) );
		}
		if ( ! Access::folder( $folder_id ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That folder does not exist.', array( 'status' => 404 ) );
		}
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			$name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		$now = self::now();
		$ok  = $wpdb->insert( Install::table( 'files' ), array(
			'folder_id'   => (int) $folder_id,
			'name'        => self::unique_name( (int) $folder_id, $name ),
			'ext'         => 'link',
			'stored_name' => '',
			'mime'        => 'text/uri-list',
			'size'        => 0,
			'sha256'      => '',
			'version'     => 1,
			'description' => isset( $meta['description'] ) ? sanitize_textarea_field( $meta['description'] ) : '',
			'link_url'    => $url,
			'uploaded_by' => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
		if ( ! $ok ) {
			return new \WP_Error( 'bizyfiles_db', 'The link could not be saved.', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		Activity::log( 'files', $id, 0, 'uploaded', 'Link added: ' . $name );
		return self::file_row( $id );
	}

	/* ───────────────────────── Copying a folder ───────────────────────── */

	/**
	 * Copy a folder with everything the person can see inside it into another place. Files are copied byte for byte.
	 * Only managers carry a folder's own access rules along; for everyone else the copy takes the new place's rules.
	 *
	 * @return array|\WP_Error the new top folder row
	 */
	public static function copy_folder( $id, $dest_parent, array $viewer ) {
		global $wpdb;
		$src = Access::folder( $id );
		if ( ! $src ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That folder does not exist.', array( 'status' => 404 ) );
		}
		$dest_parent = (int) $dest_parent;
		if ( $dest_parent && ( $dest_parent === (int) $id || in_array( (int) $id, Access::path_ids( $dest_parent ), true ) ) ) {
			return new \WP_Error( 'bizyfiles_invalid', 'A folder cannot be copied into itself.', array( 'status' => 400 ) );
		}
		$count = 0;
		$top   = self::copy_folder_into( $src, $dest_parent, $viewer, true, $count );
		return is_wp_error( $top ) ? $top : self::folder_row( $top );
	}

	private static function copy_folder_into( array $src, $dest_parent, array $viewer, $is_top, &$count ) {
		global $wpdb;
		if ( ++$count > 5000 ) {
			return new \WP_Error( 'bizyfiles_invalid', 'That folder is too big to copy in one go.', array( 'status' => 400 ) );
		}
		$new = self::create_folder( $dest_parent, $src['name'] . ( $is_top ? ' - Copy' : '' ), array( 'color' => $src['color'], 'description' => $src['description'], 'inherit' => 1 ) );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$new_id = (int) $new['id'];
		// Carry own rules for managers (a copied top-level folder always needs some, or nobody but managers could see it).
		$defines = 0 === (int) $src['parent_id'] || ! (int) $src['inherit'];
		if ( $viewer['manager'] && $defines ) {
			Access::save_rules( $new_id, 0, Access::rules_of( (int) $src['id'] ) );
		} elseif ( 0 === $dest_parent ) {
			Access::save_rules( $new_id, 0, array( array( 'type' => 'team', 'level' => 'manage' ) ) );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE folder_id=%d AND deleted_at IS NULL', (int) $src['id'] ), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
			if ( ++$count > 5000 ) {
				break;
			}
			if ( '' !== (string) $f['link_url'] ) {
				self::add_link( $new_id, $f['name'], $f['link_url'], array( 'description' => $f['description'] ) );
			} elseif ( is_readable( self::path( $f ) ) ) {
				$tmp = self::dir() . '/.copy-' . bin2hex( random_bytes( 6 ) );
				if ( @copy( self::path( $f ), $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
					self::add_file( $new_id, $f['name'], $tmp, array( 'mime' => $f['mime'], 'description' => $f['description'], 'tags' => $f['tags'] ) );
				}
			}
		}
		foreach ( Access::folders() as $child ) {
			if ( (int) $child['parent_id'] === (int) $src['id'] && Access::level( (int) $child['id'], $viewer ) > 0 ) {
				$r = self::copy_folder_into( $child, $new_id, $viewer, false, $count );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		return $new_id;
	}

	/* ───────────────────────── Versions ───────────────────────── */

	public static function versions( $file_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT v.id, v.version, v.name, v.mime, v.size, v.note, v.created_at, v.uploaded_by, u.display_name AS uploader FROM ' . Install::table( 'versions' ) . " v LEFT JOIN $wpdb->users u ON u.ID=v.uploaded_by WHERE v.file_id=%d ORDER BY v.version DESC", (int) $file_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Make an older version the current one (as a new version, so nothing is lost). */
	public static function restore_version( $file_id, $version ) {
		global $wpdb;
		$f = self::file_row( $file_id );
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'versions' ) . ' WHERE file_id=%d AND version=%d', (int) $file_id, (int) $version ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $f || ! $v || ! is_readable( self::dir() . '/' . basename( $v['stored_name'] ) ) ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That version is not available.', array( 'status' => 404 ) );
		}
		$tmp = self::dir() . '/.copy-' . bin2hex( random_bytes( 6 ) );
		@copy( self::dir() . '/' . basename( $v['stored_name'] ), $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		return self::add_file( (int) $f['folder_id'], $v['name'], $tmp, array( 'file_id' => (int) $file_id, 'mime' => $v['mime'], 'note' => 'Restored from v' . (int) $version ) );
	}

	/* ───────────────────────── Recycle bin ───────────────────────── */

	/** Items in the bin that this person may see: top-level trashed items (files, folders) from folders they manage. */
	public static function trash_list( ?array $viewer = null ) {
		global $wpdb;
		$viewer = $viewer ? $viewer : Access::viewer();
		$out    = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, parent_id, name, deleted_at FROM ' . Install::table( 'folders' ) . ' WHERE deleted_at IS NOT NULL AND trash_root=1 ORDER BY deleted_at DESC', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'folder', 'id' => (int) $r['id'], 'name' => $r['name'], 'deleted_at' => $r['deleted_at'], 'parent_id' => (int) $r['parent_id'], 'size' => 0 );
		}
		foreach ( (array) $wpdb->get_results( 'SELECT id, folder_id, name, size, deleted_at FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NOT NULL AND trash_root=1 ORDER BY deleted_at DESC', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'file', 'id' => (int) $r['id'], 'name' => $r['name'], 'deleted_at' => $r['deleted_at'], 'parent_id' => (int) $r['folder_id'], 'size' => (int) $r['size'], 'ext' => self::ext( $r['name'] ), 'kind' => self::kind( self::ext( $r['name'] ) ) );
		}
		if ( ! $viewer['manager'] ) {
			// Others see the bin only for folders they manage (the folder may itself be gone, so check its nearest living ancestor).
			$out = array_values( array_filter( $out, function ( $i ) use ( $viewer ) {
				return $i['parent_id'] && Access::level( $i['parent_id'], $viewer ) >= Access::LEVELS['manage'];
			} ) );
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['deleted_at'], $a['deleted_at'] );
		} );
		return $out;
	}

	public static function restore( $type, $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( 'folder' === $type ) {
			$f = self::folder_row( $id );
			if ( ! $f || null === $f['deleted_at'] ) {
				return new \WP_Error( 'bizyfiles_not_found', 'That item is not in the recycle bin.', array( 'status' => 404 ) );
			}
			$at  = $f['deleted_at'];
			$ids = implode( ',', self::subtree_ids( $id, true ) );
			// A folder whose parent is gone comes back at the top level.
			if ( (int) $f['parent_id'] && ! Access::folder( $f['parent_id'] ) ) {
				$wpdb->update( Install::table( 'folders' ), array( 'parent_id' => 0, 'inherit' => 0 ), array( 'id' => $id ) );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'folders' ) . " SET deleted_at=NULL, trash_root=0 WHERE id IN ($ids) AND deleted_at=%s", $at ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'files' ) . " SET deleted_at=NULL, trash_root=0 WHERE folder_id IN ($ids) AND deleted_at=%s", $at ) ); // phpcs:ignore WordPress.DB
			Access::reset();
			return true;
		}
		$f = self::file_row( $id );
		if ( ! $f || null === $f['deleted_at'] ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That item is not in the recycle bin.', array( 'status' => 404 ) );
		}
		$folder = Access::folder( $f['folder_id'] ) ? (int) $f['folder_id'] : 0;
		$wpdb->update( Install::table( 'files' ), array( 'deleted_at' => null, 'trash_root' => 0, 'folder_id' => $folder, 'name' => self::unique_name( $folder, $f['name'], $id ) ), array( 'id' => $id ) );
		return true;
	}

	/** Remove a file for good: the row, its versions and the stored bytes. */
	public static function purge_file( array $f ) {
		global $wpdb;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT stored_name FROM ' . Install::table( 'versions' ) . ' WHERE file_id=%d', (int) $f['id'] ), ARRAY_A ) as $v ) { // phpcs:ignore WordPress.DB
			$p = self::dir() . '/' . basename( $v['stored_name'] );
			is_file( $p ) && unlink( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		is_file( self::path( $f ) ) && unlink( self::path( $f ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$wpdb->delete( Install::table( 'versions' ), array( 'file_id' => (int) $f['id'] ) );
		$wpdb->delete( Install::table( 'shares' ), array( 'file_id' => (int) $f['id'] ) );
		$wpdb->delete( Install::table( 'files' ), array( 'id' => (int) $f['id'] ) );
		do_action( 'bizyfiles_file_purged', (int) $f['id'] );
	}

	public static function purge( $type, $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( 'file' === $type ) {
			$f = self::file_row( $id );
			if ( ! $f || null === $f['deleted_at'] ) {
				return new \WP_Error( 'bizyfiles_not_found', 'That item is not in the recycle bin.', array( 'status' => 404 ) );
			}
			self::purge_file( $f );
			return true;
		}
		$f = self::folder_row( $id );
		if ( ! $f || null === $f['deleted_at'] ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That item is not in the recycle bin.', array( 'status' => 404 ) );
		}
		$ids = self::subtree_ids( $id, true );
		$in  = implode( ',', $ids );
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Install::table( 'files' ) . " WHERE folder_id IN ($in)", ARRAY_A ) as $file ) { // phpcs:ignore WordPress.DB
			self::purge_file( $file );
		}
		$wpdb->query( 'DELETE FROM ' . Install::table( 'access' ) . " WHERE folder_id IN ($in)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Install::table( 'folders' ) . " WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB
		Access::reset();
		return true;
	}

	/** Empty the bin of everything older than N days (0 = never auto-purge). */
	public static function auto_purge() {
		global $wpdb;
		$days = (int) Settings::get( 'trash_days' );
		if ( $days < 1 ) {
			return 0;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$n   = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . Install::table( 'files' ) . ' WHERE deleted_at IS NOT NULL AND trash_root=1 AND deleted_at < %s', $cut ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			self::purge( 'file', $r['id'] ) === true && $n++;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . Install::table( 'folders' ) . ' WHERE deleted_at IS NOT NULL AND trash_root=1 AND deleted_at < %s', $cut ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			self::purge( 'folder', $r['id'] ) === true && $n++;
		}
		return $n;
	}
}
