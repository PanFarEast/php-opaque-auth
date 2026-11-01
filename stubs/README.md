# IDE and static-analysis support

`opaque_auth.stub.php` describes the native classes and low-level functions.
PHPDoc array shapes provide parameter-key completion, result field types,
identifier/key-stretching options, and nullable client login results.

This is **development-only metadata**, not an implementation. Never `require`
it, add it to Composer autoload/classmap, or preload it: the extension already
declares these symbols. It is not needed in production or your Docker runtime.

## IDE setup

- **PhpStorm:** add this directory as an external PHP library under
  **Settings → PHP → Include Path**. Avoid indexing the earlier PHP facade as
  another declaration of the same classes.
- **VS Code / Intelephense:** add this directory to
  `intelephense.environment.includePaths` (use an absolute path when it lives
  outside the workspace). If the whole checkout is already indexed, the stub
  is available without an extra include path; exclude `php/Opaque.php` from
  indexing to avoid competing facade declarations.

Point the IDE at this directory in your extension checkout; no Composer package
is required. Use the stub from the same revision as the native extension.

## PHPStan

Add the file to your project's `phpstan.neon`, adjusting the path:

```neon
parameters:
    stubFiles:
        - /path/to/php-opaque-auth/stubs/opaque_auth.stub.php
```

Use `stubFiles`, not `bootstrapFiles`: the analyzer should read declarations,
not execute them. Run analysis with the extension available so PHPStan can
discover the native symbols and enrich them with these PHPDoc types.

## Psalm

Add the file to your project's `psalm.xml`, adjusting the path:

```xml
<stubs>
    <file name="/path/to/php-opaque-auth/stubs/opaque_auth.stub.php" />
</stubs>
```

The normal PHP test suite compares stub and native signatures in isolated
processes, including parameter names, optional defaults, return nullability,
method visibility, and sensitive-parameter attributes. PHPDoc shapes describe
the public contract; protocol behavior is covered by the API/interop tests.
