<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Metatavu\WordPressIaC\Jwks_Source;

final class Rotating_Jwks implements Jwks_Source {
	public array $requests = array();

	public function __construct( private array $cached, private array $fresh ) {}

	public function keys( bool $refresh ) {
		$this->requests[] = $refresh;
		$jwks             = $refresh ? $this->fresh : $this->cached;
		return array() === $jwks['keys'] ? array() : \Firebase\JWT\JWK::parseKeySet( $jwks, 'RS256' );
	}
}
