# PHPUnit tests

PHPUnit tests for `core/`: `Unit/` and `Feature/` (the two testsuites in `core/phpunit.xml`), with their support code in `Support/`, `Attributes/`, `fixtures/`, `config/` and `sessions/`. The namespace is `Tests\`.

`core/phpunit.xml`, `core/composer.json` (`autoload-dev`) and `core/vendor` stay in `core/`, so run the tests from there:

```bash
cd core
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
```

Paths in a test: `base_path()` is still `core/`, so reach this directory with `base_path('../tests/unit/...')`. From a test file, `core/` is `dirname(__DIR__, N)` plus `/core`, and the repository root needs no `core/` segment.

This is not the same as [`tests/api/tests/unit`](../api/tests/unit), the `unit` project of the Playwright suite (TypeScript, no engine needed). That one tests the helpers of the API suite; this one tests the engine's PHP code.

Runtime coverage from `testing:coverage` lives in `tests/coverage/{tokenId}/`, separate from PHPUnit sources. `core/config/testing.php` defines the container and host paths; Compose mounts that directory at `/var/www/tests/coverage` so HTTP requests, queue workers and the report server share it. The installer and source deploy preserve this ignored directory. Existing recordings under `core/tests/coverage` are not migrated automatically; copy them to `tests/coverage` before resuming an old recording or serving an old report.
