#!/bin/sh
set -eu

if [ "${OPAQUE_PIE_TEST_CONTAINER:-}" != 1 ]; then
    echo 'Run PIE prerequisite checks only in the disposable test container.' >&2
    exit 1
fi

if php -r 'exit(extension_loaded("opaque_auth") ? 0 : 1);'; then
    echo 'Uninstall opaque_auth before running PIE prerequisite checks.' >&2
    exit 1
fi

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM

export HOME="$work/home"
export COMPOSER_HOME="$work/composer"
export COMPOSER_ALLOW_SUPERUSER=1
export XDG_CONFIG_HOME="$work/config"
export XDG_DATA_HOME="$work/data"
export XDG_CACHE_HOME="$work/cache"
mkdir -p "$HOME" "$COMPOSER_HOME"

pie_bin=$(command -v pie)
php_config=$(command -v php-config)
phpize=$(command -v phpize)
original_path=$PATH

# Give every attempt its own small source tree, so PIE cannot write into the
# checkout or interfere with another PIE build running there.
make_fixture() {
    fixture=$1
    mkdir -p "$fixture/src" "$fixture/php" "$fixture/pie"
    cp "$root/Cargo.toml" "$fixture/"
    [ ! -f "$root/Cargo.lock" ] || cp "$root/Cargo.lock" "$fixture/"
    cp "$root/composer.json" "$fixture/"
    cp "$root/src/lib.rs" "$fixture/src/"
    cp "$root/php/composer.json" "$fixture/php/"
    cp "$root/php/Opaque.php" "$fixture/php/"
    cp "$root/pie/config.m4" "$root/pie/build.sh" "$fixture/pie/"
}

without_tool() {
    tool=$1
    filtered="$work/path-$tool"
    mkdir -p "$filtered"
    old_ifs=$IFS
    IFS=:
    for dir in $original_path; do
        [ -n "$dir" ] || dir=.
        for binary in "$dir"/*; do
            name=${binary##*/}
            if [ "$name" != "$tool" ] && [ -x "$binary" ] && [ ! -e "$filtered/$name" ]; then
                ln -s "$binary" "$filtered/$name"
            fi
        done
    done
    IFS=$old_ifs
    PATH="$filtered"
    export PATH
}

run_failure_case() {
    label=$1
    expected=$2
    mode=$3
    case_dir="$work/$label"
    fixture="$case_dir/package"
    mkdir -p "$case_dir"
    make_fixture "$fixture"
    log="$case_dir/output.log"
    export HOME="$case_dir/home" COMPOSER_HOME="$case_dir/composer"
    export XDG_CONFIG_HOME="$case_dir/config" XDG_DATA_HOME="$case_dir/data" XDG_CACHE_HOME="$case_dir/cache"
    export OPAQUE_TEST_SECRET=INSTALLER_SENTINEL_NOT_A_REAL_SECRET
    mkdir -p "$HOME" "$COMPOSER_HOME"

    PATH=$original_path
    export PATH
    php_config_arg=$php_config
    phpize_arg=$phpize
    case "$mode" in
        missing-tool)
            without_tool "$label"
            ;;
        missing-phpize)
            phpize_arg="$case_dir/no-such-phpize"
            ;;
        missing-headers)
            wrapper="$case_dir/php-config-no-includes"
            cat > "$wrapper" <<EOF
#!/bin/sh
case \$1 in
    --includes) echo -I/definitely-missing-php-headers; exit 0 ;;
    --include-dir) echo /definitely-missing-php-headers; exit 0 ;;
esac
exec "$php_config" "\$@"
EOF
            chmod +x "$wrapper"
            php_config_arg=$wrapper
            ;;
    esac

    set +e
    (
        cd "$case_dir"
        "$pie_bin" repository:add path "$fixture" --no-interaction \
            --with-php-config="$php_config_arg" &&
        "$pie_bin" install 'panfareast/opaque-auth:*@dev' --no-interaction \
            --with-php-config="$php_config_arg" \
            --with-phpize-path="$phpize_arg"
    ) >"$log" 2>&1
    status=$?
    set -e
    PATH=$original_path
    export PATH
    if grep -Fq "$OPAQUE_TEST_SECRET" "$log"; then
        echo 'PIE output leaked a test secret marker.' >&2
        exit 1
    fi
    if [ "$status" -eq 0 ]; then
        cat "$log" >&2
        echo "PIE unexpectedly succeeded without $expected" >&2
        exit 1
    fi
    if ! grep -Fi "$expected" "$log" >/dev/null; then
        cat "$log" >&2
        echo "PIE failure did not explain the missing $expected prerequisite" >&2
        exit 1
    fi
    php "$root/tests/pie/enabled.php" 0
    echo "PASS: missing $label is reported without installing opaque_auth"
}

run_failure_case cargo 'opaque_auth: cargo is required' missing-tool
run_failure_case rustc 'opaque_auth: rustc is required' missing-tool
run_failure_case clang 'opaque_auth: clang is required' missing-tool
run_failure_case phpize phpize missing-phpize
run_failure_case php-headers 'PHP development headers are missing' missing-headers
