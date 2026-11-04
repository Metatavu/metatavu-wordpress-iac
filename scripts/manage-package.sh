#!/usr/bin/env bash
#
# Installs a whitelisted plugin or theme on a WP Engine install when it's
# missing, or removes it, with WP-CLI through WP Engine's SSH gateway and your
# own SSH key. A project's bootstrap/ runs it from a laptop (terraform_data,
# README.md); CI never does. On WP Engine, PHP in a REST request can't create
# or delete .php files, so the IaC plugin's API can't do this (plugin doc D18).
#
# Usage:
#   scripts/manage-package.sh --ssh <host> --install <install> --type plugin|theme \
#     --slug <slug> --source wordpress.org|release [--url <zip> --sha256 <sha256> --version <version>] [--remove]
#
#   --ssh      the SSH destination, for example an ~/.ssh/config alias for
#              <install>@<install>.ssh.wpengine.net
#   --install  the install's name: its files are in ~/sites/<install>
#   --type     plugin or theme
#   --slug     the plugin's directory or the theme's stylesheet
#   --source   wordpress.org, or release with its zip's --url, --sha256 and
#              --version, pinned in the project's code
#   --remove   deactivates a plugin and deletes the package; the active
#              theme isn't deleted
#
# An installed package is left as it is, whatever its version: the IaC
# plugin's API updates it. Nothing is activated. A release is downloaded on the
# laptop, from a private GitHub release with GITHUB_TOKEN or `gh auth token`,
# and checked before anything is uploaded.

set -euo pipefail

usage() {
	sed -n '3,27p' "$0" | sed 's/^# \{0,1\}//' >&2
	exit 1
}

ssh_host='' install='' type='' slug='' source='' url='' sha256='' version='' remove=''
while (($#)); do
	case $1 in
		--ssh) ssh_host=$2 ;;
		--install) install=$2 ;;
		--type) type=$2 ;;
		--slug) slug=$2 ;;
		--source) source=$2 ;;
		--url) url=$2 ;;
		--sha256) sha256=$2 ;;
		--version) version=$2 ;;
		--remove) remove=1 && shift && continue ;;
		*) usage ;;
	esac
	shift 2
done
[[ -n $ssh_host && -n $install && $type =~ ^(plugin|theme)$ && $source =~ ^(wordpress\.org|release)$ ]] || usage
[[ $install =~ ^[a-z][a-z0-9]{2,13}$ ]] || { echo "Invalid install name $install" >&2; exit 1; }
[[ $slug =~ ^[a-z0-9_-]+$ && $slug != metatavu-wordpress-iac ]] || { echo "Invalid slug $slug" >&2; exit 1; }
if [[ $source == release && -z $remove ]]; then
	[[ $url == https://* && $sha256 =~ ^[0-9a-f]{64}$ && -n $version ]] || usage
fi

ssh_options=(-o BatchMode=yes -o ConnectTimeout=30)
# The gateway mangles quotes in remote commands, so scripts come over stdin.
# ssh joins the arguments into one command line, so an empty one is sent as -.
remote() { ssh "${ssh_options[@]}" "$ssh_host" bash -s -- "$install" "$type" "$slug" "$@"; }

if [[ -n $remove ]]; then
	remote <<'REMOTE'
set -euo pipefail
install=$1 type=$2 slug=$3
wp=(wp --path="$HOME/sites/$install")
if ! "${wp[@]}" "$type" is-installed "$slug"; then
	echo "The $type $slug isn't installed on $install."
	exit 0
fi
if [[ $type == plugin ]]; then
	"${wp[@]}" plugin deactivate "$slug" --quiet 2>/dev/null || true
elif [[ $("${wp[@]}" option get stylesheet) == "$slug" || $("${wp[@]}" option get template) == "$slug" ]]; then
	echo "The theme $slug is active on $install: activate another one first." >&2
	exit 1
fi
"${wp[@]}" "$type" delete "$slug" --quiet
echo "Removed the $type $slug from $install."
REMOTE
	exit 0
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
zip=''
if [[ $source == release ]]; then
	zip=$work/$slug-$version.zip
	if ! curl -fsSL --retry 2 -o "$zip" "$url" 2>/dev/null; then
		# A private GitHub release: the asset through the API, with the person's own credentials.
		[[ $url =~ ^https://github\.com/([^/]+/[^/]+)/releases/download/([^/]+)/([^/]+)$ ]] || { echo "Could not download $url" >&2; exit 1; }
		repo=${BASH_REMATCH[1]} tag=${BASH_REMATCH[2]} name=${BASH_REMATCH[3]}
		token=${GITHUB_TOKEN:-$(gh auth token 2>/dev/null || true)}
		[[ -n $token ]] || { echo "Could not download $url, and there's no GITHUB_TOKEN or gh login for a private release" >&2; exit 1; }
		asset=$(curl -fsSL -H "Authorization: Bearer $token" "https://api.github.com/repos/$repo/releases/tags/$tag" |
			python3 -I -c 'import json, sys; print(next(a["url"] for a in json.load(sys.stdin)["assets"] if a["name"] == sys.argv[1]))' "$name")
		curl -fsSL --retry 2 -H "Authorization: Bearer $token" -H 'Accept: application/octet-stream' -o "$zip" "$asset"
	fi
	actual=$( (command -v sha256sum >/dev/null && sha256sum "$zip" || shasum -a 256 "$zip") | cut -d' ' -f1)
	[[ $actual == "$sha256" ]] || { echo "Checksum mismatch for $url: $actual, not $sha256" >&2; exit 1; }
	# WordPress installs a zip under its top directory, so that must be the slug.
	if unzip -Z1 "$zip" | grep -v -q "^$slug/"; then
		echo "$url must hold only the directory $slug/" >&2
		exit 1
	fi
fi

staging=''
if [[ -n $zip ]]; then
	# _wpeprivate is kept between sessions and never served; /tmp isn't kept.
	staging=sites/$install/_wpeprivate/metatavu-iac-package-$(date +%s)-$RANDOM
	ssh "${ssh_options[@]}" "$ssh_host" "mkdir -p $staging"
	rsync -e "ssh ${ssh_options[*]}" -q "$zip" "$ssh_host:$staging/"
fi

remote "$source" "${version:--}" "${staging:--}" "$(basename "${zip:--}")" <<'REMOTE'
set -euo pipefail
install=$1 type=$2 slug=$3 source=$4 version=$5 staging=$6 name=$7
wp=(wp --path="$HOME/sites/$install")
[[ $staging != - ]] && trap 'rm -rf "$HOME/$staging"' EXIT
if "${wp[@]}" "$type" is-installed "$slug"; then
	echo "The $type $slug is already installed on $install ($("${wp[@]}" "$type" get "$slug" --field=version)); left as it is."
	exit 0
fi
if [[ $source == wordpress.org ]]; then
	"${wp[@]}" "$type" install "$slug" --quiet
else
	"${wp[@]}" "$type" install "$HOME/$staging/$name" --quiet
	installed=$("${wp[@]}" "$type" get "$slug" --field=version)
	[[ $installed == "$version" ]] || { echo "Installed $slug $installed, not $version" >&2; exit 1; }
fi
echo "Installed the $type $slug $("${wp[@]}" "$type" get "$slug" --field=version) on $install."
REMOTE
