#!/bin/sh
set -eu

if [ "${OPAQUE_PIE_TEST_CONTAINER:-}" != 1 ]; then
    echo 'Run PIE installation checks only in the disposable test container.' >&2
    exit 1
fi

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
export HOME="$work/home"
export COMPOSER_HOME="$work/composer"
export COMPOSER_ALLOW_SUPERUSER=1
export XDG_CONFIG_HOME="$work/config"
export XDG_DATA_HOME="$work/data"
export XDG_CACHE_HOME="$work/cache"
mkdir -p "$HOME" "$COMPOSER_HOME" "$work/app"
php "$root/tests/pie/enabled.php" 0

pie --version
composer --version
pie repository:add path "$root" --no-interaction --with-php-config="$(command -v php-config)"
pie install 'panfareast/opaque-auth:*@dev' --no-interaction \
    --with-php-config="$(command -v php-config)" \
    --with-phpize-path="$(command -v phpize)"
php --ri opaque_auth
php -d zend.exception_ignore_args=1 "$root/tests/pie/installed.php"

cd "$work/app"
php -r '
$root = $argv[1];
file_put_contents("composer.json", json_encode([
    "repositories" => [["type" => "path", "url" => $root . "/php"]],
    "require" => ["panfareast/opaque-auth-php" => "*@dev"],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
' "$root"
composer install --no-interaction --no-progress
php -d zend.exception_ignore_args=1 "$root/tests/pie/installed.php" "$work/app/vendor/autoload.php"
php "$root/tests/pie/enabled.php" 1

pie uninstall panfareast/opaque-auth --no-interaction \
    --with-php-config="$(command -v php-config)"
php "$root/tests/pie/enabled.php" 0

sh "$root/tests/pie/prerequisites.sh"

pie install 'panfareast/opaque-auth:*@dev' --no-interaction \
    --with-php-config="$(command -v php-config)" \
    --with-phpize-path="$(command -v phpize)"
php "$root/tests/pie/enabled.php" 1
php -d zend.exception_ignore_args=1 "$root/tests/pie/installed.php" "$work/app/vendor/autoload.php"
php -d zend.exception_ignore_args=1 "$root/tests/pie/installed.php"
pie uninstall panfareast/opaque-auth --no-interaction \
    --with-php-config="$(command -v php-config)"
php "$root/tests/pie/enabled.php" 0
