<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Brain\Monkey\Functions;
use Metatavu\WordPressIaC\GitHub_Jwks;

final class GitHubJwksTest extends Test_Case {

	private array $transients = array();
	private int $fetches      = 0;
	private string $body;

	protected function setUp(): void {
		parent::setUp();
		$this->body = (string) json_encode(
			array(
				'keys' => array(
					array(
						'kty' => 'RSA',
						'kid' => 'key-1',
						'n'   => 'sXch',
						'e'   => 'AQAB',
					),
				),
			)
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function () {
				++$this->fetches;
				return array( 'body' => $this->body );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $response ) => $response['body'] );
	}

	public function test_caches_the_keys(): void {
		$jwks = new GitHub_Jwks( new Memory_Replay_Guard() );
		$this->assertArrayHasKey( 'key-1', $jwks->keys( false ) );
		$this->assertArrayHasKey( 'key-1', $jwks->keys( false ) );
		$this->assertSame( 1, $this->fetches );
	}

	public function test_only_the_lock_owner_refreshes(): void {
		$locks = new Memory_Replay_Guard();
		$jwks  = new GitHub_Jwks( $locks );
		$jwks->keys( false );
		$jwks->keys( true );
		$jwks->keys( true );
		( new GitHub_Jwks( $locks ) )->keys( true );
		$this->assertSame( 2, $this->fetches );
	}

	public function test_an_invalid_document_is_not_cached(): void {
		$this->body = '{"keys": []}';
		$this->assertSame( 503, ( new GitHub_Jwks( new Memory_Replay_Guard() ) )->keys( false )->get_error_data()['status'] );
		$this->assertArrayNotHasKey( GitHub_Jwks::CACHE_KEY, $this->transients );
	}

	public function test_a_failed_refresh_keeps_the_cached_keys(): void {
		$locks = new Memory_Replay_Guard();
		$jwks  = new GitHub_Jwks( $locks );
		$jwks->keys( false );
		$this->body = 'not json';
		$this->assertArrayHasKey( 'key-1', $jwks->keys( true ) );
	}
}
