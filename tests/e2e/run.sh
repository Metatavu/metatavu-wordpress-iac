#!/usr/bin/env bash
#
# End-to-end tests against a running wp-env site, through the plugin's real
# API with tokens from the test issuer (plugin doc D13). Packages are
# installed with WP-CLI, as a project's bootstrap/ does (D18), and updates are
# installed by WordPress's automatic updater on WP-Cron (D19). Needs wp-env
# started, curl, jq and zip; wordpress.org needs network access.
#
# Usage: WP_ENV_PORT=8888 tests/e2e/run.sh
#
# E2E_PLUGIN_DIR is where the site loads the plugin from, when not the
# source: build/plugin/metatavu-wordpress-iac for the release build.

set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$root"
port=${WP_ENV_PORT:-8888}
site="http://localhost:$port"
api="$site/?rest_route=/metatavu-iac/v1"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
failures=0

php() {
	if type -P php >/dev/null; then
		command php "$@"
	else
		"$root/scripts/dev.sh" php "$@" | tr -d '\r'
	fi
}
token() { php tests/e2e/issue-token.php token "$1" "$site"; }
# wp-env's own lines are dropped; a command that prints nothing else still succeeds.
wp() { npx -y @wordpress/env@10 run cli wp "$@" 2>/dev/null | { grep -v -E '^(ℹ|✔)' || true; }; }

# expect <description> <expected HTTP status> <jq filter that must be true, or ""> -- <curl arguments>
expect() {
	local description=$1 status=$2 filter=$3
	shift 4
	local body code
	body=$(curl -sS -o "$work/body" -w '%{http_code}' "$@") || true
	code=$body
	body=$(cat "$work/body")
	if [[ $code != "$status" ]] || { [[ -n $filter ]] && ! jq -e "$filter" <<<"$body" >/dev/null 2>&1; }; then
		echo "FAIL $description: HTTP $code $body" >&2
		failures=$((failures + 1))
	else
		echo "ok   $description"
	fi
}

read_as() { curl -sS -H "Authorization: Bearer $(token develop-plan)" "$api/state"; }
put_json() { expect "$1" "$2" "$3" -- -X PUT -H "Authorization: Bearer $(token "${5:-develop}")" -H 'Content-Type: application/json' -d "$4" "$api/$6"; }

# A release zip of acme-release at a version: its activation hook sets the
# option acme_release_activated, and its deactivation hook deletes it.
# release <version> [directory] [stray file] [extra header line]
release() {
	local version=$1
	local name=acme-release-$version${3:+-with-$3}${4:+-extra}
	local dir=$work/$name
	rm -rf "$dir" "$work/$name.zip"
	mkdir -p "$dir/${2:-acme-release}"
	cat >"$dir/${2:-acme-release}/acme-release.php" <<PHP
<?php
/**
 * Plugin Name: Acme Release
 * Version: $version
 * ${4:-Description: A test plugin.}
 */
register_activation_hook( __FILE__, fn() => update_option( 'acme_release_activated', 'yes' ) );
register_deactivation_hook( __FILE__, fn() => delete_option( 'acme_release_activated' ) );
PHP
	[[ -n ${3:-} ]] && printf 'x' >"$dir/$3"
	(cd "$dir" && zip -qr "$work/$name.zip" .)
	echo "$work/$name.zip"
}

