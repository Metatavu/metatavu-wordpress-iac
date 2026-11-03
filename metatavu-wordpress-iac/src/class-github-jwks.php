<?php
/**
 * GitHub Actions' token signing keys.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

use Firebase\JWT\JWK;

/**
 * GitHub's keys, cached for an hour and fetched again at most every five
 * minutes when a token names an unknown key (D3).
 */
final class GitHub_Jwks implements Jwks_Source {

	const URL           = 'https://token.actions.githubusercontent.com/.well-known/jwks';
	const CACHE_KEY     = 'metatavu_iac_jwks';
	const REFRESHED_KEY = 'metatavu_iac_jwks_refreshed';

	/**
	 * Returns GitHub's keys.
	 *
	 * @param bool $refresh Fetch them again, because a token named an unknown key.
	 * @return array|\WP_Error
	 */
	public function keys( bool $refresh ) {
		$jwks = get_transient( self::CACHE_KEY );
		if ( $refresh && false === get_transient( self::REFRESHED_KEY ) ) {
			set_transient( self::REFRESHED_KEY, 1, 5 * MINUTE_IN_SECONDS );
			$jwks = false;
		}
		if ( ! is_array( $jwks ) ) {
			$response = wp_remote_get( self::URL, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return new \WP_Error( 'metatavu_iac_jwks_unavailable', 'GitHub\'s token signing keys could not be fetched.', array( 'status' => 503 ) );
			}
			$jwks = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $jwks ) || ! isset( $jwks['keys'] ) ) {
				return new \WP_Error( 'metatavu_iac_jwks_unavailable', 'GitHub\'s token signing keys could not be read.', array( 'status' => 503 ) );
			}
			set_transient( self::CACHE_KEY, $jwks, HOUR_IN_SECONDS );
		}
		return JWK::parseKeySet( $jwks, 'RS256' );
	}
}
