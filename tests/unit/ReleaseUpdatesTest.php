<?php
/**
 * @package Metatavu\WordPressIaC
 */

namespace Metatavu\WordPressIaC\Tests;

use Brain\Monkey\Functions;
use Metatavu\WordPressIaC\Release_Updates;

final class ReleaseUpdatesTest extends Test_Case {

	const STORAGE = 'phpunit_storage';
	const FILE    = 'wordpress-seo/wp-seo.php';

	private array $options = array();
	private string $installed;
	private int $scheduled;
	private bool $fail_storage;

	protected function setUp(): void {
		parent::setUp();
		$this->options      = array();
		$this->installed    = '1.0.0';
		$this->scheduled    = 0;
		$this->fail_storage = false;
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_plugins' )->alias( fn() => array( self::FILE => array( 'Version' => $this->installed ) ) );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'trailingslashit' )->alias( fn( $path ) => rtrim( $path, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->alias( fn( $dir ) => ! $this->fail_storage && ( is_dir( $dir ) || mkdir( $dir, 0777, true ) ) );
		Functions\when( 'wp_delete_file' )->alias( fn( $file ) => is_file( $file ) && unlink( $file ) );
		Functions\when( 'wp_tempnam' )->alias( fn() => tempnam( sys_get_temp_dir(), 'iac' ) );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () {
				++$this->scheduled;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$files = glob( ABSPATH . self::STORAGE . '/*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			unlink( $file );
		}
		if ( is_dir( ABSPATH . self::STORAGE ) ) {
			rmdir( ABSPATH . self::STORAGE );
		}
		parent::tearDown();
	}

	/**
	 * The staged files.
	 *
	 * @return string[]
	 */
	private function staged(): array {
		$files = glob( ABSPATH . self::STORAGE . '/*' );
		return is_array( $files ) ? $files : array();
	}

	private function releases(): Release_Updates {
		return new Release_Updates( self::config( array( 'storage' => self::STORAGE ) ) );
	}

	/**
	 * Stages a zip with the given content as wordpress-seo's version 2.0.0.
	 */
	private function stage( string $content = 'zip', string $version = '2.0.0' ): Release_Updates {
		$upload = tempnam( sys_get_temp_dir(), 'upload' );
		file_put_contents( $upload, $content );
		$releases = $this->releases();
		$result   = $releases->declare_release( 'plugin', 'wordpress-seo', $version, hash( 'sha256', $content ), $upload, $this->installed );
		if ( true !== $result ) {
			unlink( $upload );
			throw new \RuntimeException( $result->get_error_message() );
		}
		return $releases;
	}

	public function test_stages_a_version_that_is_not_installed_and_runs_the_updater(): void {
		$this->stage();
		$this->assertCount( 1, $this->staged() );
		$this->assertSame( 1, $this->scheduled );
		$this->assertSame(
			array(
				'version' => '2.0.0',
				'error'   => null,
			),
			$this->releases()->pending( 'plugin', 'wordpress-seo', '1.0.0' )
		);
	}

	public function test_a_staged_zip_is_encrypted_under_a_random_name(): void {
		$this->stage( 'the release' );
		$staged = $this->staged();
		$this->assertCount( 1, $staged );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\.bin$/', basename( $staged[0] ) );
		$this->assertStringNotContainsString( 'the release', file_get_contents( $staged[0] ) );
	}

	public function test_a_large_zip_is_encrypted_and_decrypted_in_chunks(): void {
		$content = random_bytes( 2 * Release_Updates::CHUNK + 123 );
		$copy    = $this->stage( $content )->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/2.0.0' );
		$this->assertIsString( $copy );
		$this->assertSame( hash( 'sha256', $content ), hash_file( 'sha256', $copy ) );
		unlink( $copy );
	}

	public function test_a_staged_zip_cut_short_is_refused(): void {
		$releases = $this->stage( random_bytes( Release_Updates::CHUNK + 10 ) );
		$staged   = $this->staged()[0];
		file_put_contents( $staged, substr( file_get_contents( $staged ), 0, -40 ) );
		$this->assertSame( 'metatavu_iac_checksum_mismatch', $releases->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/2.0.0' )->get_error_code() );
	}

	public function test_a_new_version_replaces_the_staged_zip(): void {
		$this->stage();
		$this->stage( 'newer', '3.0.0' );
		$this->assertCount( 1, $this->staged() );
		$this->assertSame( 'newer', file_get_contents( $this->releases()->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/3.0.0' ) ) );
	}

	public function test_a_failed_staging_keeps_the_previous_declaration(): void {
		$this->stage();
		$this->fail_storage = true;
		try {
			$this->stage( 'newer', '3.0.0' );
			$this->fail( 'Staging should fail.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be stored', $e->getMessage() );
		}
		$this->assertSame( '2.0.0', $this->releases()->pending( 'plugin', 'wordpress-seo', '1.0.0' )['version'] );
		$this->assertCount( 1, $this->staged() );
	}

	public function test_still_pending_while_the_updater_may_restore_the_previous_version(): void {
		$releases                           = $this->stage();
		$this->options['auto_updater.lock'] = time();
		$this->assertSame( '2.0.0', $releases->pending( 'plugin', 'wordpress-seo', '2.0.0' )['version'] );
		unset( $this->options['auto_updater.lock'] );
		$this->assertNull( $releases->pending( 'plugin', 'wordpress-seo', '2.0.0' ) );
	}

	public function test_tidying_deletes_the_zip_of_a_version_installed_otherwise(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$releases->tidy();
		$this->assertCount( 0, $this->staged() );
	}

	public function test_declaring_the_installed_version_stages_nothing(): void {
		$this->installed = '2.0.0';
		$this->stage();
		$this->assertCount( 0, $this->staged() );
		$this->assertSame( 0, $this->scheduled );
		$this->assertNull( $this->releases()->pending( 'plugin', 'wordpress-seo', '2.0.0' ) );
	}

	public function test_offers_the_staged_version_instead_of_any_other_offer(): void {
		$releases = $this->stage();
		$cached   = (object) array(
			'last_checked' => 1,
			'response'     => array(
				self::FILE => (object) array(
					'new_version' => '3.0.0',
					'package'     => 'https://example.com/wordpress-seo.zip',
				),
			),
		);
		$offer    = $releases->offer( 'plugin', $cached )->response[ self::FILE ];
		$this->assertSame( '2.0.0', $offer->new_version );
		$this->assertSame( 'metatavu-iac://plugin/wordpress-seo/2.0.0', $offer->package );
		$this->assertSame( self::FILE, $offer->plugin );
		$this->assertTrue( $offer->autoupdate );
	}

	public function test_offers_nothing_once_the_version_is_installed(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$cached          = (object) array( 'response' => array( self::FILE => (object) array( 'package' => 'https://example.com/wordpress-seo.zip' ) ) );
		$this->assertSame( array(), $releases->offer( 'plugin', $cached )->response );
	}

	public function test_an_offer_without_a_cache_leaves_the_update_check_due(): void {
		$value = $this->stage()->offer( 'plugin', false );
		$this->assertArrayHasKey( self::FILE, $value->response );
		$this->assertObjectNotHasProperty( 'last_checked', $value );
	}

	public function test_leaves_offers_alone_without_managed_releases(): void {
		$cached = (object) array( 'response' => array( 'akismet/akismet.php' => (object) array() ) );
		$this->assertSame( $cached, $this->releases()->offer( 'plugin', $cached ) );
	}

	public function test_updates_automatically_only_its_own_offers_of_managed_releases(): void {
		$releases = $this->stage();
		$this->assertTrue(
			$releases->auto_update(
				'plugin',
				false,
				(object) array(
					'plugin'  => self::FILE,
					'package' => 'metatavu-iac://plugin/wordpress-seo/2.0.0',
				)
			)
		);
		$this->assertFalse(
			$releases->auto_update(
				'plugin',
				true,
				(object) array(
					'plugin'  => self::FILE,
					'package' => 'https://example.com/wordpress-seo.zip',
				)
			)
		);
		$this->assertTrue( $releases->auto_update( 'plugin', true, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertNull( $releases->auto_update( 'plugin', null, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
	}

	public function test_resolves_the_package_to_a_copy_of_the_checked_zip(): void {
		$copy = $this->stage( 'the release' )->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/2.0.0' );
		$this->assertIsString( $copy );
		$this->assertSame( 'the release', file_get_contents( $copy ) );
		unlink( $copy );
		$this->assertCount( 1, $this->staged() );
	}

	public function test_refuses_a_staged_zip_changed_on_disk(): void {
		$releases = $this->stage( 'the release' );
		file_put_contents( $this->staged()[0], 'tampered', FILE_APPEND );
		$result = $releases->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/2.0.0' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'metatavu_iac_checksum_mismatch', $result->get_error_code() );
		// WordPress reports no error of its own for a refused download.
		$releases->updated_automatically(
			array(
				'plugin' => array(
					(object) array(
						'item'   => (object) array( 'plugin' => self::FILE ),
						'result' => null,
					),
				),
			)
		);
		$this->assertStringContainsString( "isn't the declared one", $releases->pending( 'plugin', 'wordpress-seo', '1.0.0' )['error'] );
	}

	public function test_resolves_only_its_own_staged_packages(): void {
		$releases = $this->stage();
		$this->assertFalse( $releases->resolve_package( false, 'https://example.com/wordpress-seo.zip' ) );
		$this->assertSame( 'metatavu_iac_not_staged', $releases->resolve_package( false, 'metatavu-iac://plugin/wordpress-seo/9.0.0' )->get_error_code() );
		$this->assertSame( 'metatavu_iac_not_staged', $releases->resolve_package( false, 'metatavu-iac://plugin/akismet/2.0.0' )->get_error_code() );
	}

	public function test_deletes_the_staged_zip_once_installed(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$releases->updated_automatically(
			array(
				'plugin' => array(
					(object) array(
						'item'   => (object) array( 'plugin' => self::FILE ),
						'result' => true,
					),
				),
			)
		);
		$this->assertCount( 0, $this->staged() );
		$this->assertNull( $this->releases()->pending( 'plugin', 'wordpress-seo', '2.0.0' ) );
	}

	public function test_an_update_from_wp_admin_is_settled_when_its_upgrader_completes(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$releases->upgraded( (object) array( 'skin' => new \stdClass() ), array( 'plugin' => self::FILE ) );
		$this->assertCount( 0, $this->staged() );
	}

	public function test_an_update_from_wp_admins_ajax_is_settled_too(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$releases->upgraded( (object) array( 'skin' => new \WP_Ajax_Upgrader_Skin() ), array( 'plugin' => self::FILE ) );
		$this->assertCount( 0, $this->staged() );
	}

	public function test_an_automatic_update_waits_for_the_updaters_fatal_error_check(): void {
		$releases        = $this->stage();
		$this->installed = '2.0.0';
		$releases->upgraded( (object) array( 'skin' => new \Automatic_Upgrader_Skin() ), array( 'plugin' => self::FILE ) );
		$this->assertCount( 1, $this->staged() );
	}

	public function test_records_a_failed_attempt_and_keeps_the_zip(): void {
		$releases = $this->stage();
		$releases->updated_automatically(
			array(
				'plugin' => array(
					(object) array(
						'item'   => (object) array( 'plugin' => self::FILE ),
						'result' => new \WP_Error( 'failed', 'Could not copy file.' ),
					),
				),
			)
		);
		$this->assertSame( 'Could not copy file.', $this->releases()->pending( 'plugin', 'wordpress-seo', '1.0.0' )['error'] );
		$this->assertCount( 1, $this->staged() );
	}

	public function test_forgetting_a_release_deletes_its_zip(): void {
		$this->stage()->forget( 'plugin', 'wordpress-seo' );
		$this->assertCount( 0, $this->staged() );
		$this->assertNull( $this->releases()->pending( 'plugin', 'wordpress-seo', '1.0.0' ) );
	}
}
