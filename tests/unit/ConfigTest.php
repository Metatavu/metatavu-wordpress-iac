<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Metatavu\WordPressIaC\Config;

final class ConfigTest extends Test_Case {

	public function test_reads_a_valid_config(): void {
		$config = self::config();
		$this->assertSame( '123', $config->repository_id );
		$this->assertTrue( $config->can_read( 'develop-plan' ) );
		$this->assertTrue( $config->can_read( 'develop' ) );
		$this->assertFalse( $config->can_write( 'develop-plan' ) );
		$this->assertTrue( $config->options['acme_api']['sensitive'] );
		$this->assertFalse( $config->options['blogdescription']['sensitive'] );
	}

	public function test_refuses_a_config_without_trust(): void {
		$config = Config::from_array(
			array(
				'install'  => 'acmedev',
				'audience' => 'https://acmedev.wpengine.com',
			),
			'production'
		);
		$this->assertInstanceOf( \WP_Error::class, $config );
		$this->assertSame( 503, $config->get_error_data()['status'] );
	}

	public function test_refuses_environments_that_are_not_a_list(): void {
		$this->assertInstanceOf( \WP_Error::class, Config::from_array( self::config_array( array( 'trust' => array( 'write' => 'develop' ) ) ), 'production' ) );
	}

	public function test_refuses_a_sensitive_flag_that_is_not_a_boolean(): void {
		$config = Config::from_array( self::config_array( array( 'whitelist' => array( 'options' => array( 'acme_api' => array( 'sensitive' => 'true' ) ) ) ) ), 'production' );
		$this->assertInstanceOf( \WP_Error::class, $config );
	}

	public function test_refuses_a_whitelist_that_is_not_a_map(): void {
		$this->assertInstanceOf( \WP_Error::class, Config::from_array( array_merge( self::config_array(), array( 'whitelist' => array( 'options' => 'blogdescription' ) ) ), 'production' ) );
		$this->assertInstanceOf( \WP_Error::class, Config::from_array( array_merge( self::config_array(), array( 'whitelist' => 'everything' ) ), 'production' ) );
	}

	public function test_storage_defaults_to_uploads(): void {
		$this->assertSame( 'wp-content/uploads/metatavu-iac', self::config()->storage );
		$this->assertSame( 'private/iac', self::config( array( 'storage' => 'private/iac' ) )->storage );
	}

	public function test_refuses_storage_outside_abspath(): void {
		foreach ( array( '/var/iac', '../iac', '_wpeprivate/../iac', '_wpeprivate/', '' ) as $storage ) {
			$this->assertInstanceOf( \WP_Error::class, Config::from_array( self::config_array( array( 'storage' => $storage ) ), 'production' ), $storage );
		}
	}

	public function test_accepts_a_test_issuer_only_on_local(): void {
		$issuer = array(
			'test_issuer' => array(
				'issuer' => 'https://issuer.test',
				'jwks'   => array( 'keys' => array() ),
			),
		);
		$this->assertInstanceOf( \WP_Error::class, Config::from_array( self::config_array( $issuer ), 'production' ) );
		$this->assertInstanceOf( \WP_Error::class, Config::from_array( self::config_array( $issuer ), 'staging' ) );
		$this->assertInstanceOf( Config::class, Config::from_array( self::config_array( $issuer ), 'local' ) );
	}
}
