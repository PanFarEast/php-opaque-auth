ARG PHP_VERSION=8.3
FROM rust:1.88-bookworm AS rust

FROM php:${PHP_VERSION}-cli-bookworm
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH=/usr/local/cargo/bin:$PATH
RUN apt-get update \
    && apt-get install -y --no-install-recommends clang libclang-dev nodejs npm \
    && rm -rf /var/lib/apt/lists/*
RUN rustup component add rustfmt clippy
WORKDIR /work
