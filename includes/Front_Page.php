<?php
namespace BizyFiles;

use BizyCore\App_Login;
use BizyCore\Registry;
use BizyCore\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * The stand-alone front-end Files page (yoursite.com/files/): for anyone signed in who has access to some folder, whether or
 * not they are a client/student record — for example a teacher whose role is tied to a group. People with a client record
 * also have Files inside their portal; both show the same explorer.
 */
class Front_Page {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function slug() {
		$s = sanitize_title( (string) Settings::get( 'front_slug' ) );
		return '' !== $s ? $s : 'files';
	}

	public static function url() {
		return home_url( '/' . self::slug() . '/' );
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	public static function maybe_serve() {
		if ( ! Settings::get( 'front_enabled' ) || ! Registry::enabled( 'files' ) || self::requested() !== self::slug() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizyfiles front' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		if ( ! is_user_logged_in() ) {
			App_Login::route_for_portal( self::url(), 'Files' );
			exit;
		}
		if ( ! Access::can_use_api() ) {
			self::page( 'No files for you yet', '<div class="note"><h1>Nothing is shared with you yet</h1><p>When a folder is shared with you, or with a group you belong to, it will appear here. If you expected to see files, please ask the person who looks after them.</p></div>' );
		}
		self::page( 'Files', Portal_Page::mount_html( 'page' ) );
	}

	/** A light page: the business name, who is signed in, sign out, and the content. Then stop. */
	private static function page( $title, $body ) {
		$u     = wp_get_current_user();
		$biz   = (string) \BizyCore\Settings::get( 'business_name' );
		$staff = current_user_can( 'manage_bizycore' );
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' — ' . $biz ); ?></title>
<style>
html,body{margin:0;height:100%;background:#f3f5f8;color:#0e121b;font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.fp-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;height:48px;padding:0 16px;background:#fff;box-shadow:0 1px 0 rgba(14,18,27,.08)}
.fp-bar b{font-size:15px}.fp-bar span{color:#525866;margin-right:12px}.fp-bar a{color:#222530;font-weight:600;text-decoration:none;margin-left:12px}
.fp-main{height:calc(100% - 48px);padding:12px;box-sizing:border-box}
.fp-main .bizyfiles-root{height:100%!important;max-height:none!important;margin:0!important;width:auto!important;left:auto!important;transform:none!important}
.note{max-width:520px;margin:12vh auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 18px 48px -22px rgba(14,18,27,.25)}.note h1{margin:0 0 8px;font-size:20px}
</style>
</head>
<body>
<div class="fp-bar"><b><?php echo esc_html( $biz ); ?></b><div><span><?php echo esc_html( $u->display_name ); ?></span><?php if ( $staff ) : ?><a href="<?php echo esc_url( Urls::app( '/files' ) ); ?>">Open in the app</a><?php endif; ?><a href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">Sign out</a></div></div>
<div class="fp-main"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
</body>
</html>
		<?php
		exit;
	}
}
