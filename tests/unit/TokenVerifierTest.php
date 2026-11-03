<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Metatavu\WordPressIaC\Jwks_Source;
use Metatavu\WordPressIaC\Replay_Guard;
use Metatavu\WordPressIaC\Static_Jwks;
use Metatavu\WordPressIaC\Token_Verifier;

final class TokenVerifierTest extends Test_Case {

	const NOW = 1800000000;

	private static $key;
	private static array $jwks;

	public static function setUpBeforeClass(): void {
		self::$key  = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details    = openssl_pkey_get_details( self::$key );
		self::$jwks = array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'kid' => 'key-1',
					'use' => 'sig',
					'n'   => rtrim( strtr( base64_encode( $details['rsa']['n'] ), '+/', '-_' ), '=' ),
					'e'   => rtrim( strtr( base64_encode( $details['rsa']['e'] ), '+/', '-_' ), '=' ),
				),
			),
		);
	}

	private static function claims( array $overrides = array() ): array {
		return array_merge(
			array(
				'iss'                 => Token_Verifier::GITHUB_ISSUER,
				'aud'                 => 'https://acmedev.wpengine.com',
				'repository'          => 'acme/acme-infra',
				'repository_id'       => '123',
				'repository_owner_id' => '456',
				'environment'         => 'develop',
				'actor'               => 'someone',
				'run_id'              => '99',
				'sha'                 => 'abc',
				'jti'                 => bin2hex( random_bytes( 8 ) ),
				'iat'                 => self::NOW - 10,
				'nbf'                 => self::NOW - 10,
				'exp'                 => self::NOW + 290,
			),
			$overrides
		);
	}

	private static function token( array $claims, string $kid = 'key-1' ): string {
		openssl_pkey_export( self::$key, $pem );
		return 'Bearer ' . JWT::encode( $claims, $pem, 'RS256', $kid );
	}

	private function verifier( ?Replay_Guard $replay = null, ?Jwks_Source $jwks = null ): Token_Verifier {
		return new Token_Verifier( self::config(), $jwks ?? new Static_Jwks( self::$jwks ), $replay ?? new Memory_Replay_Guard(), fn() => self::NOW );
	}

	private function assertRefused( int $status, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $status, $result->get_error_data()['status'], $result->get_error_message() );
	}

	public function test_accepts_a_valid_read_and_write_token(): void {
		$claims = $this->verifier()->verify( self::token( self::claims( array( 'environment' => 'develop-plan' ) ) ), false );
		$this->assertSame( 'develop-plan', $claims['environment'] );
		$this->assertSame( 'someone', $this->verifier()->verify( self::token( self::claims() ), true )['actor'] );
	}

	public function test_refuses_a_missing_or_malformed_header(): void {
		$this->assertRefused( 401, $this->verifier()->verify( null, false ) );
		$this->assertRefused( 401, $this->verifier()->verify( 'Basic dXNlcjpwYXNz', false ) );
	}

	public function test_refuses_another_algorithm(): void {
		$hs256 = 'Bearer ' . JWT::encode( self::claims(), str_repeat( 'k', 32 ), 'HS256', 'key-1' );
		$this->assertRefused( 401, $this->verifier()->verify( $hs256, false ) );
		$none = 'Bearer ' . rtrim( strtr( base64_encode( '{"alg":"none"}' ), '+/', '-_' ), '=' ) . '.' . rtrim( strtr( base64_encode( wp_json_encode( self::claims() ) ), '+/', '-_' ), '=' ) . '.';
		$this->assertRefused( 401, $this->verifier()->verify( $none, false ) );
	}

	public function test_refuses_a_bad_signature(): void {
		$other = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $other, $pem );
		$this->assertRefused( 401, $this->verifier()->verify( 'Bearer ' . JWT::encode( self::claims(), $pem, 'RS256', 'key-1' ), false ) );
	}

	#[DataProvider( 'wrong_claims' )]
	public function test_refuses_each_wrong_claim( array $overrides ): void {
		$this->assertRefused( 401, $this->verifier()->verify( self::token( self::claims( $overrides ) ), false ) );
	}

	public static function wrong_claims(): array {
		return array(
			'issuer'               => array( array( 'iss' => 'https://example.com' ) ),
			'audience'             => array( array( 'aud' => 'https://otherdev.wpengine.com' ) ),
			'repository_id'        => array( array( 'repository_id' => '124' ) ),
			'repository_owner_id'  => array( array( 'repository_owner_id' => '457' ) ),
			'expired'              => array( array( 'exp' => self::NOW - 61 ) ),
			'issued too long ago'  => array(
				array(
					'iat' => self::NOW - 361,
					'nbf' => self::NOW - 361,
					'exp' => self::NOW + 100,
				),
			),
			'issued in the future' => array(
				array(
					'iat' => self::NOW + 120,
					'nbf' => self::NOW - 10,
				),
			),
		);
	}

	public function test_accepts_an_audience_list_and_a_minute_of_skew(): void {
		$this->assertIsArray( $this->verifier()->verify( self::token( self::claims( array( 'aud' => array( 'other', 'https://acmedev.wpengine.com' ) ) ) ), false ) );
		$this->assertIsArray( $this->verifier()->verify( self::token( self::claims( array( 'exp' => self::NOW - 30 ) ) ), false ) );
	}

	public function test_scope_comes_from_the_environment(): void {
		$this->assertRefused( 403, $this->verifier()->verify( self::token( self::claims( array( 'environment' => 'develop-plan' ) ) ), true ) );
		$this->assertRefused( 403, $this->verifier()->verify( self::token( self::claims( array( 'environment' => 'production' ) ) ), false ) );
		$this->assertRefused( 403, $this->verifier()->verify( self::token( self::claims( array( 'environment' => null ) ) ), false ) );
	}

	public function test_a_write_token_works_once(): void {
		$replay = new Memory_Replay_Guard();
		$token  = self::token( self::claims() );
		$this->assertIsArray( $this->verifier( $replay )->verify( $token, true ) );
		$this->assertRefused( 401, $this->verifier( $replay )->verify( $token, true ) );
		$this->assertRefused( 401, $this->verifier( $replay )->verify( self::token( self::claims( array( 'jti' => null ) ) ), true ) );
	}

	public function test_a_read_token_can_be_used_again(): void {
		$replay = new Memory_Replay_Guard();
		$token  = self::token( self::claims( array( 'environment' => 'develop-plan' ) ) );
		$this->assertIsArray( $this->verifier( $replay )->verify( $token, false ) );
		$this->assertIsArray( $this->verifier( $replay )->verify( $token, false ) );
	}

	public function test_fetches_the_keys_again_once_for_an_unknown_key(): void {
		$jwks = new Rotating_Jwks( array( 'keys' => array() ), self::$jwks );
		$this->assertIsArray( $this->verifier( null, $jwks )->verify( self::token( self::claims() ), true ) );
		$this->assertSame( array( false, true ), $jwks->requests );

		$unknown = new Rotating_Jwks( array( 'keys' => array() ), array( 'keys' => array() ) );
		$this->assertRefused( 401, $this->verifier( null, $unknown )->verify( self::token( self::claims(), 'key-9' ), false ) );
		$this->assertSame( array( false, true ), $unknown->requests );
	}
}
