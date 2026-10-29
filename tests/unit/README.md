# PHPUnit tests

PHPUnit tests for `core/`: `Unit/` and `Feature/` (the two testsuites in `core/phpunit.xml`), with their support code in `Support/`, `Attributes/`, `fixtures/`, `config/` and `sessions/`. The namespace is `Tests\`.

`core/phpunit.xml`, `core/composer.json` (`autoload-dev`) and `core/vendor` stay in `core/`, so run the tests from there:

```bash
cd core
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
```

Paths in a test: `base_path()` is still `core/`, so reach this directory with `base_path('../tests/unit/...')`. From a test file, `core/` is `dirname(__DIR__, N)` plus `/core`, and the repository root needs no `core/` segment.

IP addresses in a test come from `Tests\Support\TestNetwork`, never from a literal; `Unit/TestNetworkLiteralsTest` fails on any address written into a test of any suite (recorded output under `fixtures/` excepted). A scenario address is a host of a configured network, `TestNetwork::public(7)` or `TestNetwork::private(5)`. The networks are the `TEST_NET_*` values in [`tests/test-network.env`](../test-network.env), shared with the API suite (`tests/api/helpers/test-network.ts`) and the shell tests (`tests/scripts/test-network.sh`); an environment variable of the same name overrides one for a run. Use `routable()` where the code refuses documentation ranges. An address fixed by a standard or by the engine, such as `0.0.0.0/0`, loopback, the metadata address or Docker's bridge gateway, is a `TestNetwork` constant instead.

This is not the same as [`tests/api/tests/unit`](../api/tests/unit), the `unit` project of the Playwright suite (TypeScript, no engine needed). That one tests the helpers of the API suite; this one tests the engine's PHP code.

Runtime coverage from `testing:coverage` lives in `tests/coverage/{tokenId}/`, separate from PHPUnit sources. `core/config/testing.php` defines the container and host paths; Compose mounts that directory at `/var/www/tests/coverage` so HTTP requests, queue workers and the report server share it. The installer and source deploy preserve this ignored directory. Existing recordings under `core/tests/coverage` are not migrated automatically; copy them to `tests/coverage` before resuming an old recording or serving an old report.
