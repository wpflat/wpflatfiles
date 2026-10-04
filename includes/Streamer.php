<?php
namespace BizyFiles;

use BizyCore\Paths;
use BizyCore\Services\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Hands files to the browser: downloads, inline previews (with byte ranges, so video and audio can seek), thumbnails
 * and the public share page. Addresses: /?bd_file=<id> (+ &v=<version>, &dl=1, &thumb=1) for signed-in people, /share/<token> for links.
 */
class Streamer {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function url( $file_id, array $args = array() ) {
		$as = Access::simulated_client();
		return add_query_arg( array_merge( array( 'bd_file' => (int) $file_id ), $as ? array( 'bd_as' => $as ) : array(), $args ), home_url( '/' ) );
	}

	public static function folder_url( $folder_id ) {
		$as = Access::simulated_client();
		return add_query_arg( array_merge( array( 'bd_folder' => (int) $folder_id ), $as ? array( 'bd_as' => $as ) : array() ), home_url( '/' ) );
	}

	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizyfiles' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
	}

	private static function deny( $code = 404 ) {
		status_header( $code );
		wp_die( 'This file is not available.', 'Not available', array( 'response' => $code ) );
	}

	public static function maybe_serve() {
		if ( ! empty( $_GET['bd_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::serve_signed_in( absint( $_GET['bd_file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['bd_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::serve_folder_zip( absint( $_GET['bd_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
		if ( preg_match( '#^share/([A-Za-z0-9]{16,40})$#', $rel, $m ) ) {
			self::share_page( $m[1] );
		}
	}

	/* ───────────────────────── Signed-in access ───────────────────────── */

	private static function serve_signed_in( $id ) {
		self::nocache();
		$f = Store::file_row( $id );
		if ( ! $f || null !== $f['deleted_at'] || '' !== (string) $f['link_url'] || ! is_user_logged_in() || ! Access::can( (int) $f['folder_id'], 'view' ) ) {
			self::deny();
		}
		$ver = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$row = $f;
		if ( $ver && $ver !== (int) $f['version'] ) {
			global $wpdb;
			$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'versions' ) . ' WHERE file_id=%d AND version=%d', $id, $ver ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! $v ) {
				self::deny();
			}
			$row = array_merge( $f, array( 'stored_name' => $v['stored_name'], 'mime' => $v['mime'], 'size' => $v['size'], 'name' => $v['name'] ) );
		}
		if ( ! empty( $_GET['thumb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::thumbnail( $row );
		}
		$download = ! empty( $_GET['dl'] ) || ! Store::inline_ok( $row ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $download ) {
			Activity::log( 'files', (int) $f['id'], 0, 'downloaded', 'Downloaded: ' . $f['name'] );
		}
		self::send( Store::path( $row ), $row['mime'], $row['name'], ! $download );
	}

	/** Download a folder as a zip: every file in it and below that the person may open. */
	private static function serve_folder_zip( $id ) {
		self::nocache();
		if ( ! is_user_logged_in() || ! Access::can( $id, 'view' ) ) {
			self::deny();
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::deny( 501 );
		}
		global $wpdb;
		$v     = Access::viewer();
		$root  = Access::folder( $id );
		$tmp   = Uploads::tmp_dir() . '/zip-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		$zip   = new \ZipArchive();
		$limit = (int) Settings::get( 'max_mb' ) * MB_IN_BYTES * 2;
		$total = 0;
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE ) ) {
			self::deny( 500 );
		}
		$walk = function ( $fid, $prefix ) use ( &$walk, $zip, $v, $wpdb, &$total, $limit ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE folder_id=%d AND deleted_at IS NULL', (int) $fid ), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
				$p = Store::path( $f );
				if ( '' === (string) $f['link_url'] && '' !== $p && is_readable( $p ) && $total + (int) $f['size'] <= $limit ) {
					$zip->addFile( $p, $prefix . $f['name'] );
					$total += (int) $f['size'];
				}
			}
			foreach ( Access::folders() as $c ) {
				if ( (int) $c['parent_id'] === (int) $fid && Access::level( (int) $c['id'], $v ) > 0 ) {
					$zip->addEmptyDir( $prefix . $c['name'] );
					$walk( (int) $c['id'], $prefix . $c['name'] . '/' );
				}
			}
		};
		$walk( $id, '' );
		$zip->close();
		Activity::log( 'folders', $id, 0, 'downloaded', 'Folder downloaded as a zip: ' . $root['name'] );
		register_shutdown_function( function () use ( $tmp ) {
			is_file( $tmp ) && unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} );
		if ( ! is_file( $tmp ) ) { // an empty folder makes no zip
			$z = new \ZipArchive();
			$z->open( $tmp, \ZipArchive::CREATE );
			$z->addFromString( 'README.txt', 'This folder is empty.' );
			$z->close();
		}
		self::send( $tmp, 'application/zip', sanitize_file_name( $root['name'] ) . '.zip', false );
	}

	/* ───────────────────────── Sending ───────────────────────── */

	/** Send a file with HTTP range support, then stop. */
	public static function send( $path, $mime, $name, $inline ) {
		if ( '' === $path || ! is_readable( $path ) ) {
			self::deny();
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$code  = 200;
		if ( $size > 0 && ! empty( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', wp_unslash( $_SERVER['HTTP_RANGE'] ), $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$code = 206;
		}
		status_header( $code );
		header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( array( '"', "\r", "\n", '\\' ), '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'application/pdf' !== $mime ) { // the browser's PDF viewer does not work inside a sandbox
			header( "Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'" );
		}
		if ( 206 === $code ) {
			header( "Content-Range: bytes $start-$end/$size" );
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ───────────────────────── Thumbnails ───────────────────────── */

	private static function thumbnail( array $row ) {
		if ( 0 !== strpos( $row['mime'], 'image/' ) || 'image/svg+xml' === $row['mime'] ) {
			self::deny();
		}
		$src   = Store::path( $row );
		$cache = Paths::dir( 'files-thumbs' ) . '/' . substr( basename( $row['stored_name'] ), 0, 32 ) . '-256.jpg';
		if ( ! is_file( $cache ) && is_readable( $src ) ) {
			$ed = wp_get_image_editor( $src );
			if ( ! is_wp_error( $ed ) ) {
				$ed->resize( 256, 256, false );
				$ed->set_quality( 78 );
				$ed->save( $cache, 'image/jpeg' );
			}
		}
		if ( is_file( $cache ) ) {
			header( 'Cache-Control: private, max-age=86400' );
			self::send( $cache, 'image/jpeg', 'thumb.jpg', true );
		}
		self::send( $src, $row['mime'], $row['name'], true ); // no image library: the original (small ones only)
	}

	/* ───────────────────────── Public share links ───────────────────────── */

	private static function share_row( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'shares' ) . ' WHERE token=%s', $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Is this link still good? @return true|string a reason */
	public static function share_state( array $s ) {
		if ( $s['revoked'] ) {
			return 'This link has been turned off.';
		}
		if ( $s['expires_at'] && strtotime( $s['expires_at'] . ' UTC' ) < time() ) {
			return 'This link has expired.';
		}
		if ( (int) $s['max_downloads'] && (int) $s['downloads'] >= (int) $s['max_downloads'] ) {
			return 'This link has reached its download limit.';
		}
		return true;
	}

	public static function share_url( $token ) {
		return home_url( '/share/' . $token );
	}

	private static function share_page( $token ) {
		self::nocache();
		$s = self::share_row( $token );
		$f = $s ? Store::file_row( $s['file_id'] ) : null;
		$biz = (string) \BizyCore\Settings::get( 'business_name' );
		if ( ! $s || ! $f || null !== $f['deleted_at'] ) {
			status_header( 404 );
			echo \BizyCore\Public_Page::shell( 'Link not found', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>This link is not available</h1><p>It may be wrong or may have been removed.</p></div></main>', $biz ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$state = self::share_state( $s );
		if ( true !== $state ) {
			status_header( 410 );
			echo \BizyCore\Public_Page::shell( 'Link unavailable', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>' . esc_html( $state ) . '</h1><p>Please ask the sender for a new link.</p></div></main>', $biz ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$ok_pw = '' === $s['password_hash'];
		$error = '';
		$cookie = 'bdfs_' . substr( md5( $token ), 0, 10 );
		if ( ! $ok_pw ) {
			if ( ! empty( $_COOKIE[ $cookie ] ) && hash_equals( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), (string) $_COOKIE[ $cookie ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$ok_pw = true;
			} elseif ( isset( $_POST['pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( wp_check_password( wp_unslash( $_POST['pw'] ), $s['password_hash'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$ok_pw = true;
					setcookie( $cookie, hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), time() + 2 * HOUR_IN_SECONDS, '/', '', is_ssl(), true );
					$_COOKIE[ $cookie ] = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
				} else {
					$error = 'That password is not right.';
				}
			}
		}
		if ( $ok_pw && ! empty( $_GET['get'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			global $wpdb;
			$inline = 'view' === $_GET['get'] && Store::inline_ok( $f ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $inline ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'shares' ) . ' SET downloads=downloads+1 WHERE id=%d', (int) $s['id'] ) ); // phpcs:ignore WordPress.DB
				Activity::log( 'files', (int) $f['id'], 0, 'shared_download', 'Downloaded through a share link: ' . $f['name'] );
			}
			self::send( Store::path( $f ), $f['mime'], $f['name'], $inline );
		}
		$body = '<main class="bd-wrap"><div class="bd-doc" style="max-width:640px;margin:0 auto">';
		$body .= '<div class="bd-eyebrow">Shared file</div><h1 style="margin:6px 0 4px;font-size:22px">' . esc_html( $f['name'] ) . '</h1><p class="bd-muted">' . esc_html( size_format( (int) $f['size'] ) ) . ' · ' . esc_html( strtoupper( $f['ext'] ) ) . '</p>';
		if ( ! $ok_pw ) {
			$body .= '<form method="post" style="margin-top:18px"><p>This file is protected. Enter the password you were given.</p>' . ( $error ? '<p class="bd-late">' . esc_html( $error ) . '</p>' : '' ) . '<p><input type="password" name="pw" autofocus style="padding:10px;border-radius:8px;border:1px solid #ccd;min-width:240px"> <button class="solid" style="padding:10px 16px;border:0;border-radius:8px;background:#222530;color:#fff;font-weight:600;cursor:pointer">Open</button></p></form>';
		} else {
			$get = esc_url( add_query_arg( 'get', 'dl' ) );
			if ( 0 === strpos( $f['mime'], 'image/' ) && Store::inline_ok( $f ) ) {
				$body .= '<p><img src="' . esc_url( add_query_arg( 'get', 'view' ) ) . '" alt="" style="max-width:100%;border-radius:10px"></p>';
			} elseif ( 0 === strpos( $f['mime'], 'video/' ) && Store::inline_ok( $f ) ) {
				$body .= '<p><video controls preload="metadata" style="width:100%;border-radius:10px" src="' . esc_url( add_query_arg( 'get', 'view' ) ) . '"></video></p>';
			} elseif ( 0 === strpos( $f['mime'], 'audio/' ) && Store::inline_ok( $f ) ) {
				$body .= '<p><audio controls style="width:100%" src="' . esc_url( add_query_arg( 'get', 'view' ) ) . '"></audio></p>';
			}
			$body .= '<p style="margin-top:18px"><a class="solid" href="' . $get . '" style="display:inline-block;padding:11px 20px;border-radius:9px;background:#222530;color:#fff;font-weight:600;text-decoration:none">Download</a></p>';
		}
		$body .= '</div></main>';
		status_header( 200 );
		echo \BizyCore\Public_Page::shell( $f['name'] . ' — ' . $biz, $body, $biz ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ───────────────────────── Creating links ───────────────────────── */

	/** @return array|\WP_Error url, token, expires_at */
	public static function create_share( $file_id, array $opts ) {
		global $wpdb;
		$f = Store::file_row( $file_id );
		if ( ! $f ) {
			return new \WP_Error( 'bizyfiles_not_found', 'That file does not exist.', array( 'status' => 404 ) );
		}
		$days   = isset( $opts['expires_days'] ) ? (int) $opts['expires_days'] : 7;
		$expiry = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;
		$token  = '';
		do {
			$token = wp_generate_password( 24, false, false );
		} while ( self::share_row( $token ) );
		$wpdb->insert( Install::table( 'shares' ), array(
			'token'         => $token,
			'file_id'       => (int) $file_id,
			'password_hash' => ! empty( $opts['password'] ) ? wp_hash_password( (string) $opts['password'] ) : '',
			'expires_at'    => $expiry,
			'max_downloads' => isset( $opts['max_downloads'] ) ? max( 0, (int) $opts['max_downloads'] ) : 0,
			'created_by'    => get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
		) );
		$share_id = (int) $wpdb->insert_id;
		Activity::log( 'files', (int) $file_id, 0, 'shared', 'Share link created for ' . $f['name'] );
		return array( 'id' => $share_id, 'token' => $token, 'url' => self::share_url( $token ), 'expires_at' => $expiry, 'protected' => ! empty( $opts['password'] ) );
	}
}
