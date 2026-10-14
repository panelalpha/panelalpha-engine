# Engine tests

Agents asked to test the engine start here: which tests to run, in what order, what not to touch, and what to report.

Three layers, cheapest first. Run them in this order and stop at the first red layer: a unit failure makes a live run meaningless.

| Layer | Where | Needs | Time |
|---|---|---|---|
| 1. PHPUnit | `tests/unit/`, run from `core/` | PHP 8.4, Composer | minutes |
| 2. API + MCP (Playwright) | `tests/api/` | Bun 1.4.2, Node 20.19+, a live engine you may change | about 36 min, more with app deploys |
| 3. Docs scenarios over MCP | `docs/` | an assistant connected to a live engine | per scenario |

How to run each suite is in its README: [`unit/README.md`](unit/README.md), [`api/README.md`](api/README.md), and the API suite's options in [`api/docs/maintainers.md`](api/docs/maintainers.md). Deploy timing, caches and onboarding an application are in the root [`AGENTS.md`](../AGENTS.md) (§1, §1a, §2 to §4, §11). This file adds what an agent must decide and must not get wrong.

## Before anything: data safety

The canonical rules are `CODING_STANDARDS.md#remote-data` in the workspace. In short:

- Never wipe, truncate or drop data on a remote engine unless the user ordered that exact action.
- Before anything that can touch a remote database, take a timestamped dump, confirm it is not empty, and tell the user its path. If you cannot, stop and ask.
- You own what you run. "The test did it" is not an excuse.

## 1. PHPUnit (`tests/unit/`)

The sources are in `tests/unit/` (`Unit/`, `Feature/`, `Support/`), but `phpunit.xml`, `composer.json` and `vendor/` stay in `core/`, so run from there. The checkout has no `vendor/`:

```bash
cd core
composer install                                              # or: php composer.phar install
./vendor/bin/phpunit --testsuite Unit                         # the default gate
./vendor/bin/phpunit --testsuite Feature
./vendor/bin/phpunit ../tests/unit/Unit/Mcp                   # one folder
./vendor/bin/phpunit ../tests/unit/Unit/Mcp/ToolNamesTest.php # one file
./vendor/bin/phpunit --filter Platform                        # by name
```

Do not confuse it with `tests/api/tests/unit`: that is the TypeScript `unit` project of the API suite.

**Database trap.** `phpunit.xml` does not force sqlite: those lines are commented out. Tests that need a database use `tests/unit/Support/InMemoryDatabase.php`, which switches to sqlite `:memory:` at runtime. That is safe on your own machine. Do not run PHPUnit inside a `core` container on a remote engine: its environment sets `DB_CONNECTION` itself. If you must, print the connection the run will use first, and take a backup.

**Host-command trap.** `scripts/tools/no-real-host-commands.sh` runs PHPUnit with shims for `sudo`, `docker`, `nsenter`, `systemctl`, `useradd` and `userdel`, and reports any test that reaches one. Use it for changes under `tests/unit/Unit/System` or `tests/unit/Unit/Deploy` (its default scope).

**Guards worth knowing:**

- `tests/unit/Unit/Mcp/ToolNamesTest`: every API operation has a name in `core/app/Mcp/tool-names.php`, and the names follow `<resource>_<action>`.
- `tests/unit/Unit/Mcp/GeneratedToolsDriftTest`: the committed tools in `core/app/Mcp/Tools/Api/` match what the generator produces. On drift, run `php artisan l5-swagger:generate && php artisan mcp:tool:generate` and commit the result.
- Detection and recipe changes: run `--filter Platform`, then the Unit suite. `tests/unit/Unit/Deploy/Platform/ShippedManifestsTest` catches duplicate or misordered manifest priorities.

**A failure is pre-existing only when proven:** `git stash`, re-run the same tests, see the same failure, `git stash pop`. Never write "probably pre-existing".

## 2. API + MCP suite (`tests/api/`)

It creates real projects, domains, databases and deploys on a live engine. **Run it only on an engine you are allowed to change.** Install with `bun install`.

