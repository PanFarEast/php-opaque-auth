# ext-opaque-auth

Native PHP OPAQUE protocol extension built on [`opaque-ke`](https://crates.io/crates/opaque-ke) and [`ext-php-rs`](https://ext-php.rs/), targeting PHP 8.3–8.5.

> **Compatibility scope:** the interoperability suite targets [`@serenity-kit/opaque` **1.1.0**](https://www.npmjs.com/package/@serenity-kit/opaque/v/1.1.0), using Ristretto255/TripleDH/SHA-512. Passing tests demonstrate only the versions and configurations exercised, not interoperability certification or a security audit. P-256 is not supported.

## Design

The extension exposes protocol operations only. PHP application code owns identity lookup, persistence, transport, and login-attempt lifecycle. In particular, `clientLoginState` and `serverLoginState` are opaque serializable values that the application must store and restore; the extension does not manage sessions.

The default target is the same Ristretto255 suite used by `@serenity-kit/opaque`. `@serenity-kit/opaque-p256` requires a distinct P-256 ciphersuite and is not implemented by the initial Ristretto-only build.

## Build

Requirements: Rust 1.88+ (needed by the PHP 8.5-capable `ext-php-rs` dependency), PHP development headers for the target ABI, and the system dependencies required by `ext-php-rs` (including Clang).

```sh
cargo build --release
```

The produced shared library is `target/release/libext_opaque_auth.so` on Linux. Load it with PHP's `extension=` directive. The registered extension module is `ext-opaque-auth`. The `Opaque\Client` and `Opaque\Server` static methods accept associative arrays with Serenity Kit's field names and return associative arrays.

Load the PHP facade before calling these methods (or use its Composer classmap autoloader):

```php
require_once '/path/to/ext-opaque-auth/php/Opaque.php';
```

## PHP API

Registration:

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

```php
$login = Opaque\Client::startLogin(['password' => $password]);
$challenge = Opaque\Server::startLogin([
    'serverSetup' => $setup,
    'userIdentifier' => $userId,
    'registrationRecord' => $record['registrationRecord'],
    'startLoginRequest' => $login['startLoginRequest'],
]);
$finish = Opaque\Client::finishLogin([
    'password' => $password,
    'clientLoginState' => $login['clientLoginState'],
    'loginResponse' => $challenge['loginResponse'],
]);
if ($finish === null) {
    // Application handles failed authentication.
    throw new RuntimeException('Login failed');
}
$verified = Opaque\Server::finishLogin([
    'serverLoginState' => $challenge['serverLoginState'],
    'finishLoginRequest' => $finish['finishLoginRequest'],
]);
// Only after server verification may the application establish its own session.
```

These operations do not create or manage application sessions. Applications own account lookup, durable registration-record storage, transport, expiration and single-use handling of protocol state, and the application session established after successful authentication. Treat all returned keys, records, setup, and protocol states as secrets; never log them.

### Identifiers and key stretching

Client registration/login finish and server login start/finish accept `identifiers`, an optional array containing `client` and/or `server` strings. Use the same identifiers throughout registration and login. These cryptographic identifiers are separate from the application's `userIdentifier` used for account lookup.

Client registration/login finish accept `keyStretching`:

| Setting | Argon2id memory (KiB) | Iterations | Parallelism |
| --- | ---: | ---: | ---: |
| omitted or `memory-constrained` | 65536 | 3 | 4 |
| `rfc-recommended` | 2097151 | 1 | 4 |
| `rfc-draft-recommended` (deprecated alias) | 2097151 | 1 | 4 |

Custom parameters use `['argon2id-custom' => ['memory' => 65536, 'iterations' => 3, 'parallelism' => 4]]`. Registration and login must use the same configuration. Changing parameters does not upgrade existing records automatically. Treat KSF selection as trusted application configuration, not untrusted request input. The RFC profile requires roughly 2 GiB per operation; select parameters appropriate for your deployment without weakening password-guessing resistance inadvertently.

All serialized protocol values use URL-safe Base64 without padding. Wrong-password client login returns `null`; invalid arguments and malformed protocol values throw errors without including secret values. Do not return internal errors or protocol state to end users. Configure PHP with `zend.exception_ignore_args=On` so application exception traces cannot inadvertently log sensitive arguments.

## Checks

```sh
cargo fmt --check
cargo test
cargo clippy --all-targets -- -D warnings
```

The complete checks include PHP flows, malformed-input failures, and JavaScript interoperability:

```sh
docker build --build-arg PHP_VERSION=8.3 -t opaque-check -f tests/Dockerfile .
docker run --rm -v "$PWD:/work" opaque-check sh tests/check.sh
```

The Gitea Actions workflow runs the same checks for PHP 8.3, 8.4, and 8.5 on a Docker-capable runner. Cross-runtime tests exchange registration and login messages in both client/server directions, checking shared keys and encoding. Tests cover the memory-constrained/default and a custom KSF; the approximately 2 GiB RFC profile is not exercised. No compatibility is claimed for `@serenity-kit/opaque-p256` or for untested package versions/configurations.
