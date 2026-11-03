<?php
/**
 * Changes to managed options made outside the API.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC;

/**
 * Logs every change to a managed option that doesn't come through the API:
 * the option, the WordPress user, where the change came from, and the old and
 * new values, or only that it changed for a sensitive option (D10). The next
 * plan shows the drift; the log explains it.
 */
final class Change_Log {

	/**
	 * Whether the API is writing an option right now: its own writes are
	 * logged as writes, not as changes.
	 *
	 * @var bool
	 */
	private static bool $api_writing = false;

	/**
	 * Runs an API write without logging it as a change.
	 *
	 * @param callable $write The write.
	 * @return mixed What the write returns.
	 */
	public static function during_api_write( callable $write ) {
		self::$api_writing = true;
		try {
			return $write();
		} finally {
			self::$api_writing = false;
		}
	}

	/**
	 * Starts watching the managed options.
	 *
	 * @param Option_Store $options The managed options.
	 */
	public static function watch( Option_Store $options ): void {
		add_action( 'added_option', fn( $name, $value ) => self::record( $options, (string) $name, 'added', null, $value ), 10, 2 );
		add_action( 'updated_option', fn( $name, $old, $value ) => self::record( $options, (string) $name, 'updated', $old, $value ), 10, 3 );
		// Before the deletion, while the old value can still be read.
		add_action( 'delete_option', fn( $name ) => self::record( $options, (string) $name, 'deleted', get_option( $name ), null ), 10, 1 );
	}

	/**
	 * Logs a change to a managed option, unless the API made it.
	 *
	 * @param Option_Store $options The managed options.
	 * @param string       $name    The option.
	 * @param string       $action  `added`, `updated` or `deleted`.
	 * @param mixed        $old     The old value.
	 * @param mixed        $value   The new value.
	 */
	private static function record( Option_Store $options, string $name, string $action, $old, $value ): void {
		if ( self::$api_writing || ! $options->is_managed( $name ) ) {
			return;
		}
		$detail = array(
			'action'  => $action,
			'user_id' => get_current_user_id(),
			'source'  => self::source(),
		);
		if ( $options->is_sensitive( $name ) ) {
			$detail['changed'] = true;
		} else {
			$detail['old'] = $old;
			$detail['new'] = $value;
		}
		Write_Log::change( 'option:' . $name, $detail );
	}

	/**
	 * Where the current request comes from.
	 */
	private static function source(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		return ( is_admin() ? 'admin:' : 'web:' ) . ( is_string( $path ) ? $path : '' );
	}
}