**Which engine.** The first run creates `env/.env` itself: on the engine host without questions, from a laptop by asking and minting a token over SSH. To choose the engine, run `bun run env:setup -- --host <host>`, which checks the connection before it writes. Do not edit `env/.env` by hand. An `env/.env` that already exists is used as it is, without a check, so before a run print its `API_BASE_URL` (and the `TEST_ENV` overlay's) and confirm it is the engine you mean. To point it elsewhere: `env:setup -- --host <host> --force`, or a second profile with `--profile <name>` and `TEST_ENV=<name>`.

**Run.**

```bash
bun run test                                         # unit + the whole API, about 36 min, no app deploys
bun run test -- tests/mcp                            # one folder; anything after -- goes to Playwright
bun run test -- --grep "suspend"                     # by name
bun run test:supported-apps                          # the application deploys, only when asked or when deploy/detection code changed
SUPPORTED_APPS=koel,ntfy bun run test:supported-apps # only these apps
```

Use `bun run test`, not `bun test`: `bun test` starts Bun's own test runner, not this suite.

**Groups that change the engine for everyone on it** are never in `bun run test`: `slow` (firewall off and on, every PHP version, Engine API access for a project, which changes the tenant firewall and issues tokens), `cli`, `webserver-change`, `update`, `engine-cert`, `network-mutation`. Run one only when the user names it, and say in the result that you did. Leave `ALLOW_BUG_REPORT_SHIP` unset: it files a real bug report to PanelAlpha.

**Engine host access.** Some specs in `bun run test` run `pae` or read `sites-db` and account containers on the engine host: locally when the suite runs there, otherwise over SSH (`ENGINE_SSH_HOST`, default the `API_BASE_URL` host; `ENGINE_SSH_USER`, default `root`; `ENGINE_SSH_KEY`). Without that access they skip with an expected reason. Say in the result that they did not run.

**DinD tests.** `bun run test` does not create the shared DinD project. Tests on it (deploy log and deploy history, a DinD database rename) skip with "No DinD setup state" until you run `bunx playwright test --project=setup-dind` once. Run it before the suite when the change touches deploys or DinD projects.

**MCP.** `tests/mcp/` compares `tools/list` with `core/app/Mcp/tool-names.php` and calls every read-only tool. After any change to `tool-names.php` or `core/app/Mcp/`, run `bun run test -- tests/mcp`.

**Supported apps.** 42 applications in `test-data/static/supported-apps.ts`. A deploy passes when the inspection names a branch and a strategy, the deploy ends `success` or `partial`, the health check reads `serving: ok`, and the address answers 200, 301 or 302 (also 303, 307, 308, 401 or 403 when `serving` is `ok`).

**After the run.**

- Read `.playwright/summary.md`: the cause of each failure, the skips, and the leak-check result on the last line. Quote it.
- Skips with a reason outside `config/expected-skips.ts` are reported as unexpected: treat them as failures. Quote the skip count next to the pass count. A green run with many skips is not a pass.
- Projects of failed tests are deleted. `PRESERVE_ON_FAILURE=1` keeps them for inspection; delete them before you finish.
- `LEAK_CHECK=1` makes the run fail if it left anything on the engine. Use it when the engine is shared. `bun run leak-check` runs the check alone.
- `bun run report` serves the HTML report (port 9323) with traces of failures. The token is redacted.
- Changed the suite itself? `bun run check` before you hand it over.

## 3. Docs scenarios over MCP

The operator docs end several pages with `## More requests to try` (and `## Real applications to try` on `docs/07-supported-projects/project-types.md`). Find the pages. If the command finds none, this branch's docs have no scenarios yet: skip layer 3 and say so.

```bash
grep -rl "## More requests to try\|## Real applications to try" docs
```

Each entry in those sections is a bold title, the request in a `text` block, and one line `Mode: … Tools: … Check: …`:

- **request**: the chat message a person would send.
- **Mode**: `read`, `modify` or `full`, the lowest permission it needs. Where an entry has no Mode line, the section's opening line gives it (on `visitor-statistics.md`, all are `read`).
- **Tools**: the MCP tools it should lead to. On the real-applications page, a shared line above the entries lists them, and an entry may add "Extra tools".
- **Check**: what must be true afterwards. The real-applications page also lists four pass conditions above its table that apply to every deploy there.

How to run them:

1. Connect the assistant under test to the engine (`pae connect` on the engine host prints the line). Set `MCP_PERMISSION_MODE` to `readonly` (scenario mode `read`), `modify` or `full`: the highest you will run, and no higher. The engine default is `full`.
2. Order: `read` first, then `modify`, then `full`. A `full` scenario deletes something: back up first, and run it only on an engine you may change.
3. Replace the example values before sending: `shop.example.com` must be a domain whose DNS points at the engine, `203.0.113.x` are documentation addresses, and project names starting with `t` (`tshop`, `tmemos`, ...) are the ones the scenarios create. Create `tshop` first if a scenario assumes it.
4. Send the request as the user. Never put a token, password or key in it; a scenario that needs one expects the assistant to send a paste page.
5. Record the tools the assistant actually called (including tools reached through `search_tools` and `execute_tools`) and the final answer.
6. Score against **Check**. The **Tools** list is a guide: the same result through other tools is a pass with a note. A scenario whose prerequisite is missing (no DNS, no Cloudflare account, no traffic) is **skipped** with the reason, not failed.
7. Delete every project the run created. The real-applications page ends with a clean-up request for its projects.

### Requests in the body of the pages

The pages also carry chat requests in their body, in `text` blocks above those sections. These are what readers paste, so run them too, after the scenarios above. Pages: `docs/02-getting-started/`, `docs/05-capabilities/`, `docs/07-supported-projects/`, and `docs/README.md`.

They have no Mode, Tools or Check line, so before you send each one:

1. **Give it a mode** from what it asks: `read` when it only looks or lists, `modify` when it creates or changes, `full` when it deletes, restores, removes or overwrites ("Delete this project.", "Restore last backup onto this project."). Run them in the order read → modify → full, under the same rules as above.
2. **Fill in the placeholders.** "This project" becomes `tshop`. `https://github.com/org/app` becomes an application from the table on `07-supported-projects/project-types.md`. `shop.example.com` is a domain whose DNS points at the engine. `<value>` gets a harmless test value. `/path/to/app.zip` is a small zip you made for the run. A `<password>` or a token never goes into the chat: the assistant must offer a paste page, or you skip the request.
3. **Take the expected result from the page.** Use the request itself and the text next to it: "What you should see", the sentence that describes the result, or a warning such as "There is no undo". Quote that sentence in the result.
4. **Score it.** Pass when the assistant did what the request asks and the result matches the page. Pass with a note when the page names no result and the assistant did what was asked. Fail when the result contradicts the page; report it as a docs or engine bug, not both. Skip, with the reason, when a prerequisite is missing.
5. A body request that repeats a scenario above already ran: mark it as covered by that scenario and do not run it twice.

## What a result contains

- Which layers ran, against which engine (`API_BASE_URL` or host), and on which commit.
- Per layer: passed, failed, skipped, with the skip reasons.
- Each failure with the test or scenario name, the error, and whether it is proven pre-existing (stash check for PHPUnit; same failure on the base commit for the others).
- Anything the run changed on the engine and did not restore, and projects left behind.
- Groups you deliberately did not run, and why.
