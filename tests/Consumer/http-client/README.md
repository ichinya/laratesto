# HTTP client with real PHPUnit

Run from the package root:

Composer needs archive extraction support: enable PHP's ZIP extension or provide
`unzip`/`7z` on `PATH` to avoid falling back to git source clones.

```bash
COMPOSER_BINARY=/path/to/composer.phar php tests/Consumer/http-client/verify.php
```

The optional check installs the current source through a Composer path repository
into an isolated consumer under `storage/framework/testing/`, alongside real
PHPUnit, Laravel and Testo. It runs both the PHPUnit smoke test
and the native HTTP client fixtures under Testo. The root dependencies are not
modified. Logs and the resolved consumer lock file remain in the reported directory.

The facade requires a running LaravelPlugin execution under Testo. Installing
PHPUnit alongside Testo is supported; using this facade inside PHPUnit tests is
outside its execution scope and raises a clear exception.
