<?php
/**
 * Where the token signing keys come from.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * The keys a token's signature is verified with.
 */
interface Jwks_Source {

	/**
	 * Returns the signing keys, as firebase/php-jwt's key set (kid to Key).
	 *
	 * @param bool $refresh Fetch them again, because a token named an unknown key.
	 * @return array|\WP_Error
	 */
	public function keys( bool $refresh );
}
