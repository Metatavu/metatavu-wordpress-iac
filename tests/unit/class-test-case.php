<?php
/**
 * Base test case.
 *
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class Test_Case extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias( fn( $value ) => $value instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( fn( $value, $flags = 0 ) => json_encode( $value, $flags ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A valid configuration array.
	 */
	protected static function config_array( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'install'   => 'acmedev',
				'audience'  => 'https://acmedev.wpengine.com',
				'trust'     => array(
					'repository_id'       => 123,
					'repository_owner_id' => 456,
					'read'                => array( 'develop-plan' ),
					'write'               => array( 'develop' ),
				),
				'whitelist' => array(
					'options' => array(
						'blogdescription' => array(),
						'acme_api'        => array( 'sensitive' => true ),
						'siteurl'         => array(),
						'wp_user_roles'   => array(),
					),
					'plugins' => array( 'wordpress-seo' ),
					'themes'  => array( 'acme' ),
				),
			),
			$overrides
		);
	}

	protected static function config( array $overrides = array(), string $environment_type = 'production' ): \Metatavu\WordPressIaC\Config {
		$config = \Metatavu\WordPressIaC\Config::from_array( self::config_array( $overrides ), $environment_type );
		if ( $config instanceof \WP_Error ) {
			throw new \RuntimeException( $config->get_error_message() );
		}
		return $config;
	}
}
