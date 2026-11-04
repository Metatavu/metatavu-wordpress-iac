<?php
/**
 * The whitelisted plugins and themes.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Manages only whitelisted plugins or themes that the project's bootstrap/
 * has installed: on WP Engine, PHP in a REST request can't create or delete
 * `.php` files (D18). A plugin is activated here. A release's zip arrives in
 * the request and is checked against its declared SHA-256, contents and
 * version (D7), then staged for WordPress's automatic updater
 * (Release_Updates, D19).
 */
final class Package_Store {

	const PLUGIN = 'plugin';
	const THEME  = 'theme';

	// The plugin never manages itself: bootstrap/ installs it over SSH.
	const OWN_SLUG = 'metatavu-wordpress-iac';

	/**
	 * Takes the whitelist, the kind of package, and its releases.
	 *
	 * @param Config          $config   The configuration.
	 * @param string          $type     self::PLUGIN or self::THEME.
	 * @param Release_Updates $releases The declared releases.
	 */
	public function __construct( private readonly Config $config, private readonly string $type, private readonly Release_Updates $releases ) {}

	/**
	 * Tells whether the slug can be managed.
	 *
	 * @param string $slug The plugin's directory or the theme's stylesheet.
	 */
	public function is_managed( string $slug ): bool {
		$whitelist = self::PLUGIN === $this->type ? $this->config->plugins : $this->config->themes;
		return self::OWN_SLUG !== $slug && in_array( $slug, $whitelist, true );
	}

	/**
	 * Every whitelisted package's state.
	 *
	 * @return array Slug to state.
	 */
	public function state(): array {
		$state = array();
		foreach ( self::PLUGIN === $this->type ? $this->config->plugins : $this->config->themes as $slug ) {
			if ( $this->is_managed( $slug ) ) {
				$state[ $slug ] = $this->read( $slug );
			}
		}
		ksort( $state, SORT_STRING );
		return $state;
	}

	/**
	 * One package's state: whether it's installed, its version, for a plugin
	 * whether it's active, and a declared release that isn't installed yet.
	 *
	 * @param string $slug A whitelisted slug.
	 */
	public function read( string $slug ): array {
		$version = self::installed_version( $this->type, $slug );
		$state   = array(
			'installed' => null !== $version,
			'version'   => $version,
		);
		if ( self::PLUGIN === $this->type ) {
			$file            = self::plugin_file( $slug );
			$state['active'] = null !== $file && is_plugin_active( $file );
		}
		$pending = $this->releases->pending( $this->type, $slug, $version );
		if ( null !== $pending ) {
			$state['pending'] = $pending;
		}
		return $state;
	}

