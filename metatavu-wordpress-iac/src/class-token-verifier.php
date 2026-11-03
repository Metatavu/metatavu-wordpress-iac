<?php
/**
 * Verifies the GitHub OIDC tokens the provider sends.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

use Firebase\JWT\JWT;

/**
 * Accepts a token only when every check in the design doc's Authentication
 * holds: RS256 signature, issuer, audience, repository and owner IDs,
 * environment, lifetime, and for writes a jti used once.
 */
final class Token_Verifier {

	const GITHUB_ISSUER = 'https://token.actions.githubusercontent.com';

	// A token is accepted for at most five minutes after it was issued, with a minute of clock skew.
	const MAX_AGE = 300;
	const LEEWAY  = 60;

	/**
	 * Builds a verifier.
	 *
	 * @param Config        $config The trust settings.
	 * @param Jwks_Source   $jwks   The signing keys.
	 * @param Replay_Guard  $replay The used write tokens.
	 * @param \Closure|null $clock  Returns the time as a Unix timestamp; for tests.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Jwks_Source $jwks,
		private readonly Replay_Guard $replay,
		private readonly ?\Closure $clock = null
	) {}

	/**
	 * Verifies an Authorization header.
	 *
	 * @param string|null $authorization The request's Authorization header.
	 * @param bool        $write         Whether the request writes.
	 * @return array|\WP_Error The token's claims, or why it was refused: 401 for an invalid token, 403 for a valid one without the scope.
	 */
	public function verify( ?string $authorization, bool $write ) {
		if ( null === $authorization || ! preg_match( '/^Bearer\s+(\S+)$/', $authorization, $matches ) ) {
			return self::unauthorized( 'A GitHub OIDC token is required.' );
		}
		$token = $matches[1];

		$header = json_decode( (string) JWT::urlsafeB64Decode( explode( '.', $token )[0] ), true );
		if ( ! is_array( $header ) || 'RS256' !== ( $header['alg'] ?? null ) ) {
			return self::unauthorized( 'Only RS256 tokens are accepted.' );
		}
		if ( ! is_string( $header['kid'] ?? null ) ) {
			return self::unauthorized( 'The token names no signing key.' );
		}

		$now    = $this->clock ? ( $this->clock )() : time();
		$claims = $this->decode( $token, $now );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$issuer = $this->config->test_issuer['issuer'] ?? self::GITHUB_ISSUER;
		if ( ( $claims['iss'] ?? null ) !== $issuer ) {
			return self::unauthorized( 'The token has the wrong issuer.' );
		}
		if ( ! in_array( $this->config->audience, (array) ( $claims['aud'] ?? array() ), true ) ) {
			return self::unauthorized( 'The token is for another audience.' );
		}
		// Names alone aren't enough: a deleted repository's name can be reused.
		if ( (string) ( $claims['repository_id'] ?? '' ) !== $this->config->repository_id
			|| (string) ( $claims['repository_owner_id'] ?? '' ) !== $this->config->repository_owner_id ) {
			return self::unauthorized( 'The token is from another repository.' );
		}
		// php-jwt checks iat only when a token has no nbf; GitHub's have both.
		if ( ! isset( $claims['exp'], $claims['iat'] ) || $now - (int) $claims['iat'] > self::MAX_AGE + self::LEEWAY ) {
			return self::unauthorized( 'The token is too old.' );
		}
		if ( (int) $claims['iat'] > $now + self::LEEWAY ) {
			return self::unauthorized( 'The token is issued in the future.' );
		}

		$environment = (string) ( $claims['environment'] ?? '' );
		if ( ! $this->config->can_read( $environment ) || ( $write && ! $this->config->can_write( $environment ) ) ) {
			return new \WP_Error( 'metatavu_iac_forbidden', 'Tokens from this GitHub environment can\'t ' . ( $write ? 'write' : 'read' ) . '.', array( 'status' => 403 ) );
		}
		if ( $write ) {
			if ( ! is_string( $claims['jti'] ?? null ) || '' === $claims['jti'] ) {
				return self::unauthorized( 'A write token needs a jti.' );
			}
			if ( ! $this->replay->claim( $claims['jti'], (int) $claims['exp'] ) ) {
				return self::unauthorized( 'The token was already used.' );
			}
		}
		return $claims;
	}

	/**
	 * Checks the signature and the times, fetching the keys again once when
	 * the token names an unknown key.
	 *
	 * @param string $token The token.
	 * @param int    $now   The time.
	 * @return array|\WP_Error
	 */
	private function decode( string $token, int $now ) {
		foreach ( array( false, true ) as $refresh ) {
			$keys = $this->jwks->keys( $refresh );
			if ( is_wp_error( $keys ) ) {
				return $keys;
			}
			if ( array() === $keys ) {
				continue;
			}
			JWT::$leeway    = self::LEEWAY;
			JWT::$timestamp = $now;
			try {
				return json_decode( wp_json_encode( JWT::decode( $token, $keys ) ), true );
			} catch ( \UnexpectedValueException $e ) {
				if ( ! $refresh && str_contains( $e->getMessage(), '"kid"' ) ) {
					continue;
				}
				return self::unauthorized( 'The token is invalid.' );
			} catch ( \Throwable $e ) {
				return self::unauthorized( 'The token is invalid.' );
			} finally {
				JWT::$timestamp = null;
			}
		}
		return self::unauthorized( 'The token is signed with an unknown key.' );
	}

	/**
	 * A 401 error.
	 *
	 * @param string $message Why.
	 */
	private static function unauthorized( string $message ): \WP_Error {
		return new \WP_Error( 'metatavu_iac_unauthorized', $message, array( 'status' => 401 ) );
	}
}
