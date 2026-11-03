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
	 * @param string         $environment_type wp_get_environment_type(): plain HTTP is accepted only on `local`.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Token_Verifier $verifier,
		private readonly Option_Store $options,
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
	}

	/**
	 * GET /state: the managed options, and the WordPress and PHP versions.
	 */
	public function state(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'install'     => $this->config->install,
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'options'     => (object) $this->options->state(),
			)
		);
	}

	/**
	 * PUT /options/{name}: sets a whitelisted option to `value`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_option( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! array_key_exists( 'value', $body ) ) {
			return new \WP_Error( 'metatavu_iac_invalid', 'The body is {"value": <JSON>}.', array( 'status' => 400 ) );
		}
		$name  = (string) $request['name'];
		$state = $this->options->write( $name, $body['value'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		Write_Log::write( 'option:' . $name, $this->claims[ spl_object_id( $request ) ] ?? array() );
		return new \WP_REST_Response( $state );
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