	/**
	 * Takes over an installed package from wordpress.org: WP Engine keeps
	 * those up to date, so a release declared earlier is forgotten.
	 *
	 * @param string $slug The slug.
	 * @return true|\WP_Error
	 */
	public function adopt_from_wordpress_org( string $slug ) {
		$refused = $this->refuse_unmanaged( $slug ) ?? $this->refuse_missing( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		$this->releases->forget( $this->type, $slug );
		return true;
	}

	/**
	 * Declares an installed package's release, after checking the uploaded
	 * zip's SHA-256, that it holds only the slug's directory, and that its
	 * header declares the declared version. A version other than the
	 * installed one is staged for WordPress's automatic updater.
	 *
	 * @param string $slug    The slug.
	 * @param string $zip     The uploaded file's path.
	 * @param string $version The declared version.
	 * @param string $sha256  The declared SHA-256 of the zip.
	 * @return true|\WP_Error
	 */
	public function stage_release( string $slug, string $zip, string $version, string $sha256 ) {
		$refused = $this->refuse_unmanaged( $slug ) ?? $this->refuse_missing( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( ! hash_equals( strtolower( $sha256 ), (string) hash_file( 'sha256', $zip ) ) ) {
			return new \WP_Error( 'metatavu_iac_checksum_mismatch', 'The zip\'s SHA-256 isn\'t the declared one.', array( 'status' => 422 ) );
		}
		$zipped = $this->zipped_version( $zip, $slug );
		if ( is_wp_error( $zipped ) ) {
			return $zipped;
		}
		if ( $zipped !== $version ) {
			return new \WP_Error( 'metatavu_iac_version_mismatch', sprintf( 'The zip holds version %s of %s, not %s.', $zipped, $slug, $version ), array( 'status' => 422 ) );
		}
		return $this->releases->declare_release( $this->type, $slug, $version, $sha256, $zip, self::installed_version( $this->type, $slug ) );
	}

	/**
	 * Activates or deactivates an installed plugin.
	 *
	 * @param string $slug   The plugin's slug.
	 * @param bool   $active Whether it should be active.
	 * @return true|\WP_Error
	 */
	public function set_active( string $slug, bool $active ) {
		$file = self::plugin_file( $slug );
		if ( null === $file ) {
			return $this->refuse_missing( $slug );
		}
		// Not silent: the plugin's own activation and deactivation hooks run, as in wp-admin.
		if ( ! $active ) {
			deactivate_plugins( $file );
			return true;
		}
		if ( is_plugin_active( $file ) ) {
			return true;
		}
		$result = activate_plugin( $file );
		return is_wp_error( $result ) ? new \WP_Error( 'metatavu_iac_activation_failed', $result->get_error_message(), array( 'status' => 422 ) ) : true;
	}

	/**
	 * Activates an installed, whitelisted theme.
	 *
	 * @param string $slug The theme's stylesheet.
	 * @return true|\WP_Error
	 */
	public function activate_theme( string $slug ) {
		$refused = $this->refuse_unmanaged( $slug ) ?? $this->refuse_missing( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( get_stylesheet() !== $slug ) {
			switch_theme( $slug );
		}
		return true;
	}

	/**
	 * Explains why a slug can't be managed, or null when it can.
	 *
	 * @param string $slug The slug.
	 */
	private function refuse_unmanaged( string $slug ): ?\WP_Error {
		if ( self::OWN_SLUG === $slug ) {
			return new \WP_Error( 'metatavu_iac_own_slug', 'The IaC plugin isn\'t managed through its own API.', array( 'status' => 403 ) );
		}
		if ( ! $this->is_managed( $slug ) ) {
			return new \WP_Error( 'metatavu_iac_not_whitelisted', ucfirst( $this->type ) . ' ' . $slug . ' isn\'t on the whitelist.', array( 'status' => 403 ) );
		}
		return null;
	}

	/**
	 * Explains that a package must be installed by the project's bootstrap/
	 * first, or null when it's installed (D18).
	 *
	 * @param string $slug The slug.
	 */
	private function refuse_missing( string $slug ): ?\WP_Error {
		if ( null !== self::installed_version( $this->type, $slug ) ) {
			return null;
		}
		return new \WP_Error( 'metatavu_iac_not_installed', ucfirst( $this->type ) . ' ' . $slug . ' isn\'t installed: install it with the project\'s bootstrap/ first.', array( 'status' => 409 ) );
	}

	/**
	 * The version a release zip's header declares, refusing a zip that holds
	 * anything but the slug's directory. A plugin's header is the one
	 * top-level PHP file with a Plugin Name, as WordPress finds the plugin; a
	 * theme's is style.css with a Theme Name.
	 *
	 * @param string $zip  The zip's path.
	 * @param string $slug The slug.
	 * @return string|\WP_Error
	 */
	private function zipped_version( string $zip, string $slug ) {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip, \ZipArchive::RDONLY ) ) {
			return new \WP_Error( 'metatavu_iac_invalid_zip', 'The upload isn\'t a zip.', array( 'status' => 422 ) );
		}
		$headers = array();
		$count   = count( $archive );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $archive->getNameIndex( $i );
			if ( ! str_starts_with( $name, $slug . '/' ) || str_contains( $name, '..' ) ) {
				$archive->close();
				return new \WP_Error( 'metatavu_iac_invalid_zip', 'The zip must hold only the directory ' . $slug . '/.', array( 'status' => 422 ) );
			}
			$candidate = self::PLUGIN === $this->type
				? 1 === substr_count( $name, '/' ) && str_ends_with( $name, '.php' )
				: $slug . '/style.css' === $name;
			if ( $candidate ) {
				$head = (string) $archive->getFromIndex( $i, 8192 );
				if ( null !== self::header( $head, self::PLUGIN === $this->type ? 'Plugin Name' : 'Theme Name' ) ) {
					$headers[ $name ] = self::header( $head, 'Version' );
				}
			}
		}
		$archive->close();
		if ( 1 !== count( $headers ) ) {
			return new \WP_Error( 'metatavu_iac_invalid_zip', sprintf( 'The zip must have exactly one %s header, not %d.', $this->type, count( $headers ) ), array( 'status' => 422 ) );
		}
		$version = reset( $headers );
		if ( null === $version ) {
			return new \WP_Error( 'metatavu_iac_invalid_zip', 'The ' . $this->type . ' header has no Version.', array( 'status' => 422 ) );
		}
		return $version;
	}

	/**
	 * A header of a plugin or theme file, as get_file_data() reads it.
	 *
	 * @param string $head The file's first 8 kB.
	 * @param string $name The header, for example `Version`.
	 */
	private static function header( string $head, string $name ): ?string {
		if ( ! preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $head, $match ) ) {
			return null;
		}
		$value = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		return '' === $value ? null : $value;
	}

	/**
	 * The installed version of a package, or null when it isn't installed.
	 *
	 * @param string $type self::PLUGIN or self::THEME.
	 * @param string $slug The slug.
	 */
	public static function installed_version( string $type, string $slug ): ?string {
		if ( self::THEME === $type ) {
			$theme = wp_get_theme( $slug );
			return $theme->exists() ? (string) $theme->get( 'Version' ) : null;
		}
		$file = self::plugin_file( $slug );
		return null === $file ? null : (string) ( get_plugins()[ $file ]['Version'] ?? '' );
	}

	/**
	 * The main file of an installed plugin, relative to the plugins directory.
	 *
	 * @param string $slug The plugin's directory.
	 */
	public static function plugin_file( string $slug ): ?string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
