<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Metatavu\WordPressIaC\Replay_Guard;

final class Memory_Replay_Guard implements Replay_Guard {
	private array $used = array();

	public function claim( string $jti, int $expires_at ): bool {
		if ( isset( $this->used[ $jti ] ) ) {
			return false;
		}
		$this->used[ $jti ] = $expires_at;
		return true;
	}
}
