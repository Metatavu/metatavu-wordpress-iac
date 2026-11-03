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

	const URL       = 'https://token.actions.githubusercontent.com/.well-known/jwks';
	const CACHE_KEY = 'metatavu_iac_jwks';
	const LOCK      = 'lock:jwks-refresh';

	/**
	 * Takes the lock for refreshes.
	 *
	 * @param Replay_Guard $locks Claims a refresh across workers, atomically: only its owner fetches.
	 */
	public function __construct( private readonly Replay_Guard $locks ) {}

	/**
	 * Returns GitHub's keys.
	 *
	 * @param bool $refresh Fetch them again, because a token named an unknown key.
	 * @return array|\WP_Error
	 */
	public function keys( bool $refresh ) {
		$cached = get_transient( self::CACHE_KEY );
		// At most one refresh every five minutes, whatever the number of workers: these requests aren't authenticated yet.
		$fetch = ! is_array( $cached ) || ( $refresh && $this->locks->claim( self::LOCK, time() + 5 * MINUTE_IN_SECONDS - Token_Verifier::LEEWAY ) );
		if ( ! $fetch ) {
			return self::parse( $cached );
		}
		$response = wp_remote_get( self::URL, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return is_array( $cached ) ? self::parse( $cached ) : self::unavailable();
		}
		$jwks = json_decode( wp_remote_retrieve_body( $response ), true );
		$keys = is_array( $jwks ) ? self::parse( $jwks ) : self::unavailable();
		if ( is_wp_error( $keys ) ) {
			return is_array( $cached ) ? self::parse( $cached ) : $keys;
		}
		set_transient( self::CACHE_KEY, $jwks, HOUR_IN_SECONDS );
		return $keys;
	}

	/**
	 * Parses a key set.
	 *
	 * @param array $jwks A JWKS document.
	 * @return array|\WP_Error
	 */
	public static function parse( array $jwks ) {
		try {
			return JWK::parseKeySet( $jwks, 'RS256' );
		} catch ( \Throwable $e ) {
			return self::unavailable();
		}
	}

	/**
	 * The keys are unavailable.
	 */
	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'metatavu_iac_jwks_unavailable', 'The token signing keys could not be read.', array( 'status' => 503 ) );
	}
}
