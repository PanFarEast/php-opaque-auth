ARG PHP_VERSION=8.3
ARG PHP_VARIANT=cli
FROM rust:1.88-bookworm AS rust

FROM php:${PHP_VERSION}-${PHP_VARIANT}-bookworm
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH=/usr/local/cargo/bin:$PATH
RUN apt-get update \
    && apt-get install -y --no-install-recommends clang libclang-dev nodejs npm \
    && rm -rf /var/lib/apt/lists/*
RUN rustup component add rustfmt clippy
RUN apt-get update \
    && apt-get install -y --no-install-recommends git libtool unzip \
    && rm -rf /var/lib/apt/lists/*
RUN curl --retry 3 --retry-all-errors -fsSL https://github.com/php/pie/releases/download/1.5.5/pie.phar -o /usr/local/bin/pie \
    && echo '2ca0dff6868f1b53c095625674deef38ac698588e0a25e4321cd834a5b7553bc  /usr/local/bin/pie' | sha256sum -c - \
    && curl --retry 3 --retry-all-errors -fsSL https://getcomposer.org/download/2.11.1/composer.phar -o /usr/local/bin/composer \
    && echo '6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c  /usr/local/bin/composer' | sha256sum -c - \
    && chmod +x /usr/local/bin/pie /usr/local/bin/composer
ENV OPAQUE_PIE_TEST_CONTAINER=1
ARG PHP_VERSION
ARG PHP_VARIANT
ENV OPAQUE_TEST_PHP_VERSION=${PHP_VERSION} \
    OPAQUE_TEST_PHP_VARIANT=${PHP_VARIANT}
WORKDIR /work
