<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Boots the plugin: the REST API, its kill switch and cache headers, and the
 * log's daily cleanup.
 */
final class Plugin {

	/**
	 * Hooks the plugin in.
	 *
	 * @param string $config_file The path of config.php.
	 */
	public static function boot( string $config_file ): void {
		$config = self::load( $config_file );
		add_filter( 'rest_pre_dispatch', array( self::class, 'refuse_when_disabled' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'no_store' ), 10, 3 );
		add_action( 'rest_api_init', fn() => self::register( $config ) );
		add_action( Write_Log::CLEANUP_HOOK, array( Write_Log::class, 'cleanup' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( Write_Log::CLEANUP_HOOK ) ) {
					wp_schedule_event( time(), 'daily', Write_Log::CLEANUP_HOOK );
				}
			}
		);
		if ( $config instanceof Config ) {
			Change_Log::watch( new Option_Store( $config ) );
		}
	}

	/**
	 * Loads config.php, or explains why it can't be used.
	 *
	 * @param string $config_file The path of config.php.
	 * @return Config|\WP_Error
	 */
	private static function load( string $config_file ) {
		if ( ! is_readable( $config_file ) ) {
			return new \WP_Error( 'metatavu_iac_not_configured', 'The IaC plugin has no config.php.', array( 'status' => 503 ) );
		}
		try {
			$loaded = require $config_file;
			return is_array( $loaded )
				? Config::from_array( $loaded, wp_get_environment_type() )
				: new \WP_Error( 'metatavu_iac_not_configured', 'The IaC plugin\'s config.php must return an array.', array( 'status' => 503 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'metatavu_iac_not_configured', 'The IaC plugin\'s config.php could not be loaded.', array( 'status' => 503 ) );
		}
	}

	/**
	 * Registers the API's routes, or one that explains a missing or invalid
	 * config.php.
	 *
	 * @param Config|\WP_Error $config The configuration.
	 */
	public static function register( $config ): void {
		if ( is_wp_error( $config ) ) {
			register_rest_route(
				Rest_Api::NAMESPACE,
				'/(?P<path>.*)',
				array(
					'methods'             => \WP_REST_Server::ALLMETHODS,
					'callback'            => fn() => $config,
					'permission_callback' => '__return_true',
				)
			);
			return;
		}

		$guard = new Database_Replay_Guard();
		$jwks  = null === $config->test_issuer ? new GitHub_Jwks( $guard ) : new Static_Jwks( $config->test_issuer['jwks'] );
		$api   = new Rest_Api(
			$config,
			new Token_Verifier( $config, $jwks, $guard ),
			new Option_Store( $config ),
			new Package_Store( $config, Package_Store::PLUGIN ),
			new Package_Store( $config, Package_Store::THEME ),
			wp_get_environment_type()
		);
		$api->register();
	}

	/**
	 * Answers every request to the namespace with 503 while
	 * METATAVU_IAC_DISABLED is true in wp-config.php.
	 *
	 * @param mixed            $result  The response so far.
	 * @param \WP_REST_Server  $server  The server.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_when_disabled( $result, $server, $request ) {
		if ( self::is_ours( $request ) && defined( 'METATAVU_IAC_DISABLED' ) && METATAVU_IAC_DISABLED ) {
			return new \WP_Error( 'metatavu_iac_disabled', 'The IaC API is switched off on this site.', array( 'status' => 503 ) );
		}
		return $result;
	}

	/**
	 * Keeps every response of the namespace out of caches.
	 *
	 * @param \WP_REST_Response $response The response.
	 * @param \WP_REST_Server   $server   The server.
	 * @param \WP_REST_Request  $request  The request.
	 */
	public static function no_store( $response, $server, $request ) {
		if ( self::is_ours( $request ) && $response instanceof \WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * Tells whether a request is for this plugin's namespace.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private static function is_ours( $request ): bool {
		return $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/' . Rest_Api::NAMESPACE . '/' );
	}
}
