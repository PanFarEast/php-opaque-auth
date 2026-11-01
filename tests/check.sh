#!/bin/sh
set -eu

composer validate --strict --no-check-publish
composer validate --working-dir=php --strict --no-check-publish
cargo fmt --check
cargo test --locked
cargo clippy --locked --all-targets -- -D warnings
cargo build --locked

export OPAQUE_EXTENSION="${OPAQUE_EXTENSION:-$PWD/target/debug/libext_opaque_auth.so}"
export PHP_BINARY="${PHP_BINARY:-php}"
for test in tests/php/*.php; do
    "$PHP_BINARY" -n -d zend.exception_ignore_args=1 -d "extension=$OPAQUE_EXTENSION" "$test"
done

npm ci --prefix tests/interop --ignore-scripts --no-audit --no-fund
npm test --prefix tests/interop

sh tests/pie/check.sh
