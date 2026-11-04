# metatavu-wordpress-iac

WordPress must-use plugin that lets OpenTofu, running in a project's CI, read and change a site's whitelisted options, and activate and update its whitelisted plugins and themes, through [metatavu-wpengine-opentofu](https://github.com/Metatavu/metatavu-wpengine-opentofu), and nothing else. Every request is authenticated with a GitHub Actions OIDC token.

Released as a zip for the project's `bootstrap/` to install (see *Releases*), with the scripts `bootstrap/` runs over SSH: one installs the plugin, the other installs and removes plugins and themes (see *Plugins and themes*).

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
	'storage'   => 'wp-content/uploads/metatavu-iac',
);
```

`storage` is where staged update zips are kept, relative to `ABSPATH`. It defaults to `wp-content/uploads/metatavu-iac`. On WP Engine, PHP in a web request can't write to `_wpeprivate`, and every directory it can write is served over HTTP, so a staged zip is kept encrypted under a random name, with its key in the database (see *Plugins and themes*).

Without a valid `config.php`, every request gets `503`. `define( 'METATAVU_IAC_DISABLED', true );` in `wp-config.php` switches the API off.

## API

REST namespace `metatavu-iac/v1`, HTTPS only, every response `Cache-Control: no-store`. Every request carries `Authorization: Bearer <GitHub OIDC token>`. A token is accepted when its RS256 signature verifies against GitHub's keys, and its issuer, audience (the config's `audience`), `repository_id`, `repository_owner_id`, environment and lifetime (issued at most five minutes ago, a minute of clock skew) are right. Tokens from a `read` environment can only read; write tokens are accepted once.

| Method and path | Scope | Does |
|---|---|---|
| `GET /state` | read | The whitelisted options (a sensitive one only as the SHA-256 of its canonical JSON), plugins and themes with their versions, whether a plugin is active and a declared release not installed yet (`pending`, with the last failed attempt's `error`), the active theme, and the WordPress and PHP versions |
| `PUT /options/{name}` | write | Sets a whitelisted option to `{"value": <JSON>}` |
| `PUT /plugins/{slug}`, `PUT /themes/{slug}` | write | Takes over a whitelisted package that's installed, and sets whether a plugin is active. From wordpress.org: `{"source": "wordpress.org", "active": true}` (WP Engine keeps it up to date). A release: see below. A package that isn't installed gets `409`: `bootstrap/` installs it first |
| `PUT /active-theme` | write | Activates a whitelisted, installed theme: `{"slug": "<theme>"}` |
| `GET /changes?limit=100` | read | Changes to managed options made outside the API, newest first (at most 1000) |

A release is uploaded as multipart: the zip as `package`, and the fields `source=release`, `version`, `sha256` and, for a plugin, `active`. PHP parses multipart bodies only for POST, so it's sent as `POST` with `X-HTTP-Method-Override: PUT`. The plugin checks the zip's SHA-256, that it holds only the slug's directory, and that its header declares `version`. When that's the installed version, the answer is `200`. Otherwise the zip is staged as an update and the answer is `202` until WordPress has installed it (see *Plugins and themes*). A zip larger than the site's `upload_max_filesize` (50 MB on WP Engine) gets `413`. The plugin never manages itself (`metatavu-wordpress-iac`), and downloads nothing.

`siteurl`, `home`, `default_role`, `users_can_register`, `active_plugins`, `template`, `stylesheet`, `admin_email` and the user roles option are refused even when whitelisted.

Values are stored as WordPress stores options: a JSON object becomes a PHP array with string keys, so an empty object reads back as `[]`, and WordPress stores a scalar option as a string, so declare scalars as strings. A sensitive option's hash is the SHA-256 of its value as JSON with object keys sorted at every level and no escaped slashes or Unicode; the provider hashes a declared value after the same normalisation.

A change to a managed option made outside the API (wp-admin, WP-CLI, another plugin, cron) is logged with the WordPress user, where it came from (`cli`, `cron`, `rest`, `ajax`, `admin:<path>`, `web:<path>`), and the old and new values, or only that it changed for a sensitive option. The next plan shows the drift; `GET /changes` explains it.

## Plugins and themes

On WP Engine, PHP in a REST request can't create or delete `.php` files, so the API can't install, update or delete a plugin or theme by itself. The work is split:

- **First install and removal:** the project's `bootstrap/`, from a laptop over SSH, with `scripts/manage-package.sh` (below).
- **Activation:** the API. Activating and deactivating write no files.
- **Updates of releases:** the API stages the uploaded zip, and WordPress's automatic updater installs it on the next WP-Cron run:
  1. The plugin offers the staged version to WordPress as the package's update. It replaces any other offer for a managed release, such as a premium plugin's own updater, and lets the automatic updater install exactly that offer.
  2. Staging schedules `wp_maybe_auto_update` at once. A request to `wp-cron.php`, without `doing_wp_cron`, runs it.
  3. The offer's package is `metatavu-iac://<type>/<slug>/<version>`. WordPress resolves it to a decrypted copy of the staged zip in its temporary directory, after the copy's SHA-256 is checked once more. The staged zip is encrypted with libsodium's `secretstream` (XChaCha20-Poly1305), in 1 MB chunks, with a random key kept in the database: the storage directory is served over HTTP. The SSH container and PHP-FPM see the site at different paths, so the offer never holds a path.
  4. WordPress keeps a backup, checks the site for a fatal error with a loopback request, and restores the previous version if it finds one. The failure shows as `pending.error` in `GET /state`, and the update is tried again on the next run.
  5. Once the version is installed, the staged zip is deleted.
