# PHPUnit tests

PHPUnit tests for `core/`: `Unit/`, `Integration/` and `Feature/` (the three testsuites in `core/phpunit.xml`), with their support code in `Support/`, `Attributes/`, `fixtures/`, `config/` and `sessions/`. The namespace is `Tests\`.

`core/phpunit.xml`, `core/composer.json` (`autoload-dev`) and `core/vendor` stay in `core/`, so run the tests from there:

```bash
cd core
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/paratest -p 8 --testsuite Integration   # the same, on 8 workers
./vendor/bin/phpunit --testsuite Feature
```

## Unit or Integration

A test is in `Unit/` only when it tests code and nothing else: it extends `PHPUnit\Framework\TestCase`, boots no application, writes no file, starts no process and opens no connection. Reading files of the repository (fixtures, templates, manifests) is fine. Anything else is in `Integration/`: a test that boots Laravel (`Tests\TestCase`, a database, routes, facades), builds a temporary tree, or runs git, a script or a fake host. `Integration/` mirrors `Unit/`'s folders and its namespace is `Tests\Integration\`; a helper both use is in `Support/`.

The Unit suite takes seconds, and it is safe to run in parallel because nothing in it shares state. The Integration suite takes about an hour one test at a time and about ten minutes with ParaTest on 8 workers. Each ParaTest worker gets its own `sessions/<name>-worker<N>/` cache and its own storage tree under the system temp directory, so workers share no files; a test that writes elsewhere must use a path of its own (a temporary directory), never a fixed one.

The Unit suite must pass in a sandbox that enforces the rule: a read-only tree, no network, and the functions that write files or start processes disabled, so a write that the code under test swallows still fails. A test that passes outside it and fails inside belongs in `Integration/`. Run it from `core/`; PHPUnit cannot create its cache directory inside the sandbox, so make it first:

```bash
mkdir -p .phpunit.cache
docker run --rm --read-only --network none -v "$PWD/..:/app:ro" -w /app/core composer:2 \
  php -d disable_functions=proc_open,popen,exec,shell_exec,system,passthru,pcntl_exec,fsockopen,stream_socket_client,curl_exec,curl_multi_exec,mail,file_put_contents,mkdir,rmdir,unlink,rename,copy,touch,tempnam,tmpfile,symlink,chmod,chown \
  vendor/bin/phpunit --testsuite Unit --do-not-cache-result
```

`Unit/UnitSuiteBoundaryTest` fails on a test under `Unit/` that extends `Tests\TestCase`.

Paths in a test: `base_path()` is still `core/`, so reach this directory with `base_path('../tests/unit/...')`. From a test file, `core/` is `dirname(__DIR__, N)` plus `/core`, and the repository root needs no `core/` segment.

IP addresses in a test come from `Tests\Support\TestNetwork`, never from a literal; `Unit/TestNetworkLiteralsTest` fails on any address written into a test of any suite (recorded output under `fixtures/` excepted). A scenario address is a host of a configured network, `TestNetwork::public(7)` or `TestNetwork::private(5)`. The networks are the `TEST_NET_*` values in [`tests/test-network.env`](../test-network.env), shared with the API suite (`tests/api/helpers/test-network.ts`) and the shell tests (`tests/scripts/test-network.sh`); an environment variable of the same name overrides one for a run. Use `routable()` where the code refuses documentation ranges. An address fixed by a standard or by the engine, such as `0.0.0.0/0`, loopback, the metadata address or Docker's bridge gateway, is a `TestNetwork` constant instead.

This is not the same as [`tests/api/tests/unit`](../api/tests/unit), the `unit` project of the Playwright suite (TypeScript, no engine needed). That one tests the helpers of the API suite; this one tests the engine's PHP code.

Runtime coverage from `testing:coverage` lives in `tests/coverage/{tokenId}/`, separate from PHPUnit sources. `core/config/testing.php` defines the container and host paths; Compose mounts that directory at `/var/www/tests/coverage` so HTTP requests and queue workers share it. The installer and source deploy preserve this ignored directory. Existing recordings under `core/tests/coverage` are not migrated automatically; copy them to `tests/coverage` before resuming an old recording or reading an old report.