# A zip with extra top-level PHP files next to the plugin: <name> <file> <header>...
zip_with() {
	local name=$1 dir=$work/$1
	shift
	rm -rf "$dir"
	mkdir -p "$dir/acme-release"
	cp "$work/acme-release-2.0.0/acme-release/acme-release.php" "$dir/acme-release/"
	while (($#)); do
		printf '<?php\n/**\n * %s\n */\n' "$2" >"$dir/acme-release/$1"
		shift 2
	done
	(cd "$dir" && zip -qr "$work/$name.zip" .)
	echo "$work/$name.zip"
}

activated() { wp option get acme_release_activated 2>/dev/null | tr -d '[:space:]'; }

# Installs a zip with WP-CLI, as bootstrap/ does: the CLI container sees the
# site's files, not this machine's, so the zip goes through wp-content.
wp_install_zip() {
	wp eval "file_put_contents( WP_CONTENT_DIR . '/e2e-package.zip', base64_decode( '$(base64 <"$1" | tr -d '\n')' ) );" >/dev/null
	wp plugin install wp-content/e2e-package.zip >/dev/null
	wp eval "unlink( WP_CONTENT_DIR . '/e2e-package.zip' );" >/dev/null
}

# Runs WP-Cron as an outside request would, until the filter holds for acme-release's
# state or a minute has passed.
cron_until() {
	local i
	for i in {1..30}; do
		# Without doing_wp_cron, which only a lock WordPress set itself would match.
		curl -sS -o /dev/null "$site/wp-cron.php" || true
		if read_as | jq -e ".plugins[\"acme-release\"] | $1" >/dev/null 2>&1; then
			return 0
		fi
		sleep 2
	done
	return 1
}
staged() { wp eval 'echo count( glob( ABSPATH . "wp-content/uploads/metatavu-iac/*.bin" ) );' | tr -d '[:space:]'; }
check() { if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: got '$2', expected '$3'" >&2; failures=$((failures + 1)); fi; }

# put_release <description> <status> <filter> <zip> <version> [sha256] [active]
put_release() {
	local description=$1 status=$2 filter=$3 zip=$4 version=$5 sha256=${6:-} active=${7:-true}
	[[ -n $sha256 ]] || sha256=$(shasum -a 256 "$zip" | cut -d' ' -f1)
	expect "$description" "$status" "$filter" -- -X POST -H 'X-HTTP-Method-Override: PUT' -H "Authorization: Bearer $(token develop)" \
		-F source=release -F "version=$version" -F "sha256=$sha256" -F "active=$active" -F "package=@$zip;type=application/zip" \
		"$api/plugins/acme-release"
}

php tests/e2e/issue-token.php config "$site"
if [[ -n ${E2E_PLUGIN_DIR:-} ]]; then
	cp metatavu-wordpress-iac/config.php "$E2E_PLUGIN_DIR/config.php"
fi

# Start from a clean site: earlier runs may have left packages behind.
# Deactivated first: deleting leaves a plugin in the active list.
wp plugin deactivate acme-release classic-editor >/dev/null 2>&1 || true
wp plugin delete acme-release classic-editor >/dev/null 2>&1 || true
wp theme activate twentytwentyfive >/dev/null 2>&1 || true
wp theme delete twentytwentythree >/dev/null 2>&1 || true
wp option delete acme_release_activated metatavu_iac_release_plugin_acme-release metatavu_iac_release_plugin_classic-editor >/dev/null 2>&1 || true
wp eval 'array_map( "unlink", glob( ABSPATH . "wp-content/uploads/metatavu-iac/*.bin" ) ?: array() );' >/dev/null 2>&1 || true
# Only this run's log entries are counted.
# On a new site the table doesn't exist until the plugin's first write.
log_start=$(wp db query "SELECT COALESCE(MAX(id), 0) FROM wp_metatavu_iac_log" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)
log_start=${log_start:-0}

echo "== state"
expect "state lists the whitelisted plugins and themes" 200 '.plugins["acme-release"].installed == false and .themes.twentytwentyfive.installed and .active_theme == "twentytwentyfive"' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/state"

echo "== wordpress.org"
put_json "refuses a plugin that isn't installed" 409 '.code == "metatavu_iac_not_installed" and (.message | test("bootstrap/"))' '{"source":"wordpress.org","active":true}' develop plugins/classic-editor
wp plugin install classic-editor >/dev/null
put_json "a plan token can't write" 403 "" '{"source":"wordpress.org","active":true}' develop-plan plugins/classic-editor
put_json "takes over and activates an installed plugin" 200 '.installed and .active' '{"source":"wordpress.org","active":true}' develop plugins/classic-editor
auto_updates() { wp plugin auto-updates status classic-editor --field=status | tr -d '[:space:]'; }
put_json "switches WordPress's automatic updates on" 200 '.auto_update' '{"source":"wordpress.org","active":true,"auto_update":true}' develop plugins/classic-editor
check "WordPress updates it automatically" "$(auto_updates)" enabled
wp plugin auto-updates disable classic-editor >/dev/null
expect "automatic updates switched off in wp-admin show in the state" 200 '.plugins["classic-editor"].auto_update == false' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
put_json "leaves automatic updates as they are without auto_update" 200 '.auto_update == false' '{"source":"wordpress.org","active":true}' develop plugins/classic-editor
for invalid in '"maybe"' null '""' 0 '"true"'; do
	put_json "refuses auto_update $invalid, which isn't a JSON boolean" 400 '.code == "metatavu_iac_invalid"' "{\"source\":\"wordpress.org\",\"auto_update\":$invalid}" develop plugins/classic-editor
done
check "the refused values changed nothing" "$(auto_updates)" disabled
put_json "switches them off" 200 '(.auto_update | not)' '{"source":"wordpress.org","active":true,"auto_update":false}' develop plugins/classic-editor
check "WordPress doesn't update it automatically" "$(auto_updates)" disabled
put_json "deactivates" 200 '.installed and (.active | not)' '{"source":"wordpress.org","active":false}' develop plugins/classic-editor
put_json "refuses a plugin that isn't whitelisted" 403 '.code == "metatavu_iac_not_whitelisted"' '{"source":"wordpress.org"}' develop plugins/akismet
put_json "refuses its own slug" 403 '.code == "metatavu_iac_own_slug"' '{"source":"wordpress.org"}' develop plugins/metatavu-wordpress-iac
expect "has no DELETE" 404 "" -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/plugins/classic-editor"

echo "== releases"
v1=$(release 1.0.0)
put_release "refuses a release that isn't installed" 409 '.code == "metatavu_iac_not_installed"' "$v1" 1.0.0
wp_install_zip "$v1"
put_release "refuses a wrong checksum" 422 '.code == "metatavu_iac_checksum_mismatch"' "$v1" 1.0.0 "$(printf '0%.0s' {1..64})"
put_release "refuses a version the zip doesn't hold" 422 '.code == "metatavu_iac_version_mismatch"' "$v1" 1.0.1
put_release "refuses a zip with another directory" 422 '.code == "metatavu_iac_invalid_zip"' "$(release 1.0.0 acme-release stray.txt)" 1.0.0
release 2.0.0 >/dev/null
put_release "takes the version from the plugin header, not another PHP file" 422 '.code == "metatavu_iac_version_mismatch"' "$(zip_with helper helper.php 'Version: 1.0.0')" 1.0.0
put_release "refuses a zip with two plugin headers" 422 '.code == "metatavu_iac_invalid_zip"' "$(zip_with two other.php 'Plugin Name: Other')" 2.0.0
put_release "refuses an invalid active before staging" 400 '.code == "metatavu_iac_invalid"' "$(release 1.1.0)" 1.1.0 "" maybe
check "nothing was staged by the refused uploads" "$(staged)" 0
put_release "takes over the installed version and activates it" 200 '.installed and .active and .version == "1.0.0" and (has("pending") | not)' "$v1" 1.0.0
check "activation ran the plugin's activation hook" "$(activated)" yes

put_release "stages an update" 202 '.version == "1.0.0" and .pending.version == "1.1.0"' "$(release 1.1.0)" 1.1.0
check "the update is staged" "$(staged)" 1
if cron_until '.version == "1.1.0" and (has("pending") | not)'; then echo "ok   WordPress's automatic updater installed it on WP-Cron"; else echo "FAIL WordPress's automatic updater installed it on WP-Cron: $(read_as | jq -c '.plugins["acme-release"]')" >&2; failures=$((failures + 1)); fi
expect "the updated plugin is still active" 200 '.plugins["acme-release"].active' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
check "the installed update's zip is deleted" "$(staged)" 0

# WP-Cron runs only when this script requests it (DISABLE_WP_CRON), so the zip is changed before the updater sees it.
put_release "stages another update" 202 '.pending.version == "1.2.0"' "$(release 1.2.0)" 1.2.0
wp eval 'foreach ( glob( ABSPATH . "wp-content/uploads/metatavu-iac/*.bin" ) as $zip ) { file_put_contents( $zip, "changed", FILE_APPEND ); }' >/dev/null
if cron_until '.pending.error // "" | test("declared")'; then echo "ok   a staged zip changed on disk is refused, and the failure is in the state"; else echo "FAIL a staged zip changed on disk is refused, and the failure is in the state: $(read_as | jq -c '.plugins["acme-release"]')" >&2; failures=$((failures + 1)); fi
expect "the previous version is still installed and active" 200 '.plugins["acme-release"].version == "1.1.0" and .plugins["acme-release"].active' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
put_release "declaring the installed version again drops the update" 200 '.version == "1.1.0" and (has("pending") | not)' "$(release 1.1.0)" 1.1.0
check "the dropped update's zip is deleted" "$(staged)" 0

expect "refuses auto_update for a release" 400 '.message | test("auto_update")' -- -X POST -H 'X-HTTP-Method-Override: PUT' -H "Authorization: Bearer $(token develop)" \
	-F source=release -F version=1.1.0 -F "sha256=$(shasum -a 256 "$(release 1.1.0)" | cut -d' ' -f1)" -F auto_update=true -F "package=@$(release 1.1.0);type=application/zip" "$api/plugins/acme-release"

expect "a multipart PUT without the override is explained" 400 '.message | test("X-HTTP-Method-Override")' -- -X PUT -H "Authorization: Bearer $(token develop)" -F source=release -F "package=@$v1;type=application/zip" "$api/plugins/acme-release"

echo "== themes"
put_json "refuses a theme that isn't installed" 409 '.code == "metatavu_iac_not_installed"' '{"source":"wordpress.org"}' develop themes/twentytwentythree
wp theme install twentytwentythree >/dev/null
put_json "takes over an installed theme, updated automatically" 200 '.installed and .auto_update' '{"source":"wordpress.org","auto_update":true}' develop themes/twentytwentythree
check "WordPress updates the theme automatically" "$(wp theme auto-updates status twentytwentythree --field=status | tr -d '[:space:]')" enabled
put_json "activates it" 200 '.active_theme == "twentytwentythree"' '{"slug":"twentytwentythree"}' develop active-theme
put_json "switches back" 200 '.active_theme == "twentytwentyfive"' '{"slug":"twentytwentyfive"}' develop active-theme

echo "== changes made outside the API"
changes() { curl -sS -H "Authorization: Bearer $(token develop-plan)" "$api/changes&limit=${1:-100}"; }
expect "changes need a token" 401 "" -- "$api/changes"
before=$(changes | jq '[.changes[] | select(.target == "option:blogdescription")] | length')
put_json "an API write" 200 '.value == "Set by the API"' '{"value":"Set by the API"}' develop options/blogdescription
check "an API write isn't logged as a change" "$(changes | jq '[.changes[] | select(.target == "option:blogdescription")] | length')" "$before"
wp option update blogdescription "Changed by hand" >/dev/null
expect "a change made with WP-CLI is logged with its source and values" 200 '.changes[0].target == "option:blogdescription" and .changes[0].detail.source == "cli" and .changes[0].detail.old == "Set by the API" and .changes[0].detail.new == "Changed by hand"' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/changes&limit=1"
# A fresh value each run: an unchanged value is a no-op that WordPress doesn't report.
wp option update acme_api "{\"secret\":\"changed-$RANDOM$RANDOM\"}" --format=json >/dev/null
expect "a change to a sensitive option is logged without its values" 200 '.changes[0].target == "option:acme_api" and .changes[0].detail.changed and (.changes[0].detail | has("new") | not) and (.changes[0].detail | has("old") | not)' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/changes&limit=1"
wp option update BlogDescription "Changed in another case" >/dev/null
expect "a change spelled in another case is logged under the whitelisted name" 200 '.changes[0].target == "option:blogdescription" and .changes[0].detail.new == "Changed in another case"' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/changes&limit=1"
wp option delete blogdescription >/dev/null
expect "a deletion is logged with the old value" 200 '.changes[0].target == "option:blogdescription" and .changes[0].detail.action == "deleted" and .changes[0].detail.old == "Changed in another case"' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/changes&limit=1"
wp option add blogdescription "" >/dev/null
wp option update blogname "Not managed" >/dev/null
check "a change to an option that isn't managed isn't logged" "$(changes | jq '[.changes[] | select(.target == "option:blogname")] | length')" 0

echo "== log"
writes=$(wp db query "SELECT COUNT(*) FROM wp_metatavu_iac_log WHERE id > $log_start AND kind = 'write' AND target LIKE 'plugin:%'" --skip-column-names | tr -d '[:space:]')
if [[ ${writes:-0} -ge 6 ]]; then echo "ok   plugin writes are logged ($writes)"; else echo "FAIL plugin writes are logged ($writes)" >&2; failures=$((failures + 1)); fi
failed=$(wp db query "SELECT COUNT(*) FROM wp_metatavu_iac_log WHERE id > $log_start AND target = 'plugin:acme-release' AND JSON_UNQUOTE(JSON_EXTRACT(detail, '\$.outcome')) = 'metatavu_iac_not_installed'" --skip-column-names | tr -d '[:space:]')
check "a failed write is logged with its outcome" "${failed:-0}" 1

if ((failures > 0)); then
	echo "$failures failed" >&2
	exit 1
fi
echo "all passed"