- **wordpress.org packages** aren't updated through the plugin: WP Engine keeps them up to date.

`scripts/manage-package.sh`, published with each release, installs a whitelisted plugin or theme that's missing, or removes one, with WP-CLI over WP Engine's SSH gateway:

- **wordpress.org:** installed from wordpress.org.
- **Releases:** downloaded on the laptop, checked against the pinned SHA-256 and the slug's directory, uploaded, and installed. A private GitHub release is downloaded with `GITHUB_TOKEN` or `gh auth token`.

An installed package is left as it is, whatever its version, and nothing is activated. `--remove` deactivates a plugin and deletes the package, but not the active theme. Declare a `terraform_data` per package next to the whitelist. It runs again only when the package itself changes: a new version reaches the site through the API.

```hcl
resource "terraform_data" "package" {
  for_each = { for p in local.packages : "${p.env}/${p.type}/${p.slug}" => p }

  triggers_replace = [each.value.install, each.value.type, each.value.slug, each.value.source]
  input            = each.value

  provisioner "local-exec" {
    command = "${path.root}/../scripts/manage-package.sh --ssh ${self.input.install}@${self.input.install}.ssh.wpengine.net --install ${self.input.install} --type ${self.input.type} --slug ${self.input.slug} --source ${self.input.source}${self.input.source == "release" ? " --url ${self.input.url} --sha256 ${self.input.sha256} --version ${self.input.version}" : ""}"
  }

  provisioner "local-exec" {
    when    = destroy
    command = "${path.root}/../scripts/manage-package.sh --ssh ${self.input.install}@${self.input.install}.ssh.wpengine.net --install ${self.input.install} --type ${self.input.type} --slug ${self.input.slug} --source ${self.input.source} --remove"
  }
}
```

