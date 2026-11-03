<?php
/**
 * A fixed key set.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

use Firebase\JWT\JWK;

/**
 * The keys of a test issuer, from config.php (D13).
 */
final class Static_Jwks implements Jwks_Source {

	/**
	 * Takes the key set.
	 *
	 * @param array $jwks A JWKS document, as an array.
	 */
	public function __construct( private readonly array $jwks ) {}

	/**
	 * Returns the keys.
	 *
	 * @param bool $refresh Ignored: the keys are fixed.
	 * @return array
	 */
	public function keys( bool $refresh ) {
		return JWK::parseKeySet( $this->jwks, 'RS256' );
	}
}
