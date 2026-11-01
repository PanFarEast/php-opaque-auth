#!/bin/sh
set -eu

fail() {
    echo "opaque_auth: $*" >&2
    exit 1
}

require_tool() {
    command -v "$1" >/dev/null 2>&1 || fail "$1 is required; install the build prerequisites before retrying."
}

require_tool cargo
require_tool rustc
require_tool clang
PHP_CONFIG=${PHP_CONFIG:-php-config}
require_tool "$PHP_CONFIG"
export PHP_CONFIG
PHP=$("$PHP_CONFIG" --php-binary) || fail 'php-config must identify the target PHP executable.'
require_tool "$PHP"
export PHP

rustc --version | awk '{ split($2, v, "."); exit !(v[1] > 1 || (v[1] == 1 && v[2] >= 88)) }' \
    || fail 'Rust 1.88 or newer is required; update the Rust toolchain.'
php_version=$("$PHP_CONFIG" --vernum)
test "$php_version" -ge 80300 && test "$php_version" -lt 80600 \
    || fail 'PHP 8.3, 8.4, or 8.5 development tools are required.'
include_dir=$("$PHP_CONFIG" --include-dir)
test -f "$include_dir/main/php.h" && test -f "$include_dir/Zend/zend.h" \
    || fail 'PHP development headers are missing; install headers matching the target PHP ABI.'

case "${1:-}" in
    check) exit 0 ;;
    build) test "$#" -eq 2 || fail 'Build requires a destination directory.' ;;
    *) fail 'Expected check or build.' ;;
esac

destination=$2
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"

build_target=${CARGO_BUILD_TARGET:-$(rustc -vV | sed -n 's/^host: //p')}
case "$build_target" in
    *-apple-darwin)
        # PHP resolves extension API symbols from the hosting process at load time.
        RUSTFLAGS="${RUSTFLAGS:+$RUSTFLAGS }-C link-arg=-undefined -C link-arg=dynamic_lookup"
        export RUSTFLAGS
        artifact_suffix=dylib
        ;;
    *-linux-*) artifact_suffix=so ;;
    *) fail "Unsupported build target '$build_target'; supported targets are Linux and Darwin." ;;
esac

cargo build --release --locked
target=${CARGO_TARGET_DIR:-target}
artifact="$target/$build_target/release/libopaque_auth.$artifact_suffix"
if [ -z "${CARGO_BUILD_TARGET:-}" ]; then
    artifact="$target/release/libopaque_auth.$artifact_suffix"
fi
test -f "$artifact" || fail 'The native extension library was not produced.'
mkdir -p "$destination"
cp "$artifact" "$destination/opaque_auth.so"
