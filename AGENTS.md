# Agent guide

## Project constraints

- This is a Rust `cdylib` PHP extension using `opaque-ke` and `ext-php-rs`. Build requirements: Rust 1.85+, PHP development headers for the target ABI, and Clang.
- Protocol values use URL-safe Base64 without padding. Keep client/server states serializable; PHP applications own persistence, transport, accounts, and session lifecycle.
- Treat passwords, setup, records, keys, and protocol state as secrets. Keep them out of logs and errors.
- Protocol and serialization changes must be checked against `@serenity-kit/opaque`. The implementation is not yet interoperability-certified; don't claim drop-in compatibility unless cross-runtime tests pass.

## Checks

```sh
cargo fmt --check
cargo test
cargo clippy --all-targets -- -D warnings
```

## Repository guidance

- Format commit messages as `tag: message`.
- For Gitea issue work, use the configured MCP tools; see `docs/agents/issue-tracker.md`.
- For glossary and architecture-decision conventions, see `docs/agents/domain.md`.
