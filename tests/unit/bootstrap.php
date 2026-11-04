<?php
/**
 * Unit tests: WordPress functions are stubbed with Brain Monkey, and the few
 * WordPress classes the plugin uses are minimal stand-ins.
 *
 * @package Metatavu\WordPressIaC
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once dirname( __DIR__, 2 ) . '/metatavu-wordpress-iac/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

require_once __DIR__ . '/class-automatic-upgrader-skin.php';
require_once __DIR__ . '/class-wp-ajax-upgrader-skin.php';
