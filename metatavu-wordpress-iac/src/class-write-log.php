<?php
/**
 * The plugin's log.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Every write through the API with the token behind it (D11), kept for 365
 * days. The entries hold GitHub user names of Metatavu staff: personal data,
 * deleted when they expire.
 */
final class Write_Log {

	const RETENTION_DAYS = 365;
	const CLEANUP_HOOK   = 'metatavu_iac_cleanup';

	// The token claims a write is traced with.
	const CLAIMS = array( 'repository', 'repository_id', 'run_id', 'run_attempt', 'sha', 'ref', 'actor', 'environment', 'jti', 'workflow_ref' );

	/**
	 * Records a write, and how it ended.
	 *
	 * @param string $target  What was written, for example `option:blogdescription`.
	 * @param array  $claims  The token's claims.
	 * @param string $outcome `ok`, or the error code.
	 */
	public static function write( string $target, array $claims, string $outcome = 'ok' ): void {
		self::insert( 'write', $target, array_intersect_key( $claims, array_flip( self::CLAIMS ) ) + array( 'outcome' => $outcome ) );
	}

	/**
	 * Deletes the entries older than the retention.
	 */
	public static function cleanup(): void {
		global $wpdb;
		Schema::ensure();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the log is the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE logged_at < %s', Schema::log_table(), gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Adds an entry.
	 *
	 * @param string $kind   `write`.
	 * @param string $target What it's about.
	 * @param array  $detail Its details.
	 */
	private static function insert( string $kind, string $target, array $detail ): void {
		global $wpdb;
		Schema::ensure();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the log is the plugin's own table.
		$wpdb->insert(
			Schema::log_table(),
			array(
				'logged_at' => gmdate( 'Y-m-d H:i:s' ),
				'kind'      => $kind,
				'target'    => $target,
				'detail'    => (string) wp_json_encode( $detail ),
			)
		);
	}
}