To stop managing a package, remove it from `site/` first (a plugin is deactivated), then from `bootstrap/` (it's deleted).

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

The tests install packages with WP-CLI, as `bootstrap/` does, and run WP-Cron with requests to `wp-cron.php`. `tests/e2e/wp-env-shims.php` is mapped in as a must-use plugin only for them: wp-env checks WordPress out with git, which WordPress's automatic updater won't update, and the site's own URL isn't reachable inside the container, which its fatal-error check needs. `.wp-env.json` switches off core updates, so WP-Cron updates only the plugin under test, and WP-Cron's runs on page loads (`DISABLE_WP_CRON`), so it runs only when the tests request `wp-cron.php`. WordPress's own check for a fatal error after an update isn't tested here: it needs to write `.maintenance` into the WordPress directory, which wp-env's web server user can't everywhere.

`tests/e2e/issue-token.php` writes the test `config.php` and issues tokens, for trying the API by hand. CI runs the end-to-end tests against the release build: `scripts/build.sh`, then `cp tests/e2e/wp-env.build.json .wp-env.override.json` before starting wp-env, and `E2E_PLUGIN_DIR=build/plugin/metatavu-wordpress-iac tests/e2e/run.sh`.

## Installing

A project's `bootstrap/` installs the plugin on each install from a laptop, over WP Engine's SSH gateway with the person's own SSH key; CI never does. `scripts/install-mu-plugin.sh`, published with each release:

1. downloads the pinned release and checks its SHA-256 before anything is uploaded;
2. uploads the zip and `config.php` with rsync to a staging directory under `_wpeprivate` (kept between sessions, never served; the gateway has no SFTP, and `/tmp` isn't kept);
3. on the install, unpacks it, checks `config.php` with `php -l`, and swaps the plugin into `mu-plugins`: the directory, then the loader;
4. checks that WordPress loads the plugin, and if it doesn't, puts the previous version back.

Between the swap and that check, a few seconds, a broken release is live. The config comes in `IAC_CONFIG_PHP`, so nothing is written to disk on the laptop. Keep a copy of the script in the project's `scripts/`, from the release it installs, and declare a `terraform_data` per install, which runs it again whenever the version, the checksum or the config changes:

```hcl
locals {
  iac_plugin = { version = "0.3.0", sha256 = "<the zip's SHA-256 from the release>" }

  # config.php returns the trust settings and whitelist; JSON in a nowdoc keeps the PHP trivial.
  iac_config = {
    for env, install in wpengine_install.this : env => <<-PHP
      <?php
      return json_decode( <<<'JSON'
      ${jsonencode({
        install   = install.name
        audience  = "https://${install.name}.wpengine.com"
        trust     = { repository_id = data.github_repository.infra.repo_id, repository_owner_id = 987654, read = ["${env}-plan"], write = [env] }
        whitelist = { options = {}, plugins = [], themes = [] }
      })}
      JSON, true );
      PHP
  }
}

resource "terraform_data" "iac_plugin" {
  for_each = wpengine_install.this

  triggers_replace = [local.iac_plugin.version, local.iac_plugin.sha256, sha256(local.iac_config[each.key])]

  provisioner "local-exec" {
    command     = "${path.root}/../scripts/install-mu-plugin.sh --ssh ${each.value.name}@${each.value.name}.ssh.wpengine.net --install ${each.value.name} --version ${local.iac_plugin.version} --sha256 ${local.iac_plugin.sha256}"
    environment = { IAC_CONFIG_PHP = local.iac_config[each.key] }
  }
}
```

`--zip <file>` installs a local zip instead of the release, for testing.

## Releases

`scripts/build.sh` builds `dist/metatavu-wordpress-iac-<version>.zip` and its `.sha256`, and copies `install-mu-plugin.sh` and `manage-package.sh` with their `.sha256` files. The zip holds the loader and `metatavu-wordpress-iac/`, with `firebase/php-jwt` renamed into `Metatavu\WordPressIaC\Vendor` by PHP-Scoper (pinned and checksummed), a class-map autoloader, and no development files; the build fails if any of that doesn't hold. A `v*` tag on `main` whose version matches the plugin header publishes the zip and its checksum as a GitHub release. The zip has no `config.php`: the project's `bootstrap/` writes it.
