<?php
/**
 * The plugin's tables.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Creates the tables on first use: a must-use plugin has no activation hook (D1).
 */
final class Schema {

	const VERSION        = '1';
	const VERSION_OPTION = 'metatavu_iac_schema';

	/**
	 * The used write tokens' table.
	 */
	public static function jti_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'metatavu_iac_jti';
	}

	/**
	 * The log's table.
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'metatavu_iac_log';
	}

	/**
	 * Creates or updates the tables when this version hasn't yet.
	 */
	public static function ensure(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::jti_table() . " (
				jti varchar(191) NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (jti),
				KEY expires_at (expires_at)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::log_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				logged_at datetime NOT NULL,
				kind varchar(16) NOT NULL,
				target varchar(255) NOT NULL,
				detail longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY logged_at (logged_at)
			) $charset;"
		);
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
