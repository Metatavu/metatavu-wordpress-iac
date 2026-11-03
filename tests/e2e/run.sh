#!/usr/bin/env bash
#
# End-to-end tests against a running wp-env site, through the plugin's real
# API with tokens from the test issuer (plugin doc D13). Needs wp-env started,
# curl, jq and zip; installing from wordpress.org needs network access.
#
# Usage: WP_ENV_PORT=8888 tests/e2e/run.sh

set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
port=${WP_ENV_PORT:-8888}
site="http://localhost:$port"
api="$site/?rest_route=/metatavu-iac/v1"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
failures=0

php() { "$root/scripts/dev.sh" php "$@" | tr -d '\r'; }
token() { php tests/e2e/issue-token.php token "$1" "$site"; }
wp() { npx -y @wordpress/env@10 run cli wp "$@" 2>/dev/null | grep -v -E '^(ℹ|✔)'; }

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

# Start from a clean site: earlier runs may have left packages behind.
wp plugin delete acme-release classic-editor >/dev/null 2>&1 || true
wp theme activate twentytwentyfive >/dev/null 2>&1 || true
wp theme delete twentytwentythree >/dev/null 2>&1 || true
wp option delete acme_release_activated >/dev/null 2>&1 || true
# Only this run's log entries are counted.
log_start=$(wp db query "SELECT COALESCE(MAX(id), 0) FROM wp_metatavu_iac_log" --skip-column-names 2>/dev/null | tr -d '[:space:]')
log_start=${log_start:-0}

echo "== state"
expect "state lists the whitelisted plugins and themes" 200 '.plugins["acme-release"].installed == false and .themes.twentytwentyfive.installed and .active_theme == "twentytwentyfive"' -- \
	-H "Authorization: Bearer $(token develop-plan)" "$api/state"

echo "== wordpress.org"
put_json "a plan token can't install" 403 "" '{"source":"wordpress.org","active":true}' develop-plan plugins/classic-editor
put_json "installs and activates from wordpress.org" 200 '.installed and .active' '{"source":"wordpress.org","active":true}' develop plugins/classic-editor
put_json "deactivates" 200 '.installed and (.active | not)' '{"source":"wordpress.org","active":false}' develop plugins/classic-editor
put_json "refuses a plugin that isn't whitelisted" 403 '.code == "metatavu_iac_not_whitelisted"' '{"source":"wordpress.org"}' develop plugins/akismet
put_json "refuses its own slug" 403 '.code == "metatavu_iac_own_slug"' '{"source":"wordpress.org"}' develop plugins/metatavu-wordpress-iac

echo "== releases"
v1=$(release 1.0.0)
put_release "refuses a wrong checksum" 422 '.code == "metatavu_iac_checksum_mismatch"' "$v1" 1.0.0 "$(printf '0%.0s' {1..64})"
put_release "refuses a version the zip doesn't hold" 422 '.code == "metatavu_iac_version_mismatch"' "$v1" 1.0.1
put_release "refuses a zip with another directory" 422 '.code == "metatavu_iac_invalid_zip"' "$(release 1.0.0 acme-release stray.txt)" 1.0.0
release 2.0.0 >/dev/null
put_release "takes the version from the plugin header, not another PHP file" 422 '.code == "metatavu_iac_version_mismatch"' "$(zip_with helper helper.php 'Version: 1.0.0')" 1.0.0
put_release "refuses a zip with two plugin headers" 422 '.code == "metatavu_iac_invalid_zip"' "$(zip_with two other.php 'Plugin Name: Other')" 2.0.0
put_release "refuses an invalid active before installing" 400 '.code == "metatavu_iac_invalid"' "$v1" 1.0.0 "" maybe
expect "nothing was installed by the refused uploads" 200 '.plugins["acme-release"].installed == false' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
put_release "installs and activates a release" 200 '.installed and .active and .version == "1.0.0"' "$v1" 1.0.0
check "activation ran the plugin's activation hook" "$(activated)" yes
put_release "updates a release" 200 '.version == "1.1.0" and .active' "$(release 1.1.0)" 1.1.0
put_release "refuses an update WordPress can't install, keeping the previous version" 422 '.code == "metatavu_iac_install_failed" and (.message | test("restored"))' "$(release 9.9.9 acme-release '' 'Requires PHP: 99.0')" 9.9.9
expect "the previous version is still installed and active" 200 '.plugins["acme-release"].version == "1.1.0" and .plugins["acme-release"].active' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
expect "deletes a plugin" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/plugins/acme-release"
check "deleting ran the plugin's deactivation hook" "$(activated)" ""
expect "deletes a deactivated wordpress.org plugin" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/plugins/classic-editor"

expect "a multipart PUT without the override is explained" 400 '.message | test("X-HTTP-Method-Override")' -- -X PUT -H "Authorization: Bearer $(token develop)" -F source=release -F "package=@$v1;type=application/zip" "$api/plugins/acme-release"

echo "== themes"
put_json "installs a theme from wordpress.org" 200 '.installed' '{"source":"wordpress.org"}' develop themes/twentytwentythree
put_json "activates it" 200 '.active_theme == "twentytwentythree"' '{"slug":"twentytwentythree"}' develop active-theme
expect "refuses to delete the active theme" 409 '.code == "metatavu_iac_active_theme"' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/themes/twentytwentythree"
put_json "switches back" 200 '.active_theme == "twentytwentyfive"' '{"slug":"twentytwentyfive"}' develop active-theme
expect "deletes the inactive theme" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/themes/twentytwentythree"

echo "== log"
writes=$(wp db query "SELECT COUNT(*) FROM wp_metatavu_iac_log WHERE id > $log_start AND kind = 'write' AND target LIKE 'plugin:%'" --skip-column-names | tr -d '[:space:]')
if [[ ${writes:-0} -ge 6 ]]; then echo "ok   plugin writes are logged ($writes)"; else echo "FAIL plugin writes are logged ($writes)" >&2; failures=$((failures + 1)); fi
failed=$(wp db query "SELECT COUNT(*) FROM wp_metatavu_iac_log WHERE id > $log_start AND target = 'plugin:acme-release' AND JSON_UNQUOTE(JSON_EXTRACT(detail, '\$.outcome')) = 'metatavu_iac_install_failed'" --skip-column-names | tr -d '[:space:]')
check "a failed write is logged with its outcome" "${failed:-0}" 1

if ((failures > 0)); then
	echo "$failures failed" >&2
	exit 1
fi
echo "all passed"
