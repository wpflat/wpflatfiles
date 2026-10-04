<?php
namespace BizyFiles;

use BizyCore\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Chunked uploads, so a big file survives hosting limits: the browser cuts the file into pieces (a few MB each), sends
 * them one at a time, and asks to finish. A piece that failed can be sent again. Pieces are appended to a private temp file.
 */
class Uploads {

	const TTL = 6 * HOUR_IN_SECONDS;

	public static function tmp_dir() {
		return Paths::dir( 'files-tmp' );
	}

	/** Largest piece the server will take in one request. */
	public static function chunk_size() {
		$want = (int) Settings::get( 'chunk_mb' ) * MB_IN_BYTES;
		$max  = (int) wp_max_upload_size();
		return max( 256 * KB_IN_BYTES, min( $want, $max > 0 ? $max - 64 * KB_IN_BYTES : $want ) );
	}

	/**
	 * Start an upload.
	 *
	 * @param array $p folder_id, name, size, mime, file_id (optional: new version), note
	 * @return array|\WP_Error upload_id, chunk_size
	 */
	public static function init( array $p ) {
		$name = Store::clean_name( isset( $p['name'] ) ? $p['name'] : '' );
		$size = isset( $p['size'] ) ? (int) $p['size'] : 0;
		if ( '' === $name || ! Store::allowed( $name ) ) {
			return new \WP_Error( 'bizyfiles_type', 'That kind of file is not allowed here.', array( 'status' => 400 ) );
		}
		$max = (int) Settings::get( 'max_mb' ) * MB_IN_BYTES;
		if ( $size < 0 || $size > $max ) {
			return new \WP_Error( 'bizyfiles_size', sprintf( 'That file is too large (limit %d MB).', (int) Settings::get( 'max_mb' ) ), array( 'status' => 413 ) );
		}
		$id = bin2hex( random_bytes( 12 ) );
		$st = array(
			'user'      => get_current_user_id(),
			'folder_id' => isset( $p['folder_id'] ) ? (int) $p['folder_id'] : 0,
			'file_id'   => isset( $p['file_id'] ) ? (int) $p['file_id'] : 0,
			'name'      => $name,
			'size'      => $size,
			'mime'      => isset( $p['mime'] ) ? (string) $p['mime'] : '',
			'note'      => isset( $p['note'] ) ? (string) $p['note'] : '',
			'received'  => 0,
			'next'      => 0,
			'path'      => self::tmp_dir() . '/' . $id . '.part',
		);
		file_put_contents( $st['path'], '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		set_transient( 'bizyfiles_up_' . $id, $st, self::TTL );
		return array( 'upload_id' => $id, 'chunk_size' => self::chunk_size() );
	}

	public static function state( $id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $id );
		$st = $id ? get_transient( 'bizyfiles_up_' . $id ) : false;
		return ( is_array( $st ) && (int) $st['user'] === get_current_user_id() ) ? $st : null;
	}

	/** Take one piece. Pieces must arrive in order; sending the previous one again is harmless. */
	public static function chunk( $upload_id, $index, array $file ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $upload_id );
		$st = self::state( $id );
		if ( ! $st ) {
			return new \WP_Error( 'bizyfiles_upload', 'This upload has expired. Please start it again.', array( 'status' => 410 ) );
		}
		$index = (int) $index;
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'bizyfiles_upload', 'The piece did not arrive. Please try again.', array( 'status' => 400 ) );
		}
		if ( $index === (int) $st['next'] - 1 ) {
			return array( 'received' => (int) $st['received'], 'next' => (int) $st['next'] ); // a repeat of the piece we already have
		}
		if ( $index !== (int) $st['next'] ) {
			return new \WP_Error( 'bizyfiles_upload', 'The pieces arrived out of order. Please try again.', array( 'status' => 409, 'next' => (int) $st['next'] ) );
		}
		$len = (int) filesize( $file['tmp_name'] );
		if ( (int) $st['received'] + $len > (int) Settings::get( 'max_mb' ) * MB_IN_BYTES ) {
			self::abort( $id );
			return new \WP_Error( 'bizyfiles_size', 'That file is too large.', array( 'status' => 413 ) );
		}
		$in  = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( $st['path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return new \WP_Error( 'bizyfiles_upload', 'The server could not store the piece.', array( 'status' => 500 ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$st['received'] += $len;
		$st['next']     += 1;
		set_transient( 'bizyfiles_up_' . $id, $st, self::TTL );
		return array( 'received' => (int) $st['received'], 'next' => (int) $st['next'] );
	}

	/** Put the pieces together and add the file. @return array|\WP_Error the file row */
	public static function finish( $upload_id, $client_id = 0 ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $upload_id );
		$st = self::state( $id );
		if ( ! $st ) {
			return new \WP_Error( 'bizyfiles_upload', 'This upload has expired. Please start it again.', array( 'status' => 410 ) );
		}
		clearstatcache( true, $st['path'] );
		if ( (int) $st['size'] && (int) filesize( $st['path'] ) !== (int) $st['size'] ) {
			self::abort( $id );
			return new \WP_Error( 'bizyfiles_upload', 'The file arrived incomplete. Please upload it again.', array( 'status' => 400 ) );
		}
		delete_transient( 'bizyfiles_up_' . $id );
		return Store::add_file( $st['folder_id'], $st['name'], $st['path'], array( 'mime' => $st['mime'], 'file_id' => $st['file_id'], 'note' => $st['note'], 'client_id' => (int) $client_id ) );
	}

	public static function abort( $upload_id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $upload_id );
		$st = $id ? get_transient( 'bizyfiles_up_' . $id ) : false;
		if ( is_array( $st ) && ! empty( $st['path'] ) ) {
			@unlink( $st['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		delete_transient( 'bizyfiles_up_' . $id );
	}

	/** Remove abandoned temp pieces (daily). */
	public static function sweep() {
		$dir = self::tmp_dir();
		foreach ( (array) glob( $dir . '/*.part' ) as $f ) {
			if ( filemtime( $f ) < time() - self::TTL ) {
				@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
		}
	}
}
