<?php
namespace BizyFiles;

defined( 'ABSPATH' ) || exit;

/** BizyFiles' settings: the look, upload limits and the recycle bin. */
class Settings {

	const OPTION = 'bizyfiles_settings';

	/** theme id => label. The explorer draws itself from CSS variables, so a theme is a set of colours, shapes and density. */
	const THEMES = array(
		'win11'      => 'Windows 11',
		'win11-dark' => 'Windows 11 Dark',
		'win10'      => 'Windows 10',
		'classic'    => 'Classic Explorer',
		'finder'     => 'Finder',
		'classroom'  => 'Classroom',
	);

	public static function defaults() {
		return array(
			'theme'              => 'win11',
			'theme_switch'       => true,   // people may pick their own theme
			'users_can_upload'   => true,   // global switch; a folder still has to give the "upload" level
			'max_mb'             => 500,
			'chunk_mb'           => 4,
			'trash_days'         => 30,
			'default_view'       => 'details', // details | list | tiles | icons
			'user_label'         => '',
			'front_enabled'      => true,    // the stand-alone front-end page for anyone signed in who has access
			'front_slug'         => 'files', // its address: yoursite.com/files/
		);
	}

	public static function get( $key = null ) {
		$s = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		return null === $key ? $s : ( array_key_exists( $key, $s ) ? $s[ $key ] : null );
	}

	public static function update( array $in ) {
		$s = self::get();
		if ( isset( $in['theme'] ) && isset( self::THEMES[ $in['theme'] ] ) ) {
			$s['theme'] = $in['theme'];
		}
		foreach ( array( 'theme_switch', 'users_can_upload' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( isset( $in['max_mb'] ) ) {
			$s['max_mb'] = min( 5000, max( 1, (int) $in['max_mb'] ) );
		}
		if ( isset( $in['chunk_mb'] ) ) {
			$s['chunk_mb'] = min( 32, max( 1, (int) $in['chunk_mb'] ) );
		}
		if ( isset( $in['trash_days'] ) ) {
			$s['trash_days'] = min( 3650, max( 0, (int) $in['trash_days'] ) );
		}
		if ( isset( $in['front_enabled'] ) ) {
			$s['front_enabled'] = (bool) $in['front_enabled'];
		}
		if ( isset( $in['front_slug'] ) ) {
			$slug = sanitize_title( (string) $in['front_slug'] );
			$bad  = array( 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login', 'feed', 'sign', 'contract', 'verify', 'invoice', 'share', 'login', (string) \BizyCore\Settings::get( 'app_slug' ), (string) \BizyCore\Settings::get( 'portal_slug' ) );
			$s['front_slug'] = ( '' !== $slug && ! in_array( $slug, $bad, true ) ) ? $slug : 'files';
		}
		if ( isset( $in['default_view'] ) && in_array( $in['default_view'], array( 'details', 'list', 'tiles', 'icons' ), true ) ) {
			$s['default_view'] = $in['default_view'];
		}
		update_option( self::OPTION, $s, false );
		return $s;
	}
}
