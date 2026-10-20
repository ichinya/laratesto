# HTTP client with real PHPUnit

Run from the package root:

```bash
COMPOSER_BINARY=/path/to/composer.phar php tests/Consumer/http-client/verify.php
```

The optional check installs the current source through a Composer path repository
into an isolated consumer under `storage/framework/testing/`, alongside real
PHPUnit 12, Laravel 13.31.0 and Testo 0.10.56. It runs both the PHPUnit smoke test
and the native HTTP client fixtures under Testo. The root dependencies are not
modified. Logs and the resolved consumer lock file remain in the reported directory.

The facade requires a running LaravelPlugin execution under Testo. Installing
PHPUnit alongside Testo is supported; using this facade inside PHPUnit tests is
outside its execution scope and raises a clear exception.
