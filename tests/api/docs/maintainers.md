# For maintainers

Details that the README leaves out. Commands and variables here are the source of truth for the run options; `scripts/run-tests.ts` prints matching hints.

## Run options

Set these in the shell for one run. Connection and timing variables live in [`../env/.env.example`](../env/.env.example).

| Variable                 | Effect                                                                                                                                           |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `SUPPORTED_APPS=a,b`     | Narrows the application deploys to those slugs. The once-each deploy checks still run.                                                           |
| `TEST_ENV=<name>`        | Loads `env/.env.<name>` on top of `env/.env` and keeps report, results and setup state per profile. Create it with `env:setup --profile <name>`. |
| `PR_BASE=<rev>`          | Base revision `test:pr` compares against. Default: the first of `origin/main`, `main`, `origin/master`, `master`.                                |
| `PR_APPS=a,b`            | Applications `test:pr` deploys instead of the default four. `0` or `off` runs none.                                                              |
| `TEST_PARALLEL=auto`     | Sizes the run to the Engine host (`bun run test:parallel`). A number fixes the API worker count. Unset or `off` means one worker.                |
| `ENGINE_QUEUE_WORKERS=n` | Tells the parallel sizing how many queue workers the Engine has, instead of deriving it from the Engine's RAM.                                   |
| `LEAK_CHECK=1` / `0`     | Force the leak-check on or off. Default: on when `CI` is set.                                                                                    |
| `MAX_SKIPPED=n`          | Fails the run when more than `n` tests skipped. Mandatory in CI.                                                                                 |
| `PRESERVE_ON_FAILURE=1`  | Keeps the projects of failed tests. Ignored in CI.                                                                                               |

## Run levels

`bun run test` runs unit + setup + API (about 36 min) and ends with a line saying the supported apps were not run. `bun run test:full` (`--full`) adds `supported-apps` (about 2 h 12 min). `bun run test:parallel` is `test:full` sized to the Engine host. `--pr` and `--nightly` always use the full scope. An explicit `--project=...` replaces the default list in every case.

## Nightly opt-ins

`bun run test:nightly` runs `test:full`, then `slow` (always). The others run only when their variable is set, because each one changes the whole Engine or needs its host:

| Project            | Set                                     |
| ------------------ | --------------------------------------- |
| `cli`              | `NIGHTLY_CLI=1` (needs SSH to the host) |
| `engine-cert`      | `NIGHTLY_ENGINE_CERT=1`                 |
| `network-mutation` | `ALLOW_NETWORK_MUTATION=1`              |
| `webserver-change` | `WEBSERVER=<target>`                    |
| `update`           | `NIGHTLY_UPDATE=1`                      |

`webserver-change` needs care: Engine v2.x refuses every switch away from nginx-proxy (422), so until switching is re-enabled the project only verifies that refusal and skips with the reason `This engine refuses every webserver switch`.

The command prints which of them it runs and which it skips.

## Single groups

| Command                         | What it does                                                       |
| ------------------------------- | ------------------------------------------------------------------ |
| `bun run test:supported-apps`   | Only the application deploys and the once-each checks.             |
| `bun run test:slow`             | Firewall off and on, every PHP version.                            |
| `bun run test:cli`              | The Engine's own commands. Only works on the Engine host.          |
| `bun run test:webserver-change` | Switches the web server and back. Changes the Engine for everyone. |
| `bun run test:update`           | The Engine's update.                                               |
| `bun run test:setup`            | Creates the shared test user. Done automatically in a normal run.  |
| `bun run test:ui`               | Watch the tests in a window.                                       |

## Playwright projects

`unit`, `setup`, `setup-dind`, `api` (split into `api-parallel` and `api-serial` when `TEST_PARALLEL` gives more than one worker), `supported-apps`, `slow`, `cli`, `webserver-change`, `update`, `engine-cert`, `network-mutation`. They are defined in `playwright.config.ts`. A split run writes one report and one summary per phase (`.playwright/report-parallel`, `.playwright/report-serial`) and a combined `.playwright/summary.md`.

## Report over SSH

`bun run report` serves the report on port 9323 (`REPORT_PORT`) and prints the address, using `REPORT_HOST` when set. Without opening the port:

```bash
ssh -L 9323:127.0.0.1:9323 root@engine.example.com
```

then open `http://127.0.0.1:9323`.
