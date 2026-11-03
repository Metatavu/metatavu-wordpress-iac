<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Brain\Monkey\Functions;
use Metatavu\WordPressIaC\Canonical_Json;
use Metatavu\WordPressIaC\Option_Store;

final class OptionStoreTest extends Test_Case {

	private array $options;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
		$this->options   = array(
			'blogdescription' => 'Just another site',
			'acme_api'        => array(
				'secret' => 's3cr3t',
				'url'    => 'https://api.example.com/',
			),
			'siteurl'         => 'https://example.com',
		);
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	public function test_state_has_only_managed_options_and_hashes_sensitive_ones(): void {
		$state = ( new Option_Store( self::config() ) )->state();
		$this->assertSame( array( 'acme_api', 'blogdescription' ), array_keys( $state ) );
		$this->assertSame( 'Just another site', $state['blogdescription']['value'] );
		$this->assertArrayNotHasKey( 'value', $state['acme_api'] );
		$this->assertSame( hash( 'sha256', '{"secret":"s3cr3t","url":"https://api.example.com/"}' ), $state['acme_api']['sha256'] );
	}

	public function test_a_missing_option_does_not_exist(): void {
		unset( $this->options['blogdescription'] );
		$this->assertSame(
			array(
				'exists'    => false,
				'sensitive' => false,
			),
			( new Option_Store( self::config() ) )->read( 'blogdescription' )
		);
	}

	public function test_writes_only_managed_options(): void {
		$store = new Option_Store( self::config() );
		$this->assertSame( 'New', $store->write( 'blogdescription', 'New' )['value'] );
		foreach ( array( 'siteurl', 'wp_user_roles', 'not_whitelisted' ) as $name ) {
			$result = $store->write( $name, 'x' );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( 'https://example.com', $this->options['siteurl'] );
	}

	public function test_the_prefixed_roles_option_is_forbidden(): void {
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'site_' );
		$this->assertFalse( ( new Option_Store( self::config( array( 'whitelist' => array( 'options' => array( 'site_user_roles' => array() ) ) ) ) ) )->is_managed( 'site_user_roles' ) );
	}

	public function test_canonical_json_sorts_keys_at_every_level(): void {
		$this->assertSame(
			Canonical_Json::encode(
				array(
					'b' => array(
						'y' => 1,
						'x' => 2,
					),
					'a' => array( 3, 1 ),
				)
			),
			Canonical_Json::encode(
				array(
					'a' => array( 3, 1 ),
					'b' => array(
						'x' => 2,
						'y' => 1,
					),
				)
			)
		);
		$this->assertSame(
			'{"a":[3,1],"b":{"x":2,"y":1},"u":"ä/"}',
			Canonical_Json::encode(
				array(
					'b' => array(
						'y' => 1,
						'x' => 2,
					),
					'u' => 'ä/',
					'a' => array( 3, 1 ),
				)
			)
		);
	}
}
