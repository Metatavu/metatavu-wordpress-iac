<?php
/**
 * The plugin's log.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Every write through the API with the token behind it (D11), and every
 * change to a managed option made outside it (D10), kept for 365 days. The
 * entries hold GitHub user names of Metatavu staff and WordPress user IDs of
 * the site's editors: personal data, deleted when they expire.
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
	 * Records a change to a managed option made outside the API.
	 *
	 * @param string $target The option, for example `option:blogdescription`.
	 * @param array  $detail Who, from where, and the values.
	 */
	public static function change( string $target, array $detail ): void {
		self::insert( 'change', $target, $detail );
	}

	/**
	 * The newest changes made outside the API.
	 *
	 * @param int $limit At most this many.
	 * @return array Newest first.
	 */
	public static function changes( int $limit ): array {
		global $wpdb;
		Schema::ensure();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the log is the plugin's own table, and must not be cached.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, logged_at, target, detail FROM %i WHERE kind = %s ORDER BY id DESC LIMIT %d', Schema::log_table(), 'change', $limit ), ARRAY_A );
		return array_map(
			fn( $row ) => array(
				'id'     => (int) $row['id'],
				'time'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['logged_at'] . ' UTC' ) ),
				'target' => $row['target'],
				'detail' => json_decode( $row['detail'], true ),
			),
			is_array( $rows ) ? $rows : array()
		);
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
	 * @param string $kind   `write` or `change`.
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
