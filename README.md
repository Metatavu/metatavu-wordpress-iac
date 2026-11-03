# metatavu-wordpress-iac

WordPress must-use plugin that lets OpenTofu, running in a project's CI, read and change a site's whitelisted options, plugins and themes through [metatavu-wpengine-opentofu](https://github.com/Metatavu/metatavu-wpengine-opentofu), and nothing else. Every request is authenticated with a GitHub Actions OIDC token.

Work in progress: options are in place; plugins, themes, the log of changes made outside the API, and the release build follow.

## Layout

```
wp-content/mu-plugins/
├── metatavu-wordpress-iac.php        the loader
└── metatavu-wordpress-iac/
    ├── plugin.php
    ├── config.php                     written only by the project's bootstrap/
    ├── src/
    └── vendor/
```

`config.php` returns the install's trust settings and whitelist:

```php
<?php
return array(
	'install'   => 'acmedev',
	'audience'  => 'https://acmedev.wpengine.com',
	'trust'     => array(
		'repository_id'       => 123456789,
		'repository_owner_id' => 987654,
		'read'                => array( 'develop-plan' ),
		'write'               => array( 'develop' ),
	),
	'whitelist' => array(
		'options' => array(
			'blogdescription' => array(),
			'acme_api'        => array( 'sensitive' => true ),
		),
		'plugins' => array( 'wordpress-seo' ),
		'themes'  => array( 'acme' ),
	),
);
```

Without a valid `config.php`, every request gets `503`. `define( 'METATAVU_IAC_DISABLED', true );` in `wp-config.php` switches the API off.

## API

REST namespace `metatavu-iac/v1`, HTTPS only, every response `Cache-Control: no-store`. Every request carries `Authorization: Bearer <GitHub OIDC token>`. A token is accepted when its RS256 signature verifies against GitHub's keys, and its issuer, audience (the config's `audience`), `repository_id`, `repository_owner_id`, environment and lifetime (issued at most five minutes ago, a minute of clock skew) are right. Tokens from a `read` environment can only read; write tokens are accepted once.

| Method and path | Scope | Does |
|---|---|---|
| `GET /state` | read | The whitelisted options (a sensitive one only as the SHA-256 of its canonical JSON), and the WordPress and PHP versions |
| `PUT /options/{name}` | write | Sets a whitelisted option to `{"value": <JSON>}` |

`siteurl`, `home`, `default_role`, `users_can_register`, `active_plugins`, `template`, `stylesheet`, `admin_email` and the user roles option are refused even when whitelisted.

Values are stored as WordPress stores options: a JSON object becomes a PHP array with string keys, so an empty object reads back as `[]`, and WordPress stores a scalar option as a string, so declare scalars as strings. A sensitive option's hash is the SHA-256 of its value as JSON with object keys sorted at every level and no escaped slashes or Unicode; the provider hashes a declared value after the same normalisation.

Every write is logged with the token's repository, run, commit, actor, environment and `jti`, and kept for 365 days.

## Development

Nothing PHP is needed locally: `scripts/dev.sh` runs the tools in a PHP 8.4 image (`dev/Dockerfile`).

```sh
scripts/dev.sh composer install
scripts/dev.sh composer lint    # phpcs: WordPress coding standards, PHPCompatibility 8.4+
scripts/dev.sh composer test    # PHPUnit with Brain Monkey
```

End to end, in a local WordPress ([wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)) with a test token issuer, which the plugin accepts only when the environment type is `local`:

```sh
npx @wordpress/env start
scripts/dev.sh php tests/e2e/issue-token.php config http://localhost:8888
curl -H "Authorization: Bearer $(scripts/dev.sh php tests/e2e/issue-token.php token develop-plan http://localhost:8888)" \
  'http://localhost:8888/?rest_route=/metatavu-iac/v1/state'
```
