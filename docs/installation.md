# Installing opaque-auth

The native extension is `panfareast/opaque-auth`; its PHP module is `opaque_auth`.
The optional PHP facade is the separate Composer package
`panfareast/opaque-auth-php`.

The repository is planned to move to
`https://github.com/panfareast/php-opaque-auth`. Until that transfer and package
publication are complete, use a local checkout as shown below. These instructions
do not assume the packages are already available on Packagist.

## Install with PIE

### Prerequisites

- Linux and PHP 8.3, 8.4, or 8.5.
- PHP development headers, `phpize`, and `php-config` matching the PHP runtime
  that will load the extension, including its NTS/ZTS and debug mode.
- Rust 1.88 or newer and Cargo.
- Clang and libclang, a C compiler, make, autoconf, and libtool.
- Git and unzip for package installation.
- [PIE](https://github.com/php/pie); the verified version is 1.5.5.
- Composer if you want the PHP facade; the verified version is 2.11.1.

Linux x86_64 non-debug NTS and ZTS builds have been verified on PHP 8.3.36,
8.4.27, and 8.5.12. Other architectures and debug builds are unverified;
Windows and macOS packaging is unsupported.

### Build and enable the native extension

With a local checkout at `/absolute/path/to/php-opaque-auth`, run:

```sh
pie repository:add path /absolute/path/to/php-opaque-auth \
  --with-php-config=/path/to/php-config
pie install 'panfareast/opaque-auth:*@dev' \
  --with-php-config=/path/to/php-config \
  --with-phpize-path=/path/to/phpize
/path/to/php --ri opaque_auth
```

Replace all PHP paths with tools from the same PHP installation. PIE compiles
the Rust library, installs `opaque_auth.so`, and enables the module. It needs
permission to write that installation's extension and INI directories.
Restart long-running PHP processes after installation. CLI and FPM may use
different INI directories: verify that the module is enabled in the SAPI serving
your application, not only in the CLI.

PIE currently builds this package from source; it does not download a prebuilt
extension. Missing prerequisites must be installed before retrying.

### Install the PHP facade

PIE installs the native module, not a Composer autoloader. In your application's
`composer.json`, add the facade directory as a path repository, preserving any
existing repositories:

```json
{
  "repositories": [
    { "type": "path", "url": "/absolute/path/to/php-opaque-auth/php" }
  ]
}
```

Then run Composer with the PHP runtime that has the extension enabled:

```sh
composer require 'panfareast/opaque-auth-php:*@dev'
php -r 'require "vendor/autoload.php"; exit(class_exists("Opaque\\Client") && class_exists("Opaque\\Server") ? 0 : 1);'
```

The facade requires `ext-opaque_auth` (one `ext-` prefix). Include
`vendor/autoload.php` in your application to use `Opaque\Client` and
`Opaque\Server`. Alternatively, explicitly require `php/Opaque.php` from the
checkout; the native extension must still be loaded.

### Remove the native extension

```sh
pie uninstall panfareast/opaque-auth --with-php-config=/path/to/php-config
```

Restart long-running PHP processes again after removal. The Composer facade is
a separate package; remove it with Composer if it is no longer needed.

## Docker: build with Rust, run without it

Use a multi-stage build. Both the builder and final image below inherit the
**same PHP base stage**, so the PHP version, ABI, thread mode, architecture, and
system libraries match. Only the installed shared library crosses from builder
to runtime; Rust, Cargo, Clang, PIE, source code, and build caches stay behind.

Save this as `Dockerfile` at the root of your local checkout:

```dockerfile
ARG PHP_IMAGE=php:8.3-cli-bookworm
FROM ${PHP_IMAGE} AS php-base
FROM rust:1.88-bookworm AS rust-toolchain

FROM php-base AS extension-builder
COPY --from=rust-toolchain /usr/local/cargo /usr/local/cargo
COPY --from=rust-toolchain /usr/local/rustup /usr/local/rustup
ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH=/usr/local/cargo/bin:$PATH \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS clang libclang-dev libtool git unzip \
    && rm -rf /var/lib/apt/lists/*

# Pin and verify PIE rather than fetching an unversioned installer.
RUN curl --retry 3 --retry-all-errors -fsSL \
        https://github.com/php/pie/releases/download/1.5.5/pie.phar \
        -o /usr/local/bin/pie \
    && echo '2ca0dff6868f1b53c095625674deef38ac698588e0a25e4321cd834a5b7553bc  /usr/local/bin/pie' | sha256sum -c - \
    && chmod +x /usr/local/bin/pie

WORKDIR /usr/src/php-opaque-auth
COPY Cargo.toml Cargo.lock composer.json ./
COPY src/ ./src/
COPY pie/build.sh pie/config.m4 ./pie/
RUN pie repository:add path /usr/src/php-opaque-auth --no-interaction \
        --with-php-config=/usr/local/bin/php-config \
    && pie install 'panfareast/opaque-auth:*@dev' --no-interaction \
        --with-php-config=/usr/local/bin/php-config \
        --with-phpize-path=/usr/local/bin/phpize \
    && php --ri opaque_auth \
    && mkdir -p /out \
    && cp "$(php-config --extension-dir)/opaque_auth.so" /out/opaque_auth.so

FROM php-base AS runtime
COPY --from=extension-builder /out/opaque_auth.so /tmp/opaque_auth.so
RUN cp /tmp/opaque_auth.so "$(php-config --extension-dir)/opaque_auth.so" \
    && rm /tmp/opaque_auth.so \
    && docker-php-ext-enable opaque_auth \
    && php --ri opaque_auth

# Optional facade, loaded explicitly; no Composer needed in the final image.
COPY php/Opaque.php /opt/opaque-auth/Opaque.php
```

Build and verify from the checkout root:

```sh
docker build -t php-opaque-auth:local .
docker run --rm php-opaque-auth:local php --ri opaque_auth
docker run --rm php-opaque-auth:local php -r 'require "/opt/opaque-auth/Opaque.php"; exit(class_exists("Opaque\\Client") && class_exists("Opaque\\Server") ? 0 : 1);'
docker run --rm php-opaque-auth:local sh -c '! command -v rustc && ! command -v cargo && ! command -v clang && ! command -v pie'
```

The example uses Debian Bookworm official PHP images, whose development tools
are available in the builder. The final image is the original PHP base plus
the extension, enablement INI, and optional facade; this is not a claim that the
upstream PHP base itself contains no development utilities.

For another verified PHP minor or thread mode, change the shared base:

```sh
docker build --build-arg PHP_IMAGE=php:8.5-zts-bookworm \
  -t php-opaque-auth:8.5-zts .
```

For FPM, use a matching official image such as `php:8.3-fpm-bookworm`. The final
stage inherits its FPM startup command. FPM serving behavior is not covered by
the CLI installation suite; verify the module in your deployed FPM process too.
Pin base images by digest for reproducible production builds, and rebuild the
extension when updating the PHP base.

### Integrating your application

The example copies the facade alone; application code can load it with:

```php
require_once '/opt/opaque-auth/Opaque.php';
```

If your application uses Composer instead, install its dependencies in a
separate application build stage where this native extension is enabled, then
copy the application and `vendor/` into the runtime image. For a local Composer
path repository, use `"options": { "symlink": false }` so the resulting vendor
directory contains the facade rather than a symlink to a builder-only checkout.
Keep `ext-opaque_auth` enabled in the final image; bypassing Composer's platform
checks is not a substitute for installing it.

### ABI and shared-library failures

- Never copy an NTS artifact into ZTS PHP, or an artifact built for a different
  PHP API/debug mode. Build separately for each target platform.
- Do not build on Debian and copy into Alpine: the libc and dependencies differ.
  This example is not an Alpine recipe.
- If PHP reports a missing shared library, inspect the artifact with `ldd` in the
  builder and install the corresponding runtime package in the final stage.
  Do not copy the entire build toolchain to resolve a runtime dependency.
- Keep the final `php --ri opaque_auth` build step: it fails the image build if
  the copied extension cannot be loaded.

Installing the extension does not configure application authentication sessions,
storage, or transport. Applications own those concerns and the lifecycle of
serialized protocol state. Never log passwords, setup, records, keys, or state.
