#!/bin/sh
set -eu

export PHP_BINARY="${PHP_BINARY:-php}"
"$PHP_BINARY" -n tests/runtime.php
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/target/php-$OPAQUE_TEST_PHP_VERSION-$OPAQUE_TEST_PHP_VARIANT}"
case "$CARGO_TARGET_DIR" in
    /*) ;;
    *) export CARGO_TARGET_DIR="$PWD/$CARGO_TARGET_DIR" ;;
esac

composer validate --strict --no-check-publish
composer validate --working-dir=php --strict --no-check-publish
cargo fmt --check
cargo test --locked
cargo clippy --locked --all-targets -- -D warnings
cargo build --locked

export OPAQUE_EXTENSION="${OPAQUE_EXTENSION:-$CARGO_TARGET_DIR/debug/libopaque_auth.so}"
for test in tests/php/*.php; do
    "$PHP_BINARY" -n -d zend.exception_ignore_args=1 -d "extension=$OPAQUE_EXTENSION" "$test"
done

npm ci --prefix tests/interop --ignore-scripts --no-audit --no-fund
npm test --prefix tests/interop

sh tests/pie/check.sh
