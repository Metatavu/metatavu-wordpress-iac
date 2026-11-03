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

# A release zip of acme-release at a version; extra arguments change it.
release() {
	local version=$1
	local name=acme-release-$version${3:+-with-$3}
	local dir=$work/$name
	rm -rf "$dir" "$work/$name.zip"
	mkdir -p "$dir/${2:-acme-release}"
	printf '<?php\n/**\n * Plugin Name: Acme Release\n * Version: %s\n */\n' "$version" >"$dir/${2:-acme-release}/acme-release.php"
	[[ -n ${3:-} ]] && printf 'x' >"$dir/$3"
	(cd "$dir" && zip -qr "$work/$name.zip" .)
	echo "$work/$name.zip"
}

put_release() {
	local description=$1 status=$2 filter=$3 zip=$4 version=$5 sha256=${6:-}
	[[ -n $sha256 ]] || sha256=$(shasum -a 256 "$zip" | cut -d' ' -f1)
	expect "$description" "$status" "$filter" -- -X POST -H 'X-HTTP-Method-Override: PUT' -H "Authorization: Bearer $(token develop)" \
		-F source=release -F "version=$version" -F "sha256=$sha256" -F active=true -F "package=@$zip;type=application/zip" \
		"$api/plugins/acme-release"
}

php tests/e2e/issue-token.php config "$site"

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
expect "nothing was installed by the refused uploads" 200 '.plugins["acme-release"].installed == false' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
put_release "installs and activates a release" 200 '.installed and .active and .version == "1.0.0"' "$v1" 1.0.0
put_release "updates a release" 200 '.version == "1.1.0" and .active' "$(release 1.1.0)" 1.1.0
broken=$work/broken && mkdir -p "$broken/acme-release" && printf 'Version: 9.9.9\nnot a plugin\n' >"$broken/acme-release/readme.txt" && printf '<?php // Version: 9.9.9\n' >"$broken/acme-release/acme-release.php" && (cd "$broken" && zip -qr "$work/broken.zip" .)
put_release "refuses an update that isn't a plugin, keeping the previous version" 422 '.code == "metatavu_iac_install_failed" and (.message | test("could not be installed")) and (.message | test("restored"))' "$work/broken.zip" 9.9.9
expect "the previous version is still installed and active" 200 '.plugins["acme-release"].version == "1.1.0" and .plugins["acme-release"].active' -- -H "Authorization: Bearer $(token develop-plan)" "$api/state"
expect "deletes a plugin" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/plugins/acme-release"
expect "deletes a deactivated wordpress.org plugin" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/plugins/classic-editor"

expect "a multipart PUT without the override is explained" 400 '.message | test("X-HTTP-Method-Override")' -- -X PUT -H "Authorization: Bearer $(token develop)" -F source=release -F "package=@$v1;type=application/zip" "$api/plugins/acme-release"

echo "== themes"
put_json "installs a theme from wordpress.org" 200 '.installed' '{"source":"wordpress.org"}' develop themes/twentytwentythree
put_json "activates it" 200 '.active_theme == "twentytwentythree"' '{"slug":"twentytwentythree"}' develop active-theme
expect "refuses to delete the active theme" 409 '.code == "metatavu_iac_active_theme"' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/themes/twentytwentythree"
put_json "switches back" 200 '.active_theme == "twentytwentyfive"' '{"slug":"twentytwentyfive"}' develop active-theme
expect "deletes the inactive theme" 200 '.installed == false' -- -X DELETE -H "Authorization: Bearer $(token develop)" "$api/themes/twentytwentythree"

echo "== log"
writes=$(wp db query "SELECT COUNT(*) FROM wp_metatavu_iac_log WHERE kind = 'write' AND target LIKE 'plugin:%'" --skip-column-names | tr -d '[:space:]')
if [[ ${writes:-0} -ge 6 ]]; then echo "ok   plugin writes are logged ($writes)"; else echo "FAIL plugin writes are logged ($writes)" >&2; failures=$((failures + 1)); fi

if ((failures > 0)); then
	echo "$failures failed" >&2
	exit 1
fi
echo "all passed"
