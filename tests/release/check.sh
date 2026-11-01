#!/bin/sh
set -eu

if [ "${OPAQUE_PIE_TEST_CONTAINER:-}" != 1 ]; then
    echo 'Run release installation checks only in the disposable test container.' >&2
    exit 1
fi
: "${OPAQUE_RELEASE_VERSION:?Set the exact release version, without v}"
: "${OPAQUE_RELEASE_COMMIT:?Set the expected release commit}"

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
php -n "$root/tests/runtime.php"
php "$root/tests/pie/enabled.php" 0
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
export HOME="$work/home" COMPOSER_HOME="$work/composer" COMPOSER_ALLOW_SUPERUSER=1
export XDG_CONFIG_HOME="$work/config" XDG_DATA_HOME="$work/data" XDG_CACHE_HOME="$work/cache"
unset GH_TOKEN GITHUB_TOKEN COMPOSER_AUTH
mkdir -p "$HOME" "$COMPOSER_HOME"
cd "$work"

# This harness contains tests only: source must come from the public repository.
if [ -e "$root/Cargo.toml" ] || [ -d "$root/src" ]; then
    echo 'Copy only the release test harness into the container, not the source checkout.' >&2
    exit 1
fi

php_config=$(command -v php-config)
phpize=$(command -v phpize)
pie repository:add vcs https://github.com/panfareast/php-opaque-auth --no-interaction \
    --with-php-config="$php_config"
pie info "panfareast/opaque-auth:$OPAQUE_RELEASE_VERSION" --no-interaction \
    --with-php-config="$php_config"
pie install "panfareast/opaque-auth:$OPAQUE_RELEASE_VERSION" --no-interaction \
    --with-php-config="$php_config" --with-phpize-path="$phpize"
php "$root/tests/release/metadata.php" "$work"
php -d zend.exception_ignore_args=1 "$root/tests/pie/installed.php"
pie uninstall panfareast/opaque-auth --no-interaction --with-php-config="$php_config"
php "$root/tests/pie/enabled.php" 0
