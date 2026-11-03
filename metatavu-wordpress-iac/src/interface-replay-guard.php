<?php
/**
 * Refuses a write token used twice.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Remembers the write tokens' jti until they expire (D5).
 */
interface Replay_Guard {

	/**
	 * Records a jti, atomically.
	 *
	 * @param string $jti        The token's jti.
	 * @param int    $expires_at The token's exp.
	 * @return bool False when the jti was already used.
	 */
	public function claim( string $jti, int $expires_at ): bool;
}
