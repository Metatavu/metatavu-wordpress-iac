#!/usr/bin/env bash
#
# Installs or updates the IaC must-use plugin and its config.php on a WP
# Engine install, through WP Engine's SSH gateway with your own SSH key. A
# project's bootstrap/ runs it from a laptop (terraform_data, README.md);
# CI never does.
#
# Usage:
#   IAC_CONFIG_PHP="$(cat config.php)" scripts/install-mu-plugin.sh \
#     --ssh <host> --install <install> --version <version> --sha256 <sha256> [--zip <file>]
#
#   --ssh      the SSH destination, for example an ~/.ssh/config alias for
#              <install>@<install>.ssh.wpengine.net
#   --install  the install's name: its files are in ~/sites/<install>
#   --version  the release to install, for example 0.1.0
#   --sha256   the release zip's SHA-256, pinned in the project's code
#   --zip      a local zip instead of the release download, for testing
#
# The config comes in IAC_CONFIG_PHP, not as a file, so nothing is left on
# disk. The zip is checked before anything is uploaded. On the install, the
# new files are unpacked next to the old ones, the config is checked with
# php -l, and the plugin is swapped in. If WordPress then doesn't load, the
# previous version is put back.

set -euo pipefail

usage() {
	sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//' >&2
	exit 1
}

ssh_host='' install='' version='' sha256='' zip=''
while (($#)); do
	case $1 in
		--ssh) ssh_host=$2 ;;
		--install) install=$2 ;;
		--version) version=${2#v} ;;
		--sha256) sha256=$2 ;;
		--zip) zip=$2 ;;
		*) usage ;;
	esac
	shift 2
done
[[ -n $ssh_host && -n $install && -n $version && $sha256 =~ ^[0-9a-f]{64}$ ]] || usage
[[ $install =~ ^[a-z][a-z0-9]{2,13}$ ]] || { echo "Invalid install name $install" >&2; exit 1; }
[[ -n ${IAC_CONFIG_PHP:-} ]] || { echo "IAC_CONFIG_PHP has no config.php" >&2; exit 1; }
[[ $IAC_CONFIG_PHP == '<?php'* ]] || { echo "IAC_CONFIG_PHP must be PHP, starting with <?php" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
name=metatavu-wordpress-iac-$version.zip

if [[ -z $zip ]]; then
	zip=$work/$name
	# The release's download URL, or the same asset through the API when
	# github.com fails to serve it; the pinned checksum is checked either way.
	if ! curl -fsSL --retry 2 -o "$zip" "https://github.com/Metatavu/metatavu-wordpress-iac/releases/download/v$version/$name" 2>/dev/null; then
		asset=$(curl -fsSL "https://api.github.com/repos/Metatavu/metatavu-wordpress-iac/releases/tags/v$version" |
			python3 -I -c 'import json, sys; print(next(a["url"] for a in json.load(sys.stdin)["assets"] if a["name"] == sys.argv[1]))' "$name")
		curl -fsSL --retry 2 -H 'Accept: application/octet-stream' -o "$zip" "$asset"
	fi
fi
# Uploaded under the release's name, whatever a local zip is called.
[[ $zip == "$work/$name" ]] || cp "$zip" "$work/$name"
zip=$work/$name
actual=$( (command -v sha256sum >/dev/null && sha256sum "$zip" || shasum -a 256 "$zip") | cut -d' ' -f1)
[[ $actual == "$sha256" ]] || { echo "Checksum mismatch for $zip: $actual, not $sha256" >&2; exit 1; }
printf '%s\n' "$IAC_CONFIG_PHP" >"$work/config.php"

ssh_options=(-o BatchMode=yes -o ConnectTimeout=30)
# _wpeprivate is kept between sessions and never served; /tmp isn't kept.
staging=sites/$install/_wpeprivate/metatavu-iac-install-$(date +%s)-$RANDOM
ssh "${ssh_options[@]}" "$ssh_host" "mkdir -p $staging"
rsync -e "ssh ${ssh_options[*]}" -q "$zip" "$work/config.php" "$ssh_host:$staging/"

# The gateway mangles quotes in remote commands, so the script comes over stdin.
ssh "${ssh_options[@]}" "$ssh_host" bash -s -- "$install" "$staging" "$name" <<'REMOTE'
set -euo pipefail
install=$1 staging=$HOME/$2 name=$3
site=$HOME/sites/$install
mu=$site/wp-content/mu-plugins
cd "$staging"
trap 'rm -rf "$staging"' EXIT

unzip -q "$name" -d new
[[ -f new/metatavu-wordpress-iac.php && -f new/metatavu-wordpress-iac/plugin.php ]] || { echo "The zip isn't the IaC plugin" >&2; exit 1; }
php -l config.php >/dev/null || { echo "config.php doesn't parse" >&2; exit 1; }
cp config.php new/metatavu-wordpress-iac/config.php

mkdir -p "$mu" old
[[ -e $mu/metatavu-wordpress-iac ]] && cp -a "$mu/metatavu-wordpress-iac" old/
[[ -e $mu/metatavu-wordpress-iac.php ]] && cp -a "$mu/metatavu-wordpress-iac.php" old/

restore() {
	echo "WordPress doesn't load with the new version; putting the previous one back." >&2
	rm -rf "$mu/metatavu-wordpress-iac" "$mu/metatavu-wordpress-iac.php"
	[[ -e old/metatavu-wordpress-iac ]] && mv old/metatavu-wordpress-iac "$mu/"
	[[ -e old/metatavu-wordpress-iac.php ]] && mv old/metatavu-wordpress-iac.php "$mu/"
	exit 1
}

# The directory first, then the loader that requires it: each is a rename
# on one file system.
rm -rf "$mu/.metatavu-wordpress-iac.replaced"
[[ -e $mu/metatavu-wordpress-iac ]] && mv "$mu/metatavu-wordpress-iac" "$mu/.metatavu-wordpress-iac.replaced"
mv new/metatavu-wordpress-iac "$mu/metatavu-wordpress-iac"
mv new/metatavu-wordpress-iac.php "$mu/metatavu-wordpress-iac.php"
rm -rf "$mu/.metatavu-wordpress-iac.replaced"

wp --path="$site" eval 'echo class_exists( "\Metatavu\WordPressIaC\Plugin" ) ? "loaded" : "missing";' 2>/dev/null | grep -qx loaded || restore
version=$(sed -n 's/^ \* Version: *//p' "$mu/metatavu-wordpress-iac.php")
echo "Installed the IaC plugin $version on $install."
REMOTE
