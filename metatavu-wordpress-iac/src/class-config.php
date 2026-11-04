<?php
/**
 * The plugin's configuration.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * The trust settings, the whitelist and the staging directory from
 * config.php, which only the project's bootstrap/ writes (D2). Nothing in the
 * plugin changes it.
 */
final class Config {

	// WP Engine never serves _wpeprivate over HTTP (D21).
	const DEFAULT_STORAGE = '_wpeprivate/metatavu-iac';

	/**
	 * Builds the configuration from config.php's array.
	 *
	 * @param array  $config           What config.php returns.
	 * @param string $environment_type wp_get_environment_type(): a test issuer is accepted only on `local` (D13).
	 * @return Config|\WP_Error
	 */
	public static function from_array( array $config, string $environment_type ) {
		$trust = $config['trust'] ?? null;
		if ( ! is_string( $config['install'] ?? null ) || ! is_string( $config['audience'] ?? null ) || ! is_array( $trust ) ) {
			return self::invalid( 'install, audience and trust are required' );
		}
		foreach ( array( 'repository_id', 'repository_owner_id' ) as $key ) {
			if ( ! is_int( $trust[ $key ] ?? null ) && ! ctype_digit( (string) ( $trust[ $key ] ?? '' ) ) ) {
				return self::invalid( "trust.$key must be a number" );
			}
		}
		foreach ( array( 'read', 'write' ) as $key ) {
			if ( ! self::is_string_list( $trust[ $key ] ?? null ) ) {
				return self::invalid( "trust.$key must be a list of GitHub environments" );
			}
		}

		$whitelist = $config['whitelist'] ?? array();
		if ( ! is_array( $whitelist ) || ! is_array( $whitelist['options'] ?? array() ) ) {
			return self::invalid( 'whitelist and whitelist.options must be arrays' );
		}
		$options = array();
		foreach ( $whitelist['options'] ?? array() as $name => $settings ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $settings ) ) {
				return self::invalid( 'whitelist.options maps option names to their settings' );
			}
			// Fail closed: a sensitive flag that isn't a boolean would expose the value.
			if ( array_key_exists( 'sensitive', $settings ) && ! is_bool( $settings['sensitive'] ) ) {
				return self::invalid( "whitelist.options.$name.sensitive must be true or false" );
			}
			$options[ $name ] = array( 'sensitive' => $settings['sensitive'] ?? false );
		}
		foreach ( array( 'plugins', 'themes' ) as $key ) {
			if ( ! self::is_string_list( $whitelist[ $key ] ?? array() ) ) {
				return self::invalid( "whitelist.$key must be a list of slugs" );
			}
		}

		// Relative to ABSPATH, which differs between SSH and PHP-FPM on WP Engine (D21).
		$storage = $config['storage'] ?? self::DEFAULT_STORAGE;
		if ( ! is_string( $storage ) || ! preg_match( '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $storage ) ) {
			return self::invalid( 'storage must be a directory relative to ABSPATH, such as ' . self::DEFAULT_STORAGE );
		}

		$test_issuer = null;
		if ( isset( $config['test_issuer'] ) ) {
			if ( 'local' !== $environment_type ) {
				return self::invalid( 'a test issuer is accepted only when the environment type is local' );
			}
			if ( ! is_string( $config['test_issuer']['issuer'] ?? null ) || ! is_array( $config['test_issuer']['jwks'] ?? null ) ) {
				return self::invalid( 'test_issuer needs issuer and jwks' );
			}
			$test_issuer = $config['test_issuer'];
		}

		return new self(
			$config['install'],
			$config['audience'],
			(string) $trust['repository_id'],
			(string) $trust['repository_owner_id'],
			array_values( $trust['read'] ),
			array_values( $trust['write'] ),
			$options,
			array_values( $whitelist['plugins'] ?? array() ),
			array_values( $whitelist['themes'] ?? array() ),
			$storage,
			$test_issuer
		);
	}

	/**
	 * Use from_array().
	 *
	 * @param string     $install             The install's name.
	 * @param string     $audience            The tokens' audience: the install's base URL.
	 * @param string     $repository_id       The infra repository's ID.
	 * @param string     $repository_owner_id Its owner's ID.
	 * @param string[]   $read                GitHub environments whose tokens may read.
	 * @param string[]   $write               GitHub environments whose tokens may also write.
	 * @param array      $options             Whitelisted options, name to settings.
	 * @param string[]   $plugins             Whitelisted plugin slugs.
	 * @param string[]   $themes              Whitelisted theme slugs.
	 * @param string     $storage             Where staged release zips are kept, relative to ABSPATH.
	 * @param array|null $test_issuer         A test token issuer, on local only.
	 */
	private function __construct(
		public readonly string $install,
		public readonly string $audience,
		public readonly string $repository_id,
		public readonly string $repository_owner_id,
		public readonly array $read,
		public readonly array $write,
		public readonly array $options,
		public readonly array $plugins,
		public readonly array $themes,
		public readonly string $storage,
		public readonly ?array $test_issuer
	) {}

	/**
	 * Tells whether a token from the GitHub environment may read.
	 *
	 * @param string $environment The token's environment claim.
	 */
	public function can_read( string $environment ): bool {
		return in_array( $environment, $this->read, true ) || $this->can_write( $environment );
	}

	/**
	 * Tells whether a token from the GitHub environment may write.
	 *
	 * @param string $environment The token's environment claim.
	 */
	public function can_write( string $environment ): bool {
		return in_array( $environment, $this->write, true );
	}

	/**
	 * Explains an invalid configuration.
	 *
	 * @param string $reason What is wrong.
	 */
	private static function invalid( string $reason ): \WP_Error {
		return new \WP_Error( 'metatavu_iac_not_configured', 'The IaC plugin\'s config.php is invalid: ' . $reason . '.', array( 'status' => 503 ) );
	}

	/**
	 * Tells whether a value is a list of non-empty strings.
	 *
	 * @param mixed $value The value.
	 */
	private static function is_string_list( $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				return false;
			}
		}
		return true;
	}
}
