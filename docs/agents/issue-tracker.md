# Issue tracker: GitHub

Issues live in `panfareast/php-opaque-auth` on GitHub. Use the authenticated GitHub CLI (`gh`) with `--repo panfareast/php-opaque-auth` for issue operations.

Read the full issue body and comments before starting ticket work:

```sh
gh issue view <number> --repo panfareast/php-opaque-auth --comments
```

Preserve ticket dependencies and use the `ready-for-agent` label for approved work. Use `gh api` for native dependency relationships when needed.

If `gh` is unavailable or authentication fails, ask the user to restore access rather than creating a local replacement ticket.
