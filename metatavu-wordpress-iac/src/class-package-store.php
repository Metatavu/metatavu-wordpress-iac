<?php
/**
 * The whitelisted plugins and themes.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Installs, updates and deletes only whitelisted plugins or themes, with
 * WordPress's own upgraders, which keep a temporary backup and restore it
 * when an update fails (D8). A release zip arrives in the request and is
 * checked against its declared SHA-256 before anything is unpacked (D7);
 * wordpress.org is the only place the plugin downloads from.
 */
final class Package_Store {

	const PLUGIN = 'plugin';
	const THEME  = 'theme';

	// The plugin never manages itself: bootstrap/ installs it over SSH.
	const OWN_SLUG = 'metatavu-wordpress-iac';

	/**
	 * Takes the whitelist and the kind of package.
	 *
	 * @param Config $config The configuration.
	 * @param string $type   self::PLUGIN or self::THEME.
	 */
	public function __construct( private readonly Config $config, private readonly string $type ) {}

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
	 * One package's state: whether it's installed, its version, and for a
	 * plugin whether it's active.
	 *
	 * @param string $slug A whitelisted slug.
	 */
	public function read( string $slug ): array {
		if ( self::THEME === $this->type ) {
			$theme = wp_get_theme( $slug );
			return array(
				'installed' => $theme->exists(),
				'version'   => $theme->exists() ? (string) $theme->get( 'Version' ) : null,
			);
		}
		$file = $this->plugin_file( $slug );
		if ( null === $file ) {
			return array(
				'installed' => false,
				'version'   => null,
				'active'    => false,
			);
		}
		return array(
			'installed' => true,
			'version'   => (string) ( get_plugins()[ $file ]['Version'] ?? '' ),
			'active'    => is_plugin_active( $file ),
		);
	}

