<?php
/**
 * Used write tokens in the database.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Stores each write token's jti under a unique key until it expires, so two
 * parallel requests with the same token can't both pass (D5).
 */
final class Database_Replay_Guard implements Replay_Guard {

	/**
	 * Records a jti, atomically.
	 *
	 * @param string $jti        The token's jti.
	 * @param int    $expires_at The token's exp.
	 * @return bool False when the jti was already used.
	 */
	public function claim( string $jti, int $expires_at ): bool {
		global $wpdb;
		Schema::ensure();
		$table = Schema::jti_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the unique key is the point; no cache applies.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', $table, gmdate( 'Y-m-d H:i:s' ) ) );
		$inserted = $wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO %i (jti, expires_at) VALUES (%s, %s)', $table, $jti, gmdate( 'Y-m-d H:i:s', $expires_at + Token_Verifier::LEEWAY ) )
		);
		// phpcs:enable
		return 1 === $inserted;
	}
}
