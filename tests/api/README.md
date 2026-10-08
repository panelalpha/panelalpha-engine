# Engine tests

These tests talk to a real PanelAlpha Engine. They create projects, check that they work, and delete them again. Run them only on an Engine you are allowed to change.

## Run

```bash
bun install        # Bun 1.4.2; Node 20.19 or newer is still needed, Playwright runs under Node
bun run test:smoke # about 45 s
```

| Command                 | When to use it                                                                                                                     | Time                                                    |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| `bun run test:smoke`    | Check that the Engine answers and the critical path works.                                                                         | about 45 s                                              |
| `bun run test:pr`       | Before a merge request: typecheck, lint, unit tests, smoke, the specs for the changed areas and four sample apps.                  | depends on the changed areas; each app takes 3 to 6 min |
| `bun run test`          | The everyday run: unit tests and the whole API. Application deploys are not run.                                                   | about 36 min on a 2 vCPU / 3.8 GB Engine                |
| `bun run test:full`     | Everything in `test` plus every application deploy.                                                                                | about 2 h 12 min on a 2 vCPU / 3.8 GB Engine            |
| `bun run test:nightly`  | `test:full`, then the slow tests, then the engine-wide tests whose opt-in is set (see [docs/maintainers.md](docs/maintainers.md)). | longer than `test:full`                                 |
| `bun run test:parallel` | Optional: `test:full` on several workers, sized to the Engine host. On a 4 GB Engine it is the same as the serial run.             | not recommended until its stability has been measured   |
| `bun run test:pr:plan`  | Show which tests `test:pr` would run, without running any.                                                                         | seconds                                                 |
| `bun run test:unit`     | Logic only. No Engine needed, works offline.                                                                                       | seconds                                                 |

Times are measurements on one Engine (2026-10-08), not promises. Anything after `--` is passed to Playwright: `bun run test -- tests/users`, `bun run test -- --grep "suspend"`.

## Requirements

- Bun and Node, as above.
- An Engine you may change: the tests create and delete projects on it.
- The first run creates `env/.env` for you. On the Engine host it asks nothing. From your own computer it asks which Engine to use and mints a token over SSH. Do not commit that file. To choose the Engine yourself: `bun run env:setup -- --host engine.example.com` (`--help` lists the rest).

## When something fails

- **Summary.** Every run writes `.playwright/summary.md`: the cause of each failed test, the skips, and the leak-check result.
- **Report.** `bun run report` serves the HTML report and prints the address. A failed test keeps a trace you can open from it. Over SSH, tunnel the port instead of opening it; see [docs/maintainers.md](docs/maintainers.md).
- **Skips.** A test skips when the Engine lacks what it needs, for example no firewall. Each skip is printed with its reason. The reasons we accept are listed in `config/expected-skips.ts`; any other reason is reported as unexpected and means something broke. In CI that is an error, and `MAX_SKIPPED` must be set. A run that skipped half the tests is not a full pass.
- **Projects of failed tests are deleted**, so the Engine stays clean. To keep them for inspection on your own machine, run with `PRESERVE_ON_FAILURE=1`. It is ignored in CI.
- **Leak-check.** After the run the Engine is compared with its state before, and the run fails if something was left behind. It is on by default in CI; run with `LEAK_CHECK=1` to use it locally. The result is the last line of `.playwright/summary.md`, and `bun run leak-check` runs it on its own.
- **Secrets** (the API token and license keys) are replaced with `[REDACTED]` in console output, JUnit, the HTML report and traces, so a published report does not carry them.

## More

- Variables and their defaults: [`env/.env.example`](env/.env.example).
- Run options, nightly opt-ins, test projects, report tunnel: [docs/maintainers.md](docs/maintainers.md).
- Before pushing changes to the suite: `bun run check`.
