<?php
/**
 * The whitelisted options.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Reads and writes only the options on the whitelist, never the ones that
 * could take over the site (D6). Sensitive options are read only as their
 * SHA-256 (D9).
 */
final class Option_Store {

	// Refused even when whitelisted: they could hand the site to someone else.
	const FORBIDDEN = array( 'siteurl', 'home', 'default_role', 'users_can_register', 'active_plugins', 'template', 'stylesheet', 'admin_email', 'wp_user_roles' );

	/**
	 * Takes the whitelist.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( private readonly Config $config ) {}

	/**
	 * Tells whether the option can be read and written.
	 *
	 * @param string $name The option.
	 */
	public function is_managed( string $name ): bool {
		global $wpdb;
		// The options table compares names case-insensitively, so SITEURL is siteurl.
		$lower = strtolower( $name );
		return isset( $this->config->options[ $name ] )
			&& ! in_array( $lower, self::FORBIDDEN, true )
			&& strtolower( $wpdb->prefix . 'user_roles' ) !== $lower;
	}

	/**
	 * Every managed option's state.
	 *
	 * @return array Name to state.
	 */
	public function state(): array {
		$state = array();
		foreach ( array_keys( $this->config->options ) as $name ) {
			if ( $this->is_managed( $name ) ) {
				$state[ $name ] = $this->read( $name );
			}
		}
		ksort( $state, SORT_STRING );
		return $state;
	}

	/**
	 * One option's state: whether it exists, and its value or, for a
	 * sensitive option, its value's SHA-256.
	 *
	 * @param string $name A managed option.
	 */
	public function read( string $name ): array {
		$missing = new \stdClass();
		$value   = get_option( $name, $missing );
		if ( $value === $missing ) {
			return array(
				'exists'    => false,
				'sensitive' => $this->is_sensitive( $name ),
			);
		}
		if ( $this->is_sensitive( $name ) ) {
			return array(
				'exists'    => true,
				'sensitive' => true,
				'sha256'    => Canonical_Json::sha256( $value ),
			);
		}
		return array(
			'exists'    => true,
			'sensitive' => false,
			'value'     => $value,
		);
	}

	/**
	 * Sets a managed option.
	 *
	 * @param string $name  The option.
	 * @param mixed  $value The value, decoded from JSON.
	 * @return array|\WP_Error The option's new state.
	 */
	public function write( string $name, $value ) {
		if ( ! isset( $this->config->options[ $name ] ) ) {
			return new \WP_Error( 'metatavu_iac_not_whitelisted', 'Option ' . $name . ' isn\'t on the whitelist.', array( 'status' => 403 ) );
		}
		if ( ! $this->is_managed( $name ) ) {
			return new \WP_Error( 'metatavu_iac_forbidden_option', 'Option ' . $name . ' is never managed through the API, because it could hand the site to someone else.', array( 'status' => 403 ) );
		}
		$before = get_option( $name );
		if ( ! Change_Log::during_api_write( fn() => update_option( $name, $value ) ) && maybe_serialize( $before ) !== maybe_serialize( $value ) ) {
			return new \WP_Error( 'metatavu_iac_write_failed', 'Option ' . $name . ' could not be saved.', array( 'status' => 500 ) );
		}
		return $this->read( $name );
	}

	/**
	 * Tells whether the option is sensitive.
	 *
	 * @param string $name The option.
	 */
	public function is_sensitive( string $name ): bool {
		return true === ( $this->config->options[ $name ]['sensitive'] ?? false );
	}
}
