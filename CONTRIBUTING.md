# Contributing to PanelAlpha Engine

First off, thank you for taking the time to contribute! 🙌
Whether it's a bug report, a docs fix, a new supported project type, or a core change, it's welcome.

This guide gets a person from a clean checkout to a pull request that's easy to review.

---

## Documentation

Operator documentation lives in [`docs/`](docs/README.md). It is written for the person who installs a host and deploys apps: install, connect an assistant, run a project, supported applications. If an operator would notice the change, it belongs there.

This file is for a person sending a pull request.

Coding agents changing the engine follow [`AGENTS.md`](AGENTS.md): how to verify a change, which numbers to report, and the traps. That file does not replace [`docs/`](docs/README.md).

> 🔒 **Found a security issue?** Do **not** open a public issue. Follow [`SECURITY.md`](SECURITY.md) and report it privately.


## Ways to contribute

- **Report a bug.** Open an issue with clear steps to reproduce, the engine version, and the host OS.
- **Suggest an idea.** Open an issue describing the problem you want solved, not just the solution.
- **Improve the docs.** Edit [`docs/`](docs/README.md). Small fixes are very welcome.
- **Add support for an application.** What an operator needs is in [`docs/07-supported-projects/`](docs/07-supported-projects/how-detection-works.md). Coding agents follow the onboarding playbook in [`AGENTS.md`](AGENTS.md) (§11).
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

---

## Project layout

This repository is the engine. A few landmarks:

| Path | What lives here |
|---|---|
| [`core/`](core/) | The Laravel application, the engine's brains (PHP 8.4). |
| [`docs/`](docs/README.md) | Operator documentation (install → deploy → run). |
| [`scripts/`](scripts/) | Host scripts, benchmarks, and the DinD deploy tester. |
| [`tests/api/`](tests/api/README.md) | Playwright tests against a live engine. |
| [`AGENTS.md`](AGENTS.md) | Instructions for coding agents. People use this file. |


## Before you start

You'll need:

- A Linux environment with **PHP 8.4** and **Composer**.
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

If a failure looks unrelated to your change, prove it: `git stash`, re-run, compare, `git stash pop`. Coding agents: the current baseline is in [`AGENTS.md` §1](AGENTS.md).

Changing detection, a recipe, or onboarding an application? Coding agents follow [`AGENTS.md`](AGENTS.md) — §2–§6 for the deploy tester, §11 for a new application.

### End-to-end API tests ([`tests/api/`](tests/api/README.md))

The unit suite above covers the engine's logic in isolation. [`tests/api/`](tests/api/README.md) is a separate **Playwright** suite (TypeScript, Node ≥ 20) that drives a **live engine** over its REST API and MCP endpoint, creating real projects, domains, databases and deploys and asserting on the responses.

It needs an engine to point at. On the engine host itself `npm test` invents the host and mints a token with `pae-artisan` and writes `env/.env` — no prompt. From a laptop it asks which engine, then mints over SSH:

```bash
cd tests/api
npm install
npm test          # first run sets up env, then runs unit, API, and supported-app deploys
```

Handy variants (anything after `--` is passed straight to `playwright test`):

| Command | What it runs |
|---|---|
| `npm run test:unit` | Pure logic against stub transports: **no engine required, runs offline** |
| `npm run test:smoke` | Only `@smoke` specs: the critical path |
| `npm test -- --grep "suspend"` | A single test by name |
| `npm run test:supported-apps` | Real application deploys, plus the once-each deploy checks |
| `npm run test:cli` | `pae-artisan` operator commands on the host |
| `npm run report` | Serve the HTML report (a failing run keeps a trace) |

Before pushing changes to this suite, run `npm run check` (typecheck + lint + format). How to run the suite, and which group does what, is in [`tests/api/README.md`](tests/api/README.md).

---

## Making your change

1. **Branch** off the default branch with a descriptive name (e.g. `fix/archive-deploy-port`, `feat/detect-deno`).
2. **Keep it focused.** Small, single-purpose pull requests are far easier to review and land faster.
3. **Write a test** where it makes sense, and run the suite.
4. **Update [`docs/`](docs/README.md)** if an operator would notice the change. Coding agents update [`AGENTS.md`](AGENTS.md) when the way to verify that change changes.

### Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add Deno detection to the strategy chain
fix: re-render proxy vhosts after archive deploy
docs: clarify MCP token creation
chore: bump dev dependencies
```

Common types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`. Reference an issue where relevant (e.g. `#123`).

---

## Contributor License Agreement

Before we can accept your first contribution, you must agree to the [PanelAlpha Contributor License Agreement](CLA.md).

The CLA confirms that:

- you have the right to submit your contribution;
- you retain ownership of your contribution;
- you grant PanelAlpha the rights needed to use, modify, distribute and sublicense it;
- your contribution may be used as part of both open-source and commercial PanelAlpha products.

You only need to accept the CLA once.

When you open your first pull request, our CLA check will guide you through the process. Pull requests cannot be merged until the CLA has been accepted.

---

## Opening a pull request

- Give it a clear title and describe **what** changed and **why**.
- Link the related issue.
- Say how you verified it. Coding agents report the measurements in [`AGENTS.md` §3](AGENTS.md): a status code alone is not a result.
- Keep the pull request description up to date if behaviour changes during review.

We'll review as soon as we can. Expect a bit of back-and-forth; it's how we keep the engine dependable for the thousands of sites it runs.

---

## Questions?

Stuck, or want to talk an idea through before building it?

- **[Join our Discord](https://discord.gg/9twHWR7xGX)**
- **[Community forum](https://community.panelalpha.com/)**

Thanks again for contributing. 💙
