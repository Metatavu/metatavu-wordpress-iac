#!/usr/bin/env bash
#
# Builds the release zip dist/metatavu-wordpress-iac-<version>.zip and its
# .sha256: the loader and metatavu-wordpress-iac/, with firebase/php-jwt
# renamed into Metatavu\WordPressIaC\Vendor by PHP-Scoper (D12), a class-map
# autoloader, and no development files. The version is the plugin header's.
# PHP and Composer run locally when installed, otherwise in scripts/dev.sh.
#
# Usage: scripts/build.sh

set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

scoper_version=0.18.20
scoper_sha256=5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122

run() {
	if command -v php >/dev/null && command -v composer >/dev/null; then
		"$@"
	else
		scripts/dev.sh "$@" | tr -d '\r'
	fi
}

version=$(sed -n 's/^ \* Version: *//p' metatavu-wordpress-iac.php)
[[ -n $version ]] || { echo "No Version in metatavu-wordpress-iac.php" >&2; exit 1; }

rm -rf build dist
mkdir -p build/stage build/plugin/metatavu-wordpress-iac dist

# PHP-Scoper, pinned and checked.
curl -fsSL -o build/php-scoper.phar "https://github.com/humbug/php-scoper/releases/download/$scoper_version/php-scoper.phar"
echo "$scoper_sha256  build/php-scoper.phar" | shasum -a 256 -c - >/dev/null || { echo "PHP-Scoper checksum mismatch" >&2; exit 1; }

# The runtime libraries only, then renamed.
cp composer.json composer.lock build/stage/
run composer install --working-dir=build/stage --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --quiet
run php build/php-scoper.phar add-prefix --config=scoper.inc.php --output-dir=build/plugin/metatavu-wordpress-iac/vendor-prefixed/firebase/php-jwt --force --no-interaction --quiet

# The plugin, using the renamed library.
cp metatavu-wordpress-iac.php build/plugin/
cp -R metatavu-wordpress-iac/plugin.php metatavu-wordpress-iac/src build/plugin/metatavu-wordpress-iac/
find build/plugin/metatavu-wordpress-iac/src -name '*.php' -exec perl -pi -e 's/(?<![\\\w])Firebase\\JWT\\/Metatavu\\WordPressIaC\\Vendor\\Firebase\\JWT\\/g' {} +

# A class-map autoloader for the plugin and the renamed library.
cat >build/plugin/composer.json <<'JSON'
{
	"autoload": { "classmap": ["metatavu-wordpress-iac/src/", "metatavu-wordpress-iac/vendor-prefixed/"] },
	"config": { "vendor-dir": "metatavu-wordpress-iac/vendor", "platform-check": false }
}
JSON
run composer dump-autoload --working-dir=build/plugin --classmap-authoritative --no-interaction --quiet
rm build/plugin/composer.json

zip=dist/metatavu-wordpress-iac-$version.zip
(cd build/plugin && zip -qrX "../../$zip" metatavu-wordpress-iac.php metatavu-wordpress-iac)
(cd dist && shasum -a 256 "$(basename "$zip")" >"$(basename "$zip").sha256")

# The zip has the renamed library and nothing for development.
listing=$(unzip -Z1 "$zip")
if grep -E '(^|/)(tests|\.github|dev|scripts|node_modules)/|composer\.(json|lock)$|phpunit|phpcs|\.wp-env|(^|/)config\.php$|\.pem$' <<<"$listing"; then
	echo "Development files in $zip" >&2
	exit 1
fi
grep -q '^metatavu-wordpress-iac/vendor-prefixed/firebase/php-jwt/src/JWT.php$' <<<"$listing" || { echo "No renamed php-jwt in $zip" >&2; exit 1; }
rm -rf build/check && unzip -q -d build/check "$zip"
if grep -rnE '^(namespace|use) +Firebase\\JWT' build/check; then
	echo "php-jwt isn't renamed everywhere in $zip" >&2
	exit 1
fi
# The scripts projects pin and run from bootstrap/, with their checksums.
for script in install-mu-plugin.sh manage-package.sh; do
	cp "scripts/$script" dist/
	(cd dist && shasum -a 256 "$script" >"$script.sha256")
done
echo "Built $zip ($(cut -d' ' -f1 <"$zip.sha256"))"
