<?php
/**
 * The REST API.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * The metatavu-iac/v1 namespace. Every route denies by default: a request
 * needs a token that passes Token_Verifier, with the scope the route needs.
 */
final class Rest_Api {

	const NAMESPACE = 'metatavu-iac/v1';

	/**
	 * The verified claims of the requests being served.
	 *
	 * @var array<int, array>
	 */
	private array $claims = array();

	/**
	 * Builds the API.
	 *
	 * @param Config         $config   The configuration.
	 * @param Token_Verifier $verifier The token checks.
	 * @param Option_Store   $options  The whitelisted options.
	 * @param Package_Store  $plugins  The whitelisted plugins.
	 * @param Package_Store  $themes   The whitelisted themes.
	 * @param string         $environment_type wp_get_environment_type(): plain HTTP is accepted only on `local`.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Token_Verifier $verifier,
		private readonly Option_Store $options,
		private readonly Package_Store $plugins,
		private readonly Package_Store $themes,
		private readonly string $environment_type
	) {}

	/**
	 * Registers the routes.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, false ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/options/(?P<name>[A-Za-z0-9_.\-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'put_option' ),
				'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, true ),
			)
		);
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			register_rest_route(
				self::NAMESPACE,
				'/' . $kind . '/(?P<slug>[a-z0-9_\-]+)',
				array(
					array(
						'methods'             => 'PUT',
						'callback'            => fn( \WP_REST_Request $request ) => $this->put_package( $request, $kind ),
						'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, true ),
					),
					array(
						'methods'             => 'DELETE',
						'callback'            => fn( \WP_REST_Request $request ) => $this->delete_package( $request, $kind ),
						'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, true ),
					),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'changes' ),
				'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, false ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 1000,
						'default' => 100,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/active-theme',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'put_active_theme' ),
				'permission_callback' => fn( \WP_REST_Request $request ) => $this->authorize( $request, true ),
			)
		);
	}

	/**
	 * GET /state: the managed options, plugins and themes, the active theme,
	 * and the WordPress and PHP versions.
	 */
	public function state(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'install'      => $this->config->install,
				'wp_version'   => get_bloginfo( 'version' ),
				'php_version'  => PHP_VERSION,
				'options'      => (object) $this->options->state(),
				'plugins'      => (object) $this->plugins->state(),
				'themes'       => (object) $this->themes->state(),
				'active_theme' => get_stylesheet(),
			)
		);
	}

	/**
	 * GET /changes: changes to managed options made outside the API, newest first.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function changes( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'changes' => Write_Log::changes( (int) $request['limit'] ) ) );
	}

	/**
	 * PUT /plugins/{slug} and /themes/{slug}: installs or updates a
	 * whitelisted package, and sets whether a plugin is active.
	 *
	 * - wordpress.org: JSON `{"source": "wordpress.org", "active": true}`.
	 * - A release: multipart, with the zip as `package`, and `source=release`,
	 *   `version`, `sha256` and, for a plugin, `active`. PHP parses a
	 *   multipart body only for POST, so it's sent as POST with
	 *   `X-HTTP-Method-Override: PUT`, which WordPress routes here.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $kind    `plugins` or `themes`.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_package( \WP_REST_Request $request, string $kind ) {
		return $this->logged( $request, rtrim( $kind, 's' ) . ':' . $request['slug'], $this->apply_package( $request, $kind ) );
	}

	/**
	 * Does what put_package() describes.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $kind    `plugins` or `themes`.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply_package( \WP_REST_Request $request, string $kind ) {
		$store  = 'plugins' === $kind ? $this->plugins : $this->themes;
		$slug   = (string) $request['slug'];
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$source = $params['source'] ?? null;
		// Validated before anything is installed, so an invalid request changes nothing.
		$active = filter_var( $params['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( 'plugins' === $kind && null === $active ) {
			return new \WP_Error( 'metatavu_iac_invalid', 'active is true or false.', array( 'status' => 400 ) );
		}

		if ( 'wordpress.org' === $source ) {
			$result = $store->install_from_wordpress_org( $slug );
		} elseif ( 'release' === $source ) {
			$file = $request->get_file_params()['package'] ?? null;
			if ( ! is_array( $file ) ) {
				return self::too_large( $request ) ?? new \WP_Error( 'metatavu_iac_invalid', 'A release needs its zip as the multipart field package, sent as POST with X-HTTP-Method-Override: PUT.', array( 'status' => 400 ) );
			}
			if ( in_array( $file['error'] ?? UPLOAD_ERR_OK, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return new \WP_Error( 'metatavu_iac_too_large', 'The zip is larger than this site accepts (' . ini_get( 'upload_max_filesize' ) . ').', array( 'status' => 413 ) );
			}
			if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				return new \WP_Error( 'metatavu_iac_invalid', 'The zip didn\'t arrive.', array( 'status' => 400 ) );
			}
			if ( ! is_string( $params['version'] ?? null ) || ! preg_match( '/^[0-9a-f]{64}$/i', (string) ( $params['sha256'] ?? '' ) ) ) {
				return new \WP_Error( 'metatavu_iac_invalid', 'A release needs its version and sha256.', array( 'status' => 400 ) );
			}
			$result = $store->install_release( $slug, $file['tmp_name'], $params['version'], $params['sha256'] );
		} else {
			return self::too_large( $request ) ?? new \WP_Error( 'metatavu_iac_invalid', 'source is wordpress.org or release. A release is multipart, sent as POST with X-HTTP-Method-Override: PUT.', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'plugins' === $kind ) {
			$result = $store->set_active( $slug, $active );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return new \WP_REST_Response( $store->read( $slug ) );
	}

	/**
	 * DELETE /plugins/{slug} and /themes/{slug}: deactivates a plugin and
	 * deletes the package. The active theme can't be deleted.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $kind    `plugins` or `themes`.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_package( \WP_REST_Request $request, string $kind ) {
		$store  = 'plugins' === $kind ? $this->plugins : $this->themes;
		$slug   = (string) $request['slug'];
		$result = $store->delete( $slug );
		return $this->logged( $request, rtrim( $kind, 's' ) . ':' . $slug, is_wp_error( $result ) ? $result : new \WP_REST_Response( $store->read( $slug ) ) );
	}

	/**
	 * PUT /active-theme: activates a whitelisted, installed theme, `{"slug": "..."}`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_active_theme( \WP_REST_Request $request ) {
		$slug = $request->get_json_params()['slug'] ?? null;
		if ( ! is_string( $slug ) ) {
			return $this->logged( $request, 'active-theme', new \WP_Error( 'metatavu_iac_invalid', 'The body is {"slug": "<theme>"}.', array( 'status' => 400 ) ) );
		}
		$result = $this->themes->activate_theme( $slug );
		return $this->logged( $request, 'active-theme:' . $slug, is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'active_theme' => get_stylesheet() ) ) );
	}

	/**
	 * Logs an authorized write with its outcome, whether or not it
	 * succeeded: a failure can leave a partial change, such as a plugin
	 * installed but not activated (D11).
	 *
	 * @param \WP_REST_Request            $request The request.
	 * @param string                      $target  What it writes, for example `plugin:akismet`.
	 * @param \WP_REST_Response|\WP_Error $result  The response.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	private function logged( \WP_REST_Request $request, string $target, $result ) {
		Write_Log::write( $target, $this->claims[ spl_object_id( $request ) ] ?? array(), is_wp_error( $result ) ? $result->get_error_code() : 'ok' );
		return $result;
	}

	/**
	 * A 413 when the request was larger than post_max_size, which empties it.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private static function too_large( \WP_REST_Request $request ): ?\WP_Error {
		$length = (int) $request->get_header( 'content_length' );
		$limit  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return $limit > 0 && $length > $limit
			? new \WP_Error( 'metatavu_iac_too_large', 'The request is larger than this site accepts (' . ini_get( 'post_max_size' ) . ').', array( 'status' => 413 ) )
			: null;
	}

	/**
	 * PUT /options/{name}: sets a whitelisted option to `value`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_option( \WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$target = 'option:' . $request['name'];
		if ( ! is_array( $body ) || ! array_key_exists( 'value', $body ) ) {
			return $this->logged( $request, $target, new \WP_Error( 'metatavu_iac_invalid', 'The body is {"value": <JSON>}.', array( 'status' => 400 ) ) );
		}
		$state = $this->options->write( (string) $request['name'], $body['value'] );
		return $this->logged( $request, $target, is_wp_error( $state ) ? $state : new \WP_REST_Response( $state ) );
	}

	/**
	 * The request's Authorization header. Apache with mod_php leaves a Bearer
	 * header out of $_SERVER, where WordPress reads headers, so the rewrite
	 * WordPress's .htaccess sets and the raw request headers are tried too.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private static function authorization( \WP_REST_Request $request ): ?string {
		$header = $request->get_header( 'authorization' );
		if ( null === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( null === $header && function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					$header = sanitize_text_field( $value );
				}
			}
		}
		return $header;
	}

	/**
	 * Lets a request through only with a valid token for the scope.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param bool             $write   Whether the route writes.
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request, bool $write ) {
		if ( ! is_ssl() && 'local' !== $this->environment_type ) {
			return new \WP_Error( 'metatavu_iac_https_required', 'The API is served over HTTPS only.', array( 'status' => 403 ) );
		}
		$claims = $this->verifier->verify( self::authorization( $request ), $write );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$this->claims[ spl_object_id( $request ) ] = $claims;
		return true;
	}
}