	/**
	 * Installs a package from wordpress.org unless it's installed: WP Engine
	 * keeps wordpress.org plugins and themes up to date.
	 *
	 * @param string $slug The slug.
	 * @return true|\WP_Error
	 */
	public function install_from_wordpress_org( string $slug ) {
		$refused = $this->refuse_unmanaged( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( $this->read( $slug )['installed'] ) {
			return true;
		}
		self::load_admin();
		$info = self::PLUGIN === $this->type
			? plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			)
			: themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);
		if ( is_wp_error( $info ) || empty( $info->download_link ) ) {
			return new \WP_Error( 'metatavu_iac_not_on_wordpress_org', ucfirst( $this->type ) . ' ' . $slug . ' isn\'t on wordpress.org.', array( 'status' => 422 ) );
		}
		return $this->upgrade( $slug, $info->download_link );
	}

	/**
	 * Installs or updates a package from an uploaded release zip, after
	 * checking its SHA-256, that it holds only the slug's directory, and
	 * that its header declares the declared version.
	 *
	 * @param string $slug    The slug.
	 * @param string $zip     The uploaded file's path.
	 * @param string $version The declared version.
	 * @param string $sha256  The declared SHA-256 of the zip.
	 * @return true|\WP_Error
	 */
	public function install_release( string $slug, string $zip, string $version, string $sha256 ) {
		$refused = $this->refuse_unmanaged( $slug );
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
		return $this->upgrade( $slug, $zip );
	}

	/**
	 * Activates or deactivates an installed plugin.
	 *
	 * @param string $slug   The plugin's slug.
	 * @param bool   $active Whether it should be active.
	 * @return true|\WP_Error
	 */
	public function set_active( string $slug, bool $active ) {
		$file = $this->plugin_file( $slug );
		if ( null === $file ) {
			return new \WP_Error( 'metatavu_iac_not_installed', 'Plugin ' . $slug . ' isn\'t installed.', array( 'status' => 409 ) );
		}
		if ( ! $active ) {
			deactivate_plugins( $file, true );
			return true;
		}
		if ( is_plugin_active( $file ) ) {
			return true;
		}
		$result = activate_plugin( $file, '', false, true );
		return is_wp_error( $result ) ? new \WP_Error( 'metatavu_iac_activation_failed', $result->get_error_message(), array( 'status' => 422 ) ) : true;
	}

	/**
	 * Deletes a package; a plugin is deactivated first, and the active theme
	 * is never deleted.
	 *
	 * @param string $slug The slug.
	 * @return true|\WP_Error
	 */
	public function delete( string $slug ) {
		$refused = $this->refuse_unmanaged( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		self::load_admin();
		if ( self::THEME === $this->type ) {
			if ( ! wp_get_theme( $slug )->exists() ) {
				return true;
			}
			if ( get_stylesheet() === $slug || get_template() === $slug ) {
				return new \WP_Error( 'metatavu_iac_active_theme', 'Theme ' . $slug . ' is active, or the active theme\'s parent.', array( 'status' => 409 ) );
			}
			$result = delete_theme( $slug );
		} else {
			$file = $this->plugin_file( $slug );
			if ( null === $file ) {
				return true;
			}
			deactivate_plugins( $file, true );
			$result = delete_plugins( array( $file ) );
		}
		self::PLUGIN === $this->type ? wp_clean_plugins_cache() : wp_clean_themes_cache();
		return true === $result ? true : new \WP_Error( 'metatavu_iac_delete_failed', ucfirst( $this->type ) . ' ' . $slug . ' could not be deleted.', array( 'status' => 500 ) );
	}

	/**
	 * Activates an installed, whitelisted theme.
	 *
	 * @param string $slug The theme's stylesheet.
	 * @return true|\WP_Error
	 */
	public function activate_theme( string $slug ) {
		$refused = $this->refuse_unmanaged( $slug );
		if ( null !== $refused ) {
			return $refused;
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new \WP_Error( 'metatavu_iac_not_installed', 'Theme ' . $slug . ' isn\'t installed.', array( 'status' => 409 ) );
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
	 * Installs or updates a package with WordPress's upgrader. An installed
	 * package is moved to a temporary backup first and restored if the
	 * update fails.
	 *
	 * @param string $slug    The slug.
	 * @param string $package A zip's path or a wordpress.org download URL.
	 * @return true|\WP_Error
	 */
	private function upgrade( string $slug, string $package ) {
		self::load_admin();
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$plugin   = self::PLUGIN === $this->type;
		$upgrader = $plugin ? new \Plugin_Upgrader( $skin ) : new \Theme_Upgrader( $skin );
		$existing = $this->read( $slug )['installed'];
		// run() skips what install() and upgrade() set up: the messages, and the check that the package really is a plugin or a theme.
		$upgrader->init();
		$existing ? $upgrader->upgrade_strings() : $upgrader->install_strings();
		add_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );

		$hook_extra = array( 'type' => $this->type );
		if ( $existing ) {
			$hook_extra['temp_backup'] = array(
				'slug' => $slug,
				'src'  => $plugin ? WP_PLUGIN_DIR : get_theme_root( $slug ),
				'dir'  => $plugin ? 'plugins' : 'themes',
			);
		}
		$result = $upgrader->run(
			array(
				'package'                     => $package,
				'destination'                 => $plugin ? WP_PLUGIN_DIR : get_theme_root(),
				'clear_destination'           => $existing,
				'abort_if_destination_exists' => ! $existing,
				'clear_working'               => true,
				'hook_extra'                  => $hook_extra,
			)
		);
		remove_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );
		$plugin ? wp_clean_plugins_cache() : wp_clean_themes_cache();

		if ( is_wp_error( $result ) || ! $result || $skin->get_errors()->has_errors() ) {
			$error = is_wp_error( $result ) ? $result : $skin->get_errors();
			return new \WP_Error( 'metatavu_iac_install_failed', 'Installing ' . $slug . ' failed: ' . $error->get_error_message() . ( $existing ? ' The previous version was restored.' : '' ), array( 'status' => 422 ) );
		}
		return true;
	}

	/**
	 * The version a release zip's header declares, refusing a zip that holds
	 * anything but the slug's directory.
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
		$version = null;
		$count   = count( $archive );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $archive->getNameIndex( $i );
			if ( ! str_starts_with( $name, $slug . '/' ) || str_contains( $name, '..' ) ) {
				$archive->close();
				return new \WP_Error( 'metatavu_iac_invalid_zip', 'The zip must hold only the directory ' . $slug . '/.', array( 'status' => 422 ) );
			}
			// A plugin's header is in a PHP file at the top of its directory, a theme's in style.css.
			$header_file = self::PLUGIN === $this->type
				? 1 === substr_count( $name, '/' ) && str_ends_with( $name, '.php' )
				: $slug . '/style.css' === $name;
			if ( null === $version && $header_file ) {
				$version = self::header_version( (string) $archive->getFromIndex( $i, 8192 ) );
			}
		}
		$archive->close();
		if ( null === $version ) {
			return new \WP_Error( 'metatavu_iac_invalid_zip', 'The zip has no ' . $this->type . ' header with a version.', array( 'status' => 422 ) );
		}
		return $version;
	}

	/**
	 * The Version header of a plugin or theme file, as get_file_data() reads it.
	 *
	 * @param string $head The file's first 8 kB.
	 */
	private static function header_version( string $head ): ?string {
		if ( ! preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', $head, $match ) ) {
			return null;
		}
		$version = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		return '' === $version ? null : $version;
	}

	/**
	 * The main file of an installed plugin, relative to the plugins directory.
	 *
	 * @param string $slug The plugin's directory.
	 */
	private function plugin_file( string $slug ): ?string {
		self::load_admin();
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Loads the admin functions the upgraders need, outside wp-admin.
	 */
	private static function load_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}
}
