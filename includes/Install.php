<?php
namespace WPFlatFiles;

defined( 'ABSPATH' ) || exit;

/** WPFlat Files' tables. */
class Install {

	const DB_VERSION = '2';
	const OPTION     = 'wpflatfiles_db_version';

	/** Short names of the tables (without the WordPress prefix and "wpflatfiles_"). */
	const TABLES = array( 'folders', 'files', 'versions', 'access', 'shares' );

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpflatfiles_' . $name;
	}

	/** Cheap check on every request so an updated plugin upgrades itself. */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install_tables();
		}
	}

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table( 'folders' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  description text,
  color varchar(9) NOT NULL DEFAULT '',
  inherit tinyint(1) NOT NULL DEFAULT 1,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  deleted_at datetime DEFAULT NULL,
  trash_root tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id),
  KEY deleted_at (deleted_at)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'files' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  folder_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  ext varchar(20) NOT NULL DEFAULT '',
  stored_name varchar(190) NOT NULL DEFAULT '',
  mime varchar(120) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  version int(11) NOT NULL DEFAULT 1,
  description text,
  tags varchar(255) NOT NULL DEFAULT '',
  link_url varchar(500) NOT NULL DEFAULT '',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by_client bigint(20) unsigned NOT NULL DEFAULT 0,
  deleted_at datetime DEFAULT NULL,
  trash_root tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY folder_id (folder_id),
  KEY deleted_at (deleted_at),
  KEY name (name)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'versions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  file_id bigint(20) unsigned NOT NULL DEFAULT 0,
  version int(11) NOT NULL DEFAULT 1,
  name varchar(190) NOT NULL DEFAULT '',
  stored_name varchar(190) NOT NULL DEFAULT '',
  mime varchar(120) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY file_id (file_id)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'access' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  folder_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject_type varchar(12) NOT NULL DEFAULT '',
  subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  level varchar(10) NOT NULL DEFAULT 'view',
  PRIMARY KEY  (id),
  KEY folder_id (folder_id),
  KEY subject (subject_type,subject_id)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'shares' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(40) NOT NULL DEFAULT '',
  file_id bigint(20) unsigned NOT NULL DEFAULT 0,
  password_hash varchar(255) NOT NULL DEFAULT '',
  expires_at datetime DEFAULT NULL,
  max_downloads int(11) NOT NULL DEFAULT 0,
  downloads int(11) NOT NULL DEFAULT 0,
  revoked tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY file_id (file_id)
) $c;" );

		update_option( self::OPTION, self::DB_VERSION, false );
	}
}
