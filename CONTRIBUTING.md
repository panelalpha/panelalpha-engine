# Contributing to PanelAlpha Engine

First off, thank you for taking the time to contribute! 🙌
Whether it's a bug report, a docs fix, a new supported project type, or a core change, it's welcome.

This guide gets you from a clean checkout to a pull request that's easy to review.


## Ways to contribute

- **Report a bug.** Open an issue with clear steps to reproduce, the engine version, and the host OS.
- **Suggest an idea.** Open an issue describing the problem you want solved, not just the solution.
- **Improve the docs.** The operator documentation lives in [`docs/`](docs/README.md) and is written for the person who installs a host. Small fixes are very welcome.
- **Add support for an application.** The playbook is in [`AGENTS.md` §11](AGENTS.md). Every claim is a measurement.
- **Fix a bug or build a feature.** See the workflow below.

> 🔒 **Found a security issue?** Do **not** open a public issue. Follow [`SECURITY.md`](SECURITY.md) and report it privately.


## Contributing code and documentation

Please follow the workflow below. It's pretty basic but keeps things in check:
1. **Branch** off the default branch with a descriptive name (e.g. `fix/archive-deploy-port`, `feat/detect-deno`).
2. **Keep it focused.** Small, single-purpose pull requests are far easier to review and land faster.
3. **Write a test** where it makes sense, and run the suite.
4. **Update the docs** if behaviour changed: [`docs/`](docs/README.md) for operators, [`AGENTS.md`](AGENTS.md) for engine internals.

### Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add Deno detection to the strategy chain
fix: re-render proxy vhosts after archive deploy
docs: clarify MCP token creation
chore: bump dev dependencies
```

Common types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`. Reference an issue where relevant (e.g. `#123`).


## Contributor License Agreement

Before we can accept your first contribution, you must agree to the [PanelAlpha Contributor License Agreement](CLA.md).

You only need to accept the CLA once.

When you open your first pull request, our CLA check will guide you through the process. Pull requests cannot be merged until the CLA has been accepted.

### Why CLA?

We are a company building commercial products on top of the PanelAlpha Engine and we want to be clear about it. Our CLA confirms that:
- you have the right to submit your contribution;
- you retain ownership of your contribution;
- you grant PanelAlpha the rights needed to use, modify, distribute and sublicense it;
- your contribution may be used as part of both open-source and commercial PanelAlpha products.

**Will your code get closed?** No. The Engine is Apache 2.0 and your merged contribution stays Apache 2.0. The CLA lets us also ship it in paid products, which is something Apache 2.0 already lets anyone do, including you.


## Opening a pull request

- Give it a clear title and describe **what** changed and **why**.
- Link the related issue.
- Say how you verified it. For engine changes, **report the measurements** (see [`AGENTS.md` §3](AGENTS.md)): a status code alone is not a result.
- Keep the pull request description up to date if behaviour changes during review.

We'll review as soon as we can. Expect a bit of back-and-forth; it's how we keep the engine dependable for the thousands of sites it runs.


## Project layout

This repository is the engine. A few landmarks:

| Path | What lives here |
|---|---|
| [`core/`](core/) | The Laravel application, the engine's brains (PHP 8.3). |
| [`docs/`](docs/README.md) | Operator documentation (install → deploy → run). |
| [`scripts/`](scripts/) | Host scripts, benchmarks, and the DinD deploy tester. |
| [`AGENTS.md`](AGENTS.md) | **Contributor conventions and the testing playbook. Read this before changing the engine.** |

For anything beyond a docs typo, **[`AGENTS.md`](AGENTS.md) is the source of truth.** It explains how to verify a change, what numbers to report, and the traps to avoid.


## Before you start

You'll need:

- A Linux environment with **PHP 8.3** and **Composer**.
- **Docker** (the deploy tests run against Docker-in-Docker).
- Git.

Install the core dependencies:

```bash
cd core
composer install
```


## Running the tests

Every change should keep the unit suite green (aside from documented pre-existing failures):

```bash
cd core
./vendor/bin/phpunit --testsuite Unit
```

The current baseline and the two known, unrelated failures are documented in [`AGENTS.md` §1](AGENTS.md). **Prove a failure is pre-existing** (`git stash`, re-run, compare, `git stash pop`) before waving it away.

Changing detection or a recipe? There's a real deploy tester (Docker-in-Docker) and a full battery of measurements in [`AGENTS.md` §2–§6](AGENTS.md). Onboarding a new application? Follow [`AGENTS.md` §11](AGENTS.md).

### End-to-end API tests ([`tests/api/`](tests/api/README.md))

The unit suite above covers the engine's logic in isolation. [`tests/api/`](tests/api/README.md) is a separate **Playwright** suite (TypeScript, Node ≥ 20) that drives a **live engine** over its REST API and MCP endpoint, creating real projects, domains, databases and deploys and asserting on the responses.

It needs an engine to point at. On the engine host itself `npm test` invents the host and mints a token with `pae-artisan` and writes `env/.env` — no prompt. From a laptop it asks which engine, then mints over SSH:

```bash
cd tests/api
npm install
npm test          # first run sets up env, then runs the unit + API specs
```

Handy variants (anything after `--` is passed straight to `playwright test`):

| Command | What it runs |
|---|---|
| `npm run test:unit` | Pure logic against stub transports: **no engine required, runs offline** |
| `npm run test:smoke` | Only `@smoke` specs: the critical path |
| `npm test -- --grep "suspend"` | A single test by name |
| `npm run test:deploy` | Git-deploy specs against a shared Docker-in-Docker project |
| `npm run test:cli` | `pae-artisan` operator commands on the host |
| `npm run report` | Serve the HTML report (a failing run keeps a trace) |

Before pushing changes to this suite, run `npm run check` (typecheck + lint + format). Full setup, conventions (fixtures, cleanup, no `waitForTimeout`), the project list, and how DNS uses panelalpha.direct / panelalpha.online are all in its own [`tests/api/README.md`](tests/api/README.md). Read it before adding a spec.


## Questions?

Stuck, or want to talk an idea through before building it?

- **[Join our Discord](https://discord.gg/9twHWR7xGX)**
- **[Community forum](https://community.panelalpha.com/)**

Thanks again for contributing. 💙
