# metatavu-wordpress-iac

WordPress must-use plugin that lets OpenTofu, running in a project's CI, read and change a site's whitelisted options, plugins and themes through [metatavu-wpengine-opentofu](https://github.com/Metatavu/metatavu-wpengine-opentofu), and nothing else. Every request is authenticated with a GitHub Actions OIDC token.

Work in progress: options, plugins and themes are in place; the log of changes made outside the API and the release build follow.

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
| `GET /state` | read | The whitelisted options (a sensitive one only as the SHA-256 of its canonical JSON), plugins and themes with their versions and whether a plugin is active, the active theme, and the WordPress and PHP versions |
| `PUT /options/{name}` | write | Sets a whitelisted option to `{"value": <JSON>}` |
| `PUT /plugins/{slug}`, `PUT /themes/{slug}` | write | Installs or updates a whitelisted package, and sets whether a plugin is active. From wordpress.org: `{"source": "wordpress.org", "active": true}`, installed unless it is (WP Engine keeps it up to date). A release: see below |
| `DELETE /plugins/{slug}`, `DELETE /themes/{slug}` | write | Deactivates a plugin and deletes the package. The active theme and its parent can't be deleted (`409`) |
| `PUT /active-theme` | write | Activates a whitelisted, installed theme: `{"slug": "<theme>"}` |

A release is uploaded as multipart: the zip as `package`, and the fields `source=release`, `version`, `sha256` and, for a plugin, `active`. PHP parses multipart bodies only for POST, so it's sent as `POST` with `X-HTTP-Method-Override: PUT`. Before anything is unpacked, the plugin checks the zip's SHA-256, that it holds only the slug's directory, and that its header declares `version`. WordPress's upgraders install it, keeping the previous version in a temporary backup and restoring it if the install fails. A zip larger than the site's `upload_max_filesize` (50 MB on WP Engine) gets `413`. The plugin never manages itself (`metatavu-wordpress-iac`), and wordpress.org is the only place it downloads from.

`siteurl`, `home`, `default_role`, `users_can_register`, `active_plugins`, `template`, `stylesheet`, `admin_email` and the user roles option are refused even when whitelisted.

Values are stored as WordPress stores options: a JSON object becomes a PHP array with string keys, so an empty object reads back as `[]`, and WordPress stores a scalar option as a string, so declare scalars as strings. A sensitive option's hash is the SHA-256 of its value as JSON with object keys sorted at every level and no escaped slashes or Unicode; the provider hashes a declared value after the same normalisation.

Every authorized write is logged with its outcome (`ok` or the error code, because a failed write can leave a partial change) and the token's repository, run, commit, actor, environment and `jti`, and kept for 365 days. Activating and deactivating a plugin run its own hooks, as in wp-admin.

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
tests/e2e/run.sh          # WP_ENV_PORT when not 8888; needs curl, jq and zip, and network for wordpress.org
```

`tests/e2e/issue-token.php` writes the test `config.php` and issues tokens, for trying the API by hand.
