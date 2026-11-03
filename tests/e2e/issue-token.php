<?php
/**
 * A test token issuer for the end-to-end tests (plugin doc D13).
 *
 *   php tests/e2e/issue-token.php config <audience>        writes the plugin's config.php for wp-env
 *   php tests/e2e/issue-token.php token <environment> [aud]  prints a signed token
 *
 * The key is created on first use in tests/e2e/.issuer-key.pem, which is not committed.
 *
 * @package Metatavu\WordPressIaC
 */

require dirname( __DIR__, 2 ) . '/metatavu-wordpress-iac/vendor/autoload.php';

use Firebase\JWT\JWT;

const ISSUER = 'https://issuer.test';

$key_file = __DIR__ . '/.issuer-key.pem';
if ( ! is_file( $key_file ) ) {
	openssl_pkey_export(
		openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		),
		$pem
	);
	file_put_contents( $key_file, $pem );
}
$pem = file_get_contents( $key_file );
$b64 = fn( $bytes ) => rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );

switch ( $argv[1] ?? '' ) {
	case 'config':
		$rsa    = openssl_pkey_get_details( openssl_pkey_get_private( $pem ) )['rsa'];
		$config = array(
			'install'     => 'local',
			'audience'    => $argv[2],
			'trust'       => array(
				'repository_id'       => 123,
				'repository_owner_id' => 456,
				'read'                => array( 'develop-plan' ),
				'write'               => array( 'develop' ),
			),
			'whitelist'   => array(
				'options' => array(
					'blogdescription' => array(),
					'acme_api'        => array( 'sensitive' => true ),
					'siteurl'         => array(),
				),
				'plugins' => array( 'classic-editor', 'acme-release' ),
				'themes'  => array( 'twentytwentyfive', 'twentytwentythree' ),
			),
			'test_issuer' => array(
				'issuer' => ISSUER,
				'jwks'   => array(
					'keys' => array(
						array(
							'kty' => 'RSA',
							'kid' => 'test',
							'use' => 'sig',
							'n'   => $b64( $rsa['n'] ),
							'e'   => $b64( $rsa['e'] ),
						),
					),
				),
			),
		);
		file_put_contents( dirname( __DIR__, 2 ) . '/metatavu-wordpress-iac/config.php', "<?php\nreturn " . var_export( $config, true ) . ";\n" );
		break;
	case 'token':
		$now = time();
		echo JWT::encode(
			array(
				'iss'                 => ISSUER,
				'aud'                 => $argv[3] ?? 'http://localhost:8894',
				'repository'          => 'acme/acme-infra',
				'repository_id'       => '123',
				'repository_owner_id' => '456',
				'environment'         => $argv[2],
				'actor'               => 'someone',
				'run_id'              => '1',
				'sha'                 => 'abc',
				'jti'                 => bin2hex( random_bytes( 8 ) ),
				'iat'                 => $now,
				'nbf'                 => $now,
				'exp'                 => $now + 300,
			),
			$pem,
			'RS256',
			'test'
		);
		break;
	default:
		fwrite( STDERR, "usage: issue-token.php config <audience> | token <environment> [audience]\n" );
		exit( 1 );
}
