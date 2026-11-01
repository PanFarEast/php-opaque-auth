# ext-opaque-ke

Native PHP OPAQUE protocol extension built on [`opaque-ke`](https://crates.io/crates/opaque-ke) and [`ext-php-rs`](https://ext-php.rs/), targeting PHP 8.3–8.5.

> **Compatibility status:** this repository is an initial implementation, not yet an interoperability-certified replacement for `@serenity-kit/opaque`. Serenity Kit compatibility requires its exact Argon2id key-stretching defaults, optional identifiers, URL-safe unpadded Base64 encoding, and cross-runtime test vectors. The current Rust API implements the Ristretto255/TupleDH SHA-512 suite and the six protocol operations, but does not yet implement all optional parameters or prove cross-implementation compatibility. Do not deploy it as a drop-in replacement until the interoperability suite passes.

## Design

The extension exposes protocol operations only. PHP application code owns identity lookup, persistence, transport, and login-attempt lifecycle. In particular, `clientLoginState` and `serverLoginState` are opaque serializable values that the application must store and restore; the extension does not manage sessions.

The default target is the same Ristretto255 suite used by `@serenity-kit/opaque`. `@serenity-kit/opaque-p256` requires a distinct P-256 ciphersuite and is not implemented by the initial Ristretto-only build.

## Build

Requirements: Rust 1.85+, PHP development headers for the target ABI, and the system dependencies required by `ext-php-rs` (including Clang).

```sh
cargo build --release
```

The produced shared library is under `target/release/`. Load it with PHP's `extension=` directive. `ext-php-rs` exports the lower-level `opaque_*` functions; the intended high-level API mirrors the Serenity Kit names and object-shaped arguments.

## API shape to implement

The PHP facade should preserve the Serenity Kit operation names and result fields:

```php
$setup = Opaque\Server::createSetup();
$start = Opaque\Client::startRegistration(['password' => $password]);
$response = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => $userId,
    'registrationRequest' => $start['registrationRequest'],
]);
$record = Opaque\Client::finishRegistration([
    'clientRegistrationState' => $start['clientRegistrationState'],
    'registrationResponse' => $response['registrationResponse'],
    'password' => $password,
]);
```

Remaining methods are `Client::startLogin`, `Server::startLogin`, `Client::finishLogin`, and `Server::finishLogin`; field names match the upstream JS API (`startLoginRequest`, `loginResponse`, `serverLoginState`, `finishLoginRequest`, `sessionKey`, `exportKey`, `serverStaticPublicKey`).

## Security and compatibility work before release

- Implement Serenity Kit's Argon2id KSF exactly, including memory-constrained defaults and custom options. The parameters affect compatibility and offline-guessing resistance.
- Add client/server identifiers to the registration and login finish/start parameters.
- Implement P-256 as a separately selected ciphersuite if `@serenity-kit/opaque-p256` support is required.
- Run registration and login in both directions against `@serenity-kit/opaque` and its P-256 package using URL-safe, unpadded Base64 protocol strings.
- Test PHP 8.3, 8.4, and 8.5 builds; reject malformed messages and keep secrets out of errors/logs.

## Checks

```sh
cargo fmt --check
cargo test
cargo clippy --all-targets -- -D warnings
```
