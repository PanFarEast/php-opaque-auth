# Installing opaque-auth

The native extension is `panfareast/opaque-auth`; its PHP module is `opaque_auth`.
Enabling it provides native `Opaque\Client` and `Opaque\Server` classes directly;
Composer and manually loading PHP files are not required.

Releases are distributed from the public repository
`https://github.com/panfareast/php-opaque-auth`, using an explicitly configured
PIE VCS repository. There is no Packagist publication or separate facade package
to install. You do not need a local source checkout or a GitHub token.

## Install with PIE

### Prerequisites

- Linux or Darwin, with PHP 8.3, 8.4, or 8.5.
- PHP development headers, `phpize`, and `php-config` matching the PHP runtime
  that will load the extension, including its NTS/ZTS and debug mode.
- Rust 1.88 or newer and Cargo.
- Clang and libclang, a C compiler, make, autoconf, and libtool.
- Git and unzip for package installation.
- [PIE](https://github.com/php/pie); the verified version is 1.5.5.
- Composer is optional for application dependency management, not required to
  use the native API.

Linux x86_64 non-debug NTS and ZTS builds have been verified on PHP 8.3.36,
8.4.27, and 8.5.12. Darwin builds are supported by the build script but
unverified. Other architectures and debug builds are unverified; Windows is
unsupported.

### Install and enable a tagged release

Register the package source and select an exact release:

```sh
COMPOSER_HOME=$(mktemp -d)
export COMPOSER_HOME
printf '%s\n' '{"config":{"use-github-api":false,"github-protocols":["https"]}}' > "$COMPOSER_HOME/config.json"
pie repository:add vcs https://github.com/panfareast/php-opaque-auth \
  --with-php-config=/path/to/php-config
pie info 'panfareast/opaque-auth:0.1.0' --with-php-config=/path/to/php-config
pie install 'panfareast/opaque-auth:0.1.0' \
  --with-php-config=/path/to/php-config \
  --with-phpize-path=/path/to/phpize
/path/to/php --ri opaque_auth
```

Replace all PHP paths with tools from the same PHP installation. PIE compiles
the Rust library, installs `opaque_auth.so`, and enables the module. On Darwin,
the Cargo `.dylib` artifact is installed under PHP's `.so` extension filename.
It needs permission to write that installation's extension and INI directories.
Restart long-running PHP processes after installation. CLI and FPM may use
different INI directories: verify that the module is enabled in the SAPI serving
your application, not only in the CLI.

PIE currently builds this package from source; it does not download a prebuilt
extension. Missing prerequisites must be installed before retrying.

The Git tag `v0.1.0` supplies Composer/PIE version `0.1.0`; use that exact package
constraint for reproducible selection. GitHub supplies the tagged source
archive and Git commit references. For later compatible `0.1.x` releases you
can opt into the constraint `^0.1`, but an exact version avoids changing releases
between builds. Do not move published tags; publish a new version instead.

The temporary Composer settings directory leaves your existing configuration
untouched. PIE reads those settings internally; the Composer CLI is not required.
Keep `COMPOSER_HOME` exported for these commands. Disabling the GitHub API driver
uses public Git over HTTPS to discover tags, avoiding API quotas and SSH
credential fallback. Source/archive metadata still refers to the exact tag
commit, but installation does not need GitHub API authentication.

If PIE cannot find the package, check that the VCS repository was registered for
the same target `php-config`. PIE's default Packagist repository does not contain
this package. If you see API authentication or SSH errors, confirm that the
transport settings above are active in that process before retrying.

### Optional local development checkout

For local development only, replace the VCS source with a path repository in
your development PIE configuration and select the development version:

```sh
pie repository:add path /absolute/path/to/php-opaque-auth \
  --with-php-config=/path/to/php-config
pie install 'panfareast/opaque-auth:*@dev' \
  --with-php-config=/path/to/php-config \
  --with-phpize-path=/path/to/phpize
```

Keep development path repositories separate from release-install verification;
an earlier canonical path repository can override the VCS source.

### Use the native API

No autoloader or PHP include is needed:

```sh
/path/to/php -r 'exit((new ReflectionClass("Opaque\\Client"))->isInternal() && (new ReflectionClass("Opaque\\Server"))->isInternal() ? 0 : 1);'
```

Call the classes directly; see the [registration and login examples](../README.md#php-api).
Composer-managed applications may require `ext-opaque_auth` (one `ext-` prefix)
so dependency installation checks for the enabled module. Run Composer with the
same PHP runtime used by the application.

If upgrading an earlier unpublished checkout, rebuild the extension and remove
old `php/Opaque.php` includes or the local facade dependency. Older copies with
unconditional class declarations conflict with native classes when explicitly
included; the current checkout guards those declarations. Public calls and
serialized protocol state remain unchanged. Keep your application's autoloader
for unrelated dependencies.

### Remove the native extension

```sh
pie uninstall panfareast/opaque-auth --with-php-config=/path/to/php-config
```

Restart long-running PHP processes again after removal.

## Docker: build with Rust, run without it

Use a multi-stage build. Both the builder and final image below inherit the
**same PHP base stage**, so the PHP version, ABI, thread mode, architecture, and
system libraries match. Only the installed shared library crosses from builder
to runtime; Rust, Cargo, Clang, PIE, source code, and build caches stay behind.

Save this as `Dockerfile` in an empty build directory; no source checkout is
needed:

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
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/opaque-auth-composer

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

ARG OPAQUE_VERSION=0.1.0
WORKDIR /build
RUN mkdir -p "$COMPOSER_HOME" \
    && printf '%s\n' '{"config":{"use-github-api":false,"github-protocols":["https"]}}' > "$COMPOSER_HOME/config.json" \
    && pie repository:add vcs https://github.com/panfareast/php-opaque-auth --no-interaction \
        --with-php-config=/usr/local/bin/php-config \
    && pie install "panfareast/opaque-auth:${OPAQUE_VERSION}" --no-interaction \
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

# Native public classes are available without copying any PHP facade.
RUN php -r 'exit((new ReflectionClass("Opaque\\Client"))->isInternal() && (new ReflectionClass("Opaque\\Server"))->isInternal() ? 0 : 1);'
```

Build and verify from the directory containing the Dockerfile:

```sh
docker build --build-arg OPAQUE_VERSION=0.1.0 -t php-opaque-auth:0.1.0 .
docker run --rm php-opaque-auth:0.1.0 php --ri opaque_auth
docker run --rm php-opaque-auth:0.1.0 php -r 'exit((new ReflectionClass("Opaque\\Client"))->isInternal() && (new ReflectionClass("Opaque\\Server"))->isInternal() ? 0 : 1);'
docker run --rm php-opaque-auth:0.1.0 sh -c '! command -v rustc && ! command -v cargo && ! command -v clang && ! command -v pie'
```

The example uses Debian Bookworm official PHP images, whose development tools
are available in the builder. The final image is the original PHP base plus
the extension and enablement INI. Release-install tests complete registration and
login with matching keys using only native classes. This is not a claim that the
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

Call the public classes directly; no `require` statement is necessary:

```php
$setup = Opaque\Server::createSetup();
```

If your application uses Composer for other dependencies, install them in a
separate application build stage where this native extension is enabled, then
copy the application and `vendor/` into the runtime image. No facade dependency
is needed. Keep `ext-opaque_auth`
enabled in the final image; bypassing Composer's platform checks is not a
substitute for installing it.

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

## Release preparation

Maintainers publish Git tags, not Packagist packages. For each stable release:

1. Align the Cargo package/module version with the intended `vX.Y.Z` tag and
   validate the root PIE metadata (`composer validate --strict`). Composer
   derives the package version from the tag; do not add a hard-coded `version`
   field to the package manifest.
2. Run the full PHP 8.3–8.5 NTS/ZTS suite and review the release commit. Push that
   commit, then publish an annotated `vX.Y.Z` tag pointing to it. Repository
   ownership and release approval remain with the maintainer; use their normal
   authenticated Git setup. No registry credential or automated publishing token
   is stored in this repository.
3. Check the tag's GitHub Actions run. Its `release-install` jobs build matching
   PHP environments, copy only tests into clean installation containers, and
   install `panfareast/opaque-auth:X.Y.Z` anonymously through the documented VCS
   route. They check package/module versions, source and distribution references
   against the tag commit, ABI, native registration/login, and removal.
4. Treat published tags as immutable. If release verification fails, investigate
   and publish any necessary fix as a new version rather than moving the tag.

When only verification tooling or documentation needs correction, leave the
published package/tag unchanged and verify it with the updated harness on main:

```sh
gh workflow run checks.yml --repo panfareast/php-opaque-auth --ref main -f release_tag=v0.1.0
```

The manual run resolves the selected tag's commit independently of the harness
commit and uses the same six-row anonymous-install matrix.

This route needs no Packagist dashboard or ownership claim. If a maintainer uses
third-party signing, ownership, or credentials in the future, those steps and
secrets remain human-controlled and outside tracked configuration.
