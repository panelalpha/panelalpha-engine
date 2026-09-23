# AGENTS.md

Coding agents changing this repository start here. [`CONTRIBUTING.md`](CONTRIBUTING.md) sends you here on purpose.

People opening a pull request follow [`CONTRIBUTING.md`](CONTRIBUTING.md). Operators who install a host and deploy apps follow [`docs/`](docs/README.md). Link that page, then add only what an agent needs to change the code: how to verify, what numbers to report, and the traps. Do not copy an operator procedure into this file.

`docs/` does not link here.

The rule throughout: every claim is a measurement, and every measurement names
the stage it belongs to. "Deploy took 100s" is not a result. "npm ci 16.9s,
apt-get 11.9s, build 2.2s, inside a 53.1s critical path" is.

| Topic | Baseline (operator) | This file |
|---|---|---|
| Install, tokens, TLS | [`docs/02-getting-started/`](docs/02-getting-started/install.md) | `--in-container` for tests; installer prints `pae-artisan` |
| MCP | [`docs/04-connecting-your-ai/`](docs/04-connecting-your-ai/your-assistant.md) | CatalogueTest; do not invent client UIs |
| Detection / stacks | [`docs/07-supported-projects/`](docs/07-supported-projects/how-detection-works.md) | §6a Railpack measurements; §11 onboarding an app |
| Deploy failures | [`docs/02-getting-started/what-happens.md`](docs/02-getting-started/what-happens.md) | Explainer rules vs DinD proof |
| Telemetry | [`docs/02-getting-started/what-is-collected.md`](docs/02-getting-started/what-is-collected.md) | Field list when changing `DeployReport` |
| Pipeline speed / caches | (none — operator does not measure this) | §1–§10 below |
| REST + MCP behaviour | [`tests/api/README.md`](tests/api/README.md) | §1a: what it covers, what a green run does and does not prove |

> **Adding support for a third-party application?** §11 in this file is the
> playbook: detect without paying for a deploy, when an app needs a manifest,
> the failures that actually come up, and what counts as proof. Sections 1–10
> verify the *pipeline*; §11 onboards an *application*.

---

## 1. Unit tests

```bash
cd core
./vendor/bin/phpunit --testsuite Unit
```

**Current baseline: 2140 tests, 7986 assertions, 2 failures.**

Those 2 failures are pre-existing and unrelated to Deploy:

| Test | Cause |
|---|---|
| `ChangeWebserverSystemTest::test_webserver_script_parse_args_handles_php_argument_order` | `scripts/webserver-parse-args.test.sh` is missing from the repo |
| `UserIpAddressesTest::test_get_bind_ip_addresses_filters_non_local_default_ipv4` | depends on the host's default IPv4 route |

Report them as pre-existing **only after proving it** — `git stash`, re-run,
confirm the same 2 fail on the baseline, `git stash pop`. Never wave a failure
away as "probably pre-existing".

A recipe change must also keep detection stable. The fastest proof is
differential: extract the pre-change classes into a parallel namespace, run both
over the same fixtures, and diff every field of the returned decision.

---

## 1a. API tests (Playwright, `tests/api/`)

The unit suite above covers the engine's logic in isolation. `tests/api/` drives
a **live engine** over its REST API and its MCP endpoint: it creates real
projects, domains, databases and deploys, and asserts on what comes back.
How to run the suite, and which group does what, is in
[`tests/api/README.md`](tests/api/README.md).

```bash
cd tests/api
npm install
npm test                  # unit, the API, and the supported-app deploys
npm run test:unit         # pure logic, no engine
npm run check             # typecheck + lint + format — run before pushing
```

Three things an agent gets wrong here:

**A green run is not full coverage.** A lot of the suite skips for legitimate
environmental reasons (CSF not installed, IP management absent, `pae-artisan`
unreachable). Every run now prints what it skipped and why; read that summary
before reporting a result, and quote the skip count alongside the pass count.
`MAX_SKIPPED=<n>` turns the budget into a gate.

**MCP is tested separately from REST, on purpose.** `tools/call` answers HTTP
200 with `result.isError: true` when the tool itself fails, so REST specs
passing says nothing about the MCP surface on top of them. `tests/mcp/` compares
`tools/list` against `core/app/Mcp/tool-names.php` and calls every read-only
tool. **Changing `tool-names.php` or anything under `core/app/Mcp/` means
running `tests/mcp/`.**

**Only the engine host runs the whole thing.** The `cli`,
`webserver-change`, `update`, `engine-cert` and `network-mutation` projects
reconfigure the host, and are excluded from `npm test`. Naming them in a
result means having run them explicitly. The supported-app deploys are part
of `npm test`.

---

## 2. Deploy tests (DinD)

```bash
php scripts/dind-test/deploy.php <git-url|local-path> [flags]
```

Runs the **real** engine code (`Dind`, `DetectProjectStrategy`,
`FrameworkDockerfile`, `HostCompile`) against a real Docker-in-Docker account.
`TestSystem` only strips `sudo`, neutralises `chown`, and redirects host paths.

### Flags that change what you are measuring

| Flag | Effect | When you need it |
|---|---|---|
| `--real-home` | Account at `/home/<container>` instead of the disk cache dir | **Required** for nginx-static and Nitro-standalone recipes. `HostNodeBuild::isSafeProjectDir()` and `Dind::emptyProjectDir()` accept only `/home/<user>/project`; anywhere else the host compile throws `Refusing host Node build outside ~/project` and that whole code path goes untested |
| `--reuse-container` | Redeploy into the running container | **Required for any warm measurement** — see §4 |
| `--keep-cache` | Keep the host build cache (node_modules/npm/pnpm/yarn/bun) | Measuring host-compile reuse |
| `--name=NAME` | Account name (container is `dind-test-NAME`) | Reusing one prepared `/home` dir for many apps |
| `--timeout=N` | Seconds to wait for the app | Slow builds |

Two traps worth knowing before you stage anything: a **cold run wipes the
account directory**, and a **local path is cloned with git, so it deploys
`HEAD`, not your working tree**. Both are covered, with the rest of the
onboarding loop, in §11.

The tester removes **only its own container**, by name (`docker rm -f
dind-test-NAME`). It used to `docker container prune -f`, which is unscoped and
on a host with real hosting accounts deletes every stopped container — including
the shared cache registry. If you are reading an older checkout, check that line
before running it anywhere that matters.

`--real-home` needs the directory to exist and be yours (creating it under
`/home` needs root, once per account name):

```bash
sudo mkdir -p /home/dind-test-NAME && sudo chown $(id -u):$(id -g) /home/dind-test-NAME
```

---

## 3. What the tester reports, and what to copy into a result

Every run ends with a summary. **Report all of it** — strategy, railpack,
mode, build breakdown, phases:

```
  Strategy:  NestJS (nestjs)
  Railpack:  no
  Mode:      cold (fresh container)
  Build:     9 layer(s), 0 cached, 32.9s spent building
  Time:      103.8s total (create 0.8s, daemon-wait 1.2s, daemon-settle (test-only) 2s,
             seed (test-only) 42s, clone/detect 0.3s, start 50.8s, settle (test-only) 5s,
             http-check (test-only) 0.3s, unaccounted 1.4s)
  Production critical path: ~53.1s

  Build steps (inside the account's Docker, slowest first):
      16.9s  RUN HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund
      11.9s  RUN apt-get update && apt-get install -y --no-install-recommends git && ...
       2.2s  RUN npm run build
       0.5s  RUN node -e '...strip git-hook scripts...'
       0.3s  COPY . .
```

### The phases

| Phase | Real deploy cost? | What it is |
|---|---|---|
| `create` | **yes** | Render the account's compose file and `docker compose up` the DinD container |
| `daemon-wait` | **yes** | Inner dockerd becoming reachable |
| `daemon-settle` | no — test only | Fixed 2s margin for a loaded host |
| `seed` | no — **backgrounded in production** | Copies the `ImageCatalog` images into the inner daemon. ~40s and it dominates wall time; production uses `InnerDocker::seedBaseImagesInBackground()`, which does not block a deploy |
| `clone/detect` | **yes** | Fetch/copy the source, run `DetectProjectStrategy`, write the deploy files |
| `start` | **yes** | Host compile (nginx/Nitro only) + image preload + `compose up` incl. the image build |
| `settle`, `http-check` | no — test only | This script's own verification |

**Production critical path = create + daemon-wait + clone/detect + start.**
Quote that number, not the wall time — the wall time is dominated by seeding
that a real deploy never waits for.

The `Build steps` table breaks `start` down per layer, which is where
`composer install`, `npm ci`, `bundle install` and the framework build become
visible individually. That is the level to optimise at.

---

## 4. Run the battery: global seed once, then every app

```bash
# Phase 0 — GLOBAL SEED. One account, 11 base images. Paid ONCE.
php scripts/dind-test/deploy.php <any-app> --real-home --name=NAME --seed-only

# Then every app deploys into that seeded account:
php scripts/dind-test/deploy.php <app> --real-home --name=NAME --reuse-container            # COLD
php scripts/dind-test/deploy.php <app> --real-home --name=NAME --reuse-container --keep-cache # WARM
```

**Global seed is not a per-deploy cost.** It imports the `ImageCatalog` images
into the account's inner daemon (~45-70s for 11 images). Production does it in
the background at account creation (`InnerDocker::seedBaseImagesInBackground()`),
so no deploy ever waits on it. Measure it once, report it once, and keep it out
of every app's number.

### Two rules that make shared-account results valid

**Tear down between apps.** All apps in one account build the same
`project-app:latest` under the same compose project. Without a teardown, app N
silently *runs app N-1's image* and reports a meaningless HTTP 200 in ~5s. An
earlier run of this battery had go-beszel "passing" while serving NestJS.

```bash
docker exec $C docker compose -f $P/docker-compose.panelalpha.yml down --rmi local -v --remove-orphans
docker exec $C docker rmi -f project-app:latest
```

This removes the image but **not** BuildKit's layer cache, which is what warm
is supposed to measure.

**Host-compile recipes need a fresh account, not a reused one.** For the
nginx-static and Nitro-standalone recipes the build product lives in the
*account* (`~/project/dist`, `~/project/.output`, and the host build cache), not
in a Docker layer. Reusing an account across different apps overlays one app's
`node_modules` onto the next and npm dies with
`Cannot read properties of null (reading 'edgesOut')`. Test `vite`, `cra`,
`angular`, `astro` and `nuxt` **without** `--reuse-container`.

---

## 5. The three caches

Say which one you are measuring.

**1. Shared registry cache** (`panelalpha-cache-registry:5000`) — 7 pre-warmed
stack tags (`railpack-node-20/22`, `python-3.11/3.12`, `ruby-3.3.6/3.4.1`,
`go-1.22`). Every recipe build imports these manifests. Tenant builds are
**read-only** against it by design: `BuildCache::tenantFlags()` documents
that `--cache-to` would export layers containing customer source into a
`registry:2` with no per-repository ACL, and that this was *observed in
practice* — an app's `app.rb` and `Gemfile` retrievable from an unrelated
account. Only synthetic warm projects write those tags. **A fresh account
showing 0 cached app layers is correct, not a bug.**

**2. The account's own BuildKit cache** — inside the account's DinD container.
This is production's warm path.

**3. Host build cache** (`/var/cache/panelalpha/js/<user>`) — node_modules and
package-manager caches for host compiles.

> **The trap:** the tester recreates the container by default, destroying cache
> #2, so running it twice measures cold twice. An early battery reported
> "warm 103s vs cold 110s" and nearly concluded caching does not help. Use
> `--reuse-container`.

---

## 6. Measured results

Global seed: **51s** for 11 images, once for the whole table below.

| App | Strategy | Railpack | Cold crit | Warm crit | Rebuild | Slowest layer | HTTP |
|---|---|---|---|---|---|---|---|
| nestjs | NestJS | no | 47.2s | **1.6s** | 1s (8 cached) | `npm ci` 14.0s, `apt-get git` 11.5s | 200 |
| nextjs | Next.js | no | 79.0s | **1.8s** | 1s (9 cached) | `pnpm install` 27.5s, `pnpm run build` 14.1s | 200 |
| java-petclinic | Java (Maven) | no | 124.3s | **2.0s** | 2s (3 cached) | `mvn -B -q -DskipTests package` **106.7s** | 200 |
| laravel | Laravel | no | 60.4s | 17.8s | 2s (8 cached) | `composer install --no-dev` **13.9s** | 200 |
| railpack-probe | **Railpack** | **yes** | 33.2s | 9.0s | — | — | 200 |
| django | Dockerfile (repo's) | no | 53.5s | 2.0s | 3s (6 cached) | `FROM uv:python3.12` 27.5s | 400¹ |
| remix | Dockerfile (repo's) | no | 250.0s | 12.9s | 5s (18 cached) | `npm install --include=dev` **168.9s** | 000² |
| rust-axum | Rust | no | 64.5s | 31.3s | 2s (4 cached) | `cargo build --release` 13.4s | —³ |
| vite-react | Vite | no | 11.9s⁴ | — | — | host compile (not a layer) | 200 |
| astro | Compose (repo's) | no | 6.3s | — | — | — | 000² |

¹ Django's own `ALLOWED_HOSTS` rejects the container hostname; app and Postgres both up.
² The repo's own Dockerfile/compose fails, not a recipe.
³ Library workspace, no binary — the guard fires correctly.
⁴ Fresh account (host-compile recipe; see §4).

Known-failing, all upstream defects rather than engine faults — say which:
angular-realworld cannot resolve its own `realworld/assets/theme/styles.css`;
sveltejs/realworld ships a malformed `pnpm-lock.yaml`
(`ERR_PNPM_BROKEN_LOCKFILE`); beszel's `//go:embed all:dist` needs its JS
frontend built first; node-express-boilerplate's Dockerfile uses `node:alpine`,
which no longer ships yarn; the nuxt starter dies in `npm install` with
`Cannot read properties of null (reading 'edgesOut')` — **reproduced with the
engine changes stashed**, so not ours.

When you claim a failure is pre-existing, prove it the same way: stash, re-run,
compare, restore.

---

## 6a. Railpack

> Baseline: [`docs/07-supported-projects/how-detection-works.md`](docs/07-supported-projects/how-detection-works.md)
> (order, Deno/Elixir gap). This section is measurements and the cache path.

Railpack is the catch-all just before `static`/`fallback`, so it is reached only
when nothing earlier claims the repo. It is gated by
`DetectProjectStrategy::RAILPACK_MANIFESTS`, which today lists
`package.json`, `composer.json`, `go.mod`, `Cargo.toml`, `requirements.txt`,
`pyproject.toml`, `Gemfile`, `pom.xml`, `build.gradle[.kts]`.

**Gap worth knowing:** Railpack itself supports Deno and Elixir, but neither
`deno.json` nor `mix.exs` is in that list, so those projects land on `fallback`
and are never offered to Railpack. Verified: `denoland/fresh`, `oakserver/oak`
and `denoland/deno_std` all detect as `fallback`.

### Real repos that do reach it

Anything with a `package.json` whose framework we have no recipe for — Koa,
hapi, Restify, Feathers, or a plain Node server:

| Repo | Cold crit | Warm crit | Image built | HTTP |
|---|---|---|---|---|
| `feathers-chat/quick-start` (real Feathers app) | 59.3s | **13.5s** | yes | **200** |
| bare Node server (fixture) | 33.9s | **11.0s** | yes | **200** |
| `expressjs/express` | 96.8s | 39.1s | yes | — ¹ |
| `hapijs/hapi` | 93.5s | 38.9s | yes | — ¹ |

¹ Library repos: Railpack builds the image fine, but there is no server and no
`start` script, so nothing listens. Build success is the signal here, not HTTP.

A repo with dependencies but **no `start` script and no server** (`koajs/examples`,
`lodash/lodash`) makes the Railpack build fail, and the chain falls back to
`fallback`/static and still serves — degradation is graceful, and the recorded
strategy becomes `fallback`, not `railpack`. Do not read that as "railpack was
never tried": it was, and `DeployStrategy::…` logged the fallback.

### Does Railpack use our host cache? Yes — read-only

`DeployStrategy.php:688` builds the buildx command with
`BuildCache::tenantFlags()`, which emits one
`--cache-from type=registry,ref=panelalpha-cache-registry:5000/panelalpha-cache:railpack-<stack>`
per stack and an empty `--cache-to`.

The deploy log will **not** show this: the Railpack build runs through
`Shell::execAsUser()` and its buildx progress never reaches the tester's stdout.
Prove it from the registry's own access log instead:

```bash
before=$(docker logs panelalpha-cache-registry 2>&1 | wc -l)
# ... run the deploy ...
docker logs panelalpha-cache-registry 2>&1 | tail -n +$((before+1)) > reg.log
grep -oP 'railpack-[a-z]+-?[0-9.]*' reg.log | sort -u     # which stack tags were read
grep -oP '"(GET|HEAD|PUT|POST|PATCH) ' reg.log | sort | uniq -c
```

Measured across the four Railpack deploys above:

- every build read **all seven** stack tags — `railpack-go-1.22`,
  `railpack-node-20`, `railpack-node-22`, `railpack-python-3.11`,
  `railpack-python-3.12`, `railpack-ruby-3.3.6`, `railpack-ruby-3.4.1`
- cold: 50-64 registry requests, 22-36 blob fetches
- warm: 42 requests, 14 blobs — fewer, because BuildKit already holds them
- **142 GET + 56 HEAD, and 0 PUT/POST/PATCH.** Not one write. That is
  `tenantFlags()`'s empty `--cache-to` doing its job: a tenant build must
  never export layers holding customer source into a registry every other
  account can read.

If a Railpack timing looks suspiciously slow, check `lookUpCacheRegistry()`
resolved at all — it returns null when `docker inspect panelalpha-cache-registry`
fails on the host, and the build then runs correctly but with no cache.

---

## 6b. Cache usage — what exists, and how to count it

**The engine tracks nothing.** There are no hit counters, no per-tag metrics, no
cache statistics anywhere in `app/`. The only cache signal in the whole codebase
is a shell `echo "node_modules cache hit"` inside the host-compile script
(`HostNodeBuild::innerScript()`), emitted when `node_modules/.pa-lock` still
matches the project's lockfile — and nothing counts or stores it. So "how often
was this cache used" cannot be answered from the product today.

It *can* be measured from the cache registry's access log, which is what
`scripts/dind-test/cache-usage.php` does:

```bash
MARK=$(php scripts/dind-test/cache-usage.php --mark)
php scripts/dind-test/deploy.php <app> --real-home --name=NAME
php scripts/dind-test/cache-usage.php --from=$MARK
```

Distinguish the two columns; conflating them is the easy mistake:

- **MANIFEST READS** — the build asked about a tag. Every Railpack build asks
  about *every* stack, because `BuildCache::tenantFlags()` attaches one
  `--cache-from` per stack unconditionally. A read means "considered".
- **BLOB PULLS / MB** — cached layer data actually transferred. This is real
  usage. A tag with reads and ~0 MB did nothing for that build.

One real Railpack deploy (Feathers app, Node):

```
CACHE TAG                  MANIFEST READS   BLOB PULLS         MB   VERDICT
railpack-node-22                        2           24      143.3   USED
railpack-node-20                        2           10        0.1   consulted, no payload
railpack-go-1.22                        1            4        0.0   consulted, no payload
railpack-python-3.11                    2            4        0.0   consulted, no payload
railpack-python-3.12                    2            4        0.0   consulted, no payload
railpack-ruby-3.3.6                     2            2        0.0   consulted, no payload
railpack-ruby-3.4.1                     2            2        0.0   consulted, no payload

Writes during this window: 0 (read-only — correct for a tenant deploy)
```

**One cache of seven does the work — per build.** For a Node project the six
non-Node tags cost a manifest round trip each and deliver nothing. Across eight
Railpack deploys the pattern was exact: every tag read 16 times (8 × GET+HEAD),
while only `railpack-node-22` transferred payload (143.2 MB). That is what
motivated narrowing `--cache-from` (below).

Do **not** read this as "the other six tags are useless". They are unused *by a
Node build*, and Node is all Railpack normally sees because the recipes claim
the other languages first. When a project does fall through to Railpack, those
tags are worth a great deal — a Ruby project pays 28.1s with its tag warmed
against 208.2s without. See "Does the shared cache actually make Railpack
faster?" below for the per-stack A/B.

### Does the shared cache actually make Railpack faster? Yes — measured

A/B on the same fixtures, fresh container every run (so the account's own
BuildKit cache is never what is measured), cache disabled by **renaming** the
registry container so `lookUpCacheRegistry()` resolves null. Rename rather than
stop: `deploy.php` ran `docker container prune -f` at the time, which deletes a
*stopped* registry container outright. It now removes only its own container by
name, so stopping would do — the rename is what these numbers were taken with. Metric is `clone/detect`, because for a Railpack
app the build runs inside `prepareUserAppFromSources()`, not in `start`.

| Stack | cache ON | cache OFF | delta |
|---|---|---|---|
| ruby | **28.1s** (26.0, 30.1) | **208.2s** (249.4, 167.0) | **−180.1s (−87%)** |
| python | 46.6s (46.9, 46.4) | 68.8s (75.9, 61.7) | −22.2s (−32%) |
| node | 35.0s (45.8, 24.2) | 50.0s (47.1, 53.0) | −15.0s (−30%) |
| go | 43.2s (46.7, 39.7) | 44.1s (51.3, 37.0) | −0.9s (−2%) |

The cache works, and the size of the win tracks exactly what `mise install` costs
per language: ruby compiles from source (a 7.4× speedup when cached), python and
node download prebuilt binaries (~30%), go's toolchain is cheap enough that the
cache is noise.

**Reproducing this needs the recipes bypassed.** `RailsDockerfile::isRubyApp()`,
`PythonRecipe` and `GoRecipe` claim `Gemfile` / `requirements.txt` / `go.mod`
before Railpack is reached, so Railpack normally only ever sees Node and the
ruby/python/go tags cannot be exercised at all. Add this at the top of
`DetectProjectStrategy::detect()`, run the benchmark, then remove it:

```php
if (getenv('PANELALPHA_FORCE_RAILPACK') === '1'
    && self::firstExisting($projectDir, self::RAILPACK_MANIFESTS, $files) !== null) {
    return self::result(self::STRATEGY_RAILPACK, 'Railpack', null, null, null);
}
```

**The tension this exposes.** The ruby recipe exists *because* Railpack's ruby
path was slow — its comment cites "82.6s measured against ~2s for an image
load". The cache now brings that same path down to 28.1s. The recipe is still
faster, so bypassing it would be wrong; but it does mean the ruby/python/go warm
tags are insurance for projects the recipes decline, not everyday load. Whether
that insurance is worth ~3.3 GB of registry disk (1.7 GB after shared-layer
dedup) is a product call, and it should be made against these numbers rather
than against the assumption that the tags are simply dead.

### Narrowing `--cache-from` to the stack Railpack picked

`BuildCache::tenantFlags()` now takes an optional stack list.
`DeployStrategy` reads the plan *after* `railpack prepare` has written it,
extracts the resolved runtimes from the mise `[tools]` block
(`RailpackPlan::pinnedTools()`), maps them onto warmed tags
(the shared layer cache this fed is gone; only the base images are preloaded)
flags. Anything it cannot resolve — unreadable plan, unpinned `latest`, a
runtime or version we do not warm — returns null and every tag is read exactly
as before. Guessing a narrower list wrong costs a full rebuild; a spare round
trip costs milliseconds, so the fallback is always the safe direction.

Measured on the same Feathers app, same fixture, before and after:

| | Manifest reads | Tags consulted | `node-22` payload | Crit | HTTP |
|---|---|---|---|---|---|
| before | 13 | all 7 | 143.3 MB | 59.3s | 200 |
| after | **1** | **node-22 only** | **143.3 MB** | 56.9s | 200 |

The payload is byte-identical, so the cache still does exactly as much work —
only the six useless round trips are gone. The decision is logged, so you can
tell which path a deploy took:

```
Railpack cache narrowed for <account>: node-22
Railpack plan for <account> names no warmed runtime; reading every cache tag.
```

Both paths were exercised: `feathers-chat/quick-start` pins to a warmed version
and narrows to `node-22`; `expressjs/express` declares `"node": ">= 18"`, which
resolves to a version we do not warm, so it falls back to all seven — and got
0.1 MB from the cache either way, before and after. **That is worth noting as a
follow-up rather than a win:** a repo pinning an unwarmed runtime gets no cache
benefit at all, narrowing or not. Warming `node-18` would help those repos far
more than this change does.

Writes are expected over the whole log (cache warming writes these tags from
synthetic projects) and must be **zero inside a deploy window**. The script
distinguishes the two: unscoped it reports warming writes as normal, and with
`--from` a non-zero count means a tenant build exported layers into a registry
every other account can read, which `tenantFlags()` exists to prevent.

---

## 7. Host gotchas

- **`/tmp` may be a RAM-backed tmpfs.** It is on these dev boxes (`/etc/fstab`).
  A DinD account keeps its entire inner Docker storage — every seeded image and
  build layer — under its account dir, so a tester rooted in `/tmp` writes
  gigabytes into RAM. Five accounts consumed 29G here and killed a run with
  `no space left on device` while the disk was 75% free. The tester now uses
  `~/.cache/panelalpha-dind-test`; keep it on disk.
- **Sysbox is not the blocker.** `sysbox-runc` is registered and
  `sysbox-{fs,mgr}` run. If you see
  `nsenter: stat /proc/1/ns/user: Permission denied`, that is the production
  topology leaking into the tester: the engine normally runs inside the
  privileged `core` container and reaches the host via
  `sudo nsenter --target 1 --all`. Run natively on the host, that hop is
  redundant and impossible. `TestSystem` strips it — but note
  `HostBuilder::prepareCacheArgv()` bakes the prefix into its argv and
  dispatches through plain `exec()`, so `execOnHost()` overrides never see it.
- **`getopt()` stops at the first non-option argument.** `deploy.php` parses
  argv by hand for this reason; before that fix, `deploy.php <path> --timeout=180`
  silently dropped every flag. If you add a flag elsewhere, do not reach for
  `getopt()`.

---

## 8. Stage timings in the product

The breakdown §8 used to ask for now ships. Every deploy records it and the API
returns it, so "which stage regressed" is answerable without re-running
anything.

**API** — `GET /api/projects/{project}/deploy-log` gained a `timings` block:

```json
"timings": {
  "total_seconds": 168,
  "phases": [
    {"name": "preparing",       "seconds": 2},
    {"name": "cloning",         "seconds": 7},
    {"name": "detect",          "seconds": 0},
    {"name": "image_transfer", "seconds": 39},
    {"name": "build",           "seconds": 95.9},
    {"name": "start_to_answer", "seconds": 22.1}
  ],
  "stages": [
    {"name": "preparing", "started_at": …, "finished_at": …, "seconds": 2},
    {"name": "cloning",   "started_at": …, "finished_at": …, "seconds": 7},
    {"name": "running",   "started_at": …, "finished_at": …, "seconds": 159}
  ],
  "build": {
    "total_seconds": 114.6, "step_count": 11, "cached_steps": 6,
    "cache_hit_ratio": 0.545,
    "slowest": [{"step": "#8", "command": "RUN install-php-extensions imagick",
                 "seconds": 77.5, "cached": false}]
  }
}
```

`phases` is the block to read. The three recorded `stages` are kept because the
API has always returned them, but `running` is one 159-second blob covering
detection, base images, the build and the app booting — four things with four
different fixes. `phases` splits it using milestones the pipeline already logs
(`Detected project type`, `Loaded base image`, `Starting application`,
`Health check … answered`). See "Reading a run" in §9 for what each means.

Stage durations come from `latest.json` and are always present. The build
breakdown re-reads the whole log — hundreds of kilobytes on a real build — so it
is computed once the deploy has finished, or on demand with `?build_timings=1`.
Polling a running deploy stays cheap.

**CLI** — `php artisan project:deploy:timings <username> [--json]` prints the same thing.

**The number that matters is `cache_hit_ratio`.** A first deploy is 0.0 by
definition. A repeat of the same commit should be high; one that is not has lost
its BuildKit cache, and no total will tell you that — a slow cold build and a
cache regression look identical from the outside.

`DeployTimings` (`core/app/Lib/Deploy/DeployLog/`) is the whole implementation:
it correlates `#N [x/y] <cmd>` with `#N DONE <s>` / `#N CACHED`, drops BuildKit's
own `internal` bookkeeping steps, keeps the largest of a step's repeated `DONE`
reports, and scores a `CACHED` step as costing zero. Unit tests in
`core/tests/Unit/Deploy/DeployLog/DeployTimingsTest.php` use real Matomo output.

---

## 9. Validating deploy speed and the caches

```bash
scripts/benchmark-deploys.sh                      # the whole fixture set
scripts/benchmark-deploys.sh --apps benchphp --keep
scripts/benchmark-deploys.sh --json               # for CI
```

> Measuring what an **API client** experiences instead — seven apps across
> three runtimes (PHP 8.1 and 8.3, Node, Python), in four modes that differ by
> one thing each (no prewarm → prewarmed → rebuild → restart), driven over REST
> with a full per-phase breakdown of every run — is
> [`scripts/rest-speed-test.php`](scripts/rest-speed-test.php). Both suites read
> the same `DeployTimings` numbers, so their results compare directly; this
> section is the tool for engine work, that script for answering "how fast is
> this host, from outside". Modes (no prewarm → prewarmed → rebuild → restart)
> differ by one thing each; `--modes=noprewarm` needs `--unprewarm=<ssh target>`
> because removing the host's shared base image is not something the REST API
> can do.

Four fixtures, one per strategy that behaves differently under caching —
`static`, `express`, `compose`, `php` — each deployed three ways:

| Column | What it measures |
|---|---|
| **cold** | first deploy into a fresh account; nothing about this repo is cached |
| **warm** | `users:rebuild --username=…` on the same account — the production cache path |
| **restart** | the engine's own `down()` + `up()` + health probe; no build at all |

`restart` is the engine's restart path, not a bare `docker compose up`. The
latter is 1–2s; the engine's is ~14s because it recreates the container and
waits for the inner daemon. Compare it against itself over time, not against
compose.

It exits non-zero when a fixture detects as the wrong strategy, when a deploy
fails, or when the warm rebuild's cache hit ratio falls below `MIN_WARM_RATIO`
(default `0.5`).

**Do not measure "warm" by deleting and redeploying the account.** That destroys
cache #2 along with the account and measures cold twice — the trap §5 describes.
Measured here on 2026-08-28: delete-and-redeploy moved Matomo 257s → 248s, while
rebuilding in place moved it to 47.6s and a restart to 1.9s. The first number
would have read as "caching does nothing".

### Reading a run: the phases

Totals tell you a fixture got slower. Phases tell you which part did, and they
are printed under every fixture:

```
bphp2         php         129.5s      39s    18.1s      10       5  ok (cache .500)
    phase                cold     warm
    preparing              4s       -s
    cloning                4s       4s
    detect                 2s       1s
    image_transfer        78s       -s
    build               19.7s       4s
    start_to_answer     18.3s      22s
      cold layer  RUN composer install --no-dev --no-interacti   12.4s
      warm layer  COPY . .                                          1s
```

| Phase | Spans | Fixed by |
|---|---|---|
| `preparing` | deploy start → account/container ready | account provisioning |
| `cloning` | `git clone` | repo size, network |
| `detect` | stage `running` → `Detected project type` | detection; effectively free |
| `image_transfer` | first to last `Loaded/Preparing base image` | §10 — and the transfer itself, below |
| `build` | sum of BuildKit layer times, **cached layers count zero** | the recipe's Dockerfile |
| `start_to_answer` | `Starting application` → `Health check … answered`, minus the build | entrypoint, migrations, app boot |

A phase absent from a log is **omitted, not zeroed** — a compose deploy pulls no
base images and builds nothing, and printing `0s` there would read as "instant"
rather than "did not happen". That is why `preparing` shows `-` on a warm
rebuild: a rebuild does not re-provision the account.

`build` is charged from BuildKit's own layer times rather than wall-clock,
because the compose-up window contains both the build and the boot and those are
fixed by different people. `start_to_answer` is that window with the build
subtracted, so the phases never sum past the deploy's total.

**What the example above says.** Grav's cold deploy is dominated by
`image_transfer` at 78s — not the build, and certainly not `composer install`,
which is 12.4s. That 78s is loading the ~1GB shared PHP base *into the
account's DinD*, which every new account pays even when the image is already on
the host. The warm rebuild drops `build` from 19.7s to 4s because every layer
including `composer install` came back `CACHED` — that is what a working cache
looks like, and it is why the suite fails a warm rebuild whose hit ratio is low.

`start_to_answer` barely moves between cold and warm (18.3s → 22s): it is the
app booting, not anything the engine caches. A fixture that regresses *there*
is an application problem, not a build one.

### Every step, not just the phases

`artisan project:deploy:timings <user> --timeline` charges every line the pipeline
announces with the time until the next one. Nothing is aggregated away:

```
At    Took  Step
0s    2s    Starting stage: preparing
2s    6s    Cloning repository https://github.com/matomo-org/matomo (branch: 6.x-dev)
8s    1s    Repository cloned
9s    0s    Detected project type: PHP
10s   26s   Preparing shared PHP base image panelalpha/php:8.1-cli-bookworm-pab1ff14ca
36s   1s    Detected application port: 8000
37s   12s   Using default environment variables (source: none)
49s   1s    Loaded base image composer:2 from host cache
50s   118s  Starting application (docker compose up -d)
168s  —     Deploy finished successfully
```

`--timeline` also prints **every** build layer rather than the ten slowest,
because a layer that regressed from 0.1s to 30s does not appear in a top ten
taken from the run before it regressed.

**Read the label as "time from this milestone to the next", not "time this step
took".** Work is charged to the last thing announced before it, so the 12s above
sits against *"Using default environment variables"* when it is really the
`composer:2` transfer that finishes on the following line. Fixing that means
logging around the work rather than after it; until then the timeline tells you
*when* the time went, and the phase and layer views tell you *to what*.

`dim` lines are excluded — they are the raw output of whatever is running and
number in the thousands. Only `info`/`ok`/`warn`/`error` are milestones.

### What `docker compose up` is doing

A 118-second `Starting application (docker compose up -d)` sounds like
orchestration. It is not. Compose announces `<Kind> <name> <Verb>` around
everything it does, and pairing the verbs gives:

```
What docker compose did:
  Image project-app        building   116s
  Container project-app-1  starting     1s
  Network project_default  creating     0s
  Container project-app-1  creating     0s
```

**116 of the 118 seconds is the image build.** Creating the network and the
container, and starting it, is about one second in total. If a compose-up looks
slow, it is the build inside it — go to the layer table, not to Compose.

`Running` is not an action: Compose prints it for a container it did not have to
touch, and it is skipped. A `Starting` with no `Started` is skipped too — the
container never came up, and inventing a duration for it would hide the failure.

The layer table is where that 116s resolves:

```
#8   77.5s  RUN install-php-extensions imagick
#19  19.2s  exporting to image                  ← writing + unpacking 1.36GB
#14   8.4s  RUN composer install --no-dev --no-interaction --no-scripts
#15   6.9s  COPY . .
```

`#19 exporting to image` has no `[stage x/y]` descriptor, so it was invisible
until 2026-08-29 and its cost was charged to the app's boot instead — `build`
read 95.9s and `start_to_answer` 22.1s, when the truth is 115.1s and 2.9s.
Writing and unpacking a 1.36GB image is not bookkeeping. Any BuildKit step is
counted now, bracketed or not; only `[internal]` ones are dropped.

### Transferring base images: registry vs `save | load`, and what baking costs

`DindImageStore::loadFromHostCommand()` pushes the image to the registry from
the **host** and pulls it inside the **account**, falling back to
`docker save | docker load` when the registry is unreachable **or** when any
step of the registry path fails.

**The two ends address it differently and must.** The host pushes to
`127.0.0.1:5000`; the account pulls `panelalpha-cache-registry:5000`.
`panelalpha-cache-registry` is a name on the engine's docker network and the
host is not on that network, so pushing to it there resolves against the host's
own DNS, misses, and falls back to HTTPS against a plain-HTTP registry. That was
the code for a long time: the push failed every time, the `||` guard caught it,
and every transfer silently took `save | load` while the registry sat there
looking installed. Loopback needs no daemon config — docker treats 127.0.0.0/8
as insecure by default.

It is a compose service now, `profiles: ["cache-registry", "full"]`, so an
install running the full profile has it.

Measured on 178.104.84.45, 984MB and 989MB PHP bases, into a real account:

| | `save \| load` | registry, first push | registry, already pushed |
|---|---|---|---|
| first image into an **empty** account | 14.3-15.0s | 12.2s | **8.8s** |
| second image, a different PHP minor | 15.6-16.3s | 9.5s | **7.6s** |
| image the account **already has** | 6.5s | — | **0.36s** |

The registry is not about compression — the wire is loopback. It is that
`docker save` streams every layer whatever the target holds, while `docker pull`
asks what is missing, and pulls compressed blobs.

**Roughly 1.7-2x on realistic seeds, not the 17x an earlier measurement on
10.10.10.25 recorded.** That figure was a plain base against the imagick variant
of the *same minor* -- one differing layer, so the pull moved almost nothing.
Two different PHP minors share only the Debian base; the PHP build and the
extension layers are most of the gigabyte and are unique to each. The last row
is the mechanism at its limit: total overlap, and `save` still streams 984MB.

Nothing pre-populates the registry. `system:image:prewarm` builds and pulls onto
the host and never pushes; the registry gains an image as a side effect of the
first account that needs it, which is why the middle column exists. Pushing at
prewarm time would make every account pay the right-hand column, at the cost of
registry disk sooner -- the volume is ~479MB for two bases, so each base is
stored twice on the host.

#### Baking extensions into the base is not free

Matomo, plain base against the imagick base, both prewarmed:

| Phase | plain (842MB) | imagick (1.0GB) |
|---|---|---|
| image_transfer | 39s | **85s** |
| build | 115.1s | **69.1s** |
| total | **168s** | 195s |

46s comes off the build and 46s goes onto the transfer. Every extension baked
in makes the image bigger, and **every account pays that size**, while the
compile it replaces was paid once per account too. Baking only wins once the
transfer stops scaling with image size — which, per the table above, means
overlapping layers, which means the account already had a related base.

#### Why seeding PHP bases into every account is the wrong fix

The obvious next move is to add `panelalpha/php:*` to `AccountSeedPlan` so
`seedBaseImagesInBackground()` loads it at account creation, off the deploy's
critical path. **Do not.** Three measurements say it backfires:

- **The head start is ~21s, not 84s.** The seed fires at container creation and
  the base is needed after `preparing` (3s) + `cloning` (16s) + `detect` (2s).
  A 56s transfer cannot hide inside a 21s window.
- **It would collide with itself.** If the background seed is still loading when
  the deploy calls `ensurePhpBaseImage()`, `hasImage()` is false and the deploy
  starts *its own* transfer of the same gigabyte into the same account. Two
  concurrent 1GB loads is slower than one.
- **Disk multiplies by account.** Each DinD keeps its own store under
  `/home/<user>/docker` — 2.1G for a Matomo account, 1.1G for a static one.
  Seeding an 842MB base into all 19 accounts on this host is ~16GB, on a 42GB
  disk that already sits at 86%.

`ImageCatalog::SCOPE_RECIPE` exists for on-demand preloading and currently has
no consumer, so adding entries there would be inert rather than harmful — but it
would not help either.

**What would actually help**, in rough order of value: stop each account keeping
a private copy of a shared base (an architectural change to DinD storage, not a
tuning knob); or keep the base small and accept the compile, now that the
compile is ~77s against an 85s transfer and the two are close to a wash.

### What `image_transfer` is, and is not

It is **not** pulling base images from Docker Hub. A PHP app builds `FROM
panelalpha/php:<minor>-cli-bookworm-pa<hash>` — our image, with the standard
extension set already baked in. Nothing on the PHP path pulls a stock `php:`
image at deploy time.

The phase is the cost of copying that image from the host into the account's own
Docker daemon. Each DinD account has an isolated image store, so a fresh account
holds nothing and the engine does `docker save | docker load` across the
boundary: **842MB** for the PHP base plus **315MB** for `composer:2` (needed by
the `COPY --from=composer:2` in the generated Dockerfile).

That is 26s and 12s of Matomo's cold deploy, and 78s of Grav's — now the largest
phase of a cold PHP deploy, larger than the build itself. Every new account pays
it, however many accounts received the same bytes before.

`panelalpha-cache-registry:5000` already runs on the host and a DinD daemon can
pull from it directly (start command in §9, "Transferring base images"). Pushing
the PHP base images there would turn an 842MB uncompressed save/load into a
compressed pull over loopback. Not done; it is the obvious next lever on
cold-deploy time.

### Reference figures (10.10.10.25, 2026-08-28)

Straight from `scripts/benchmark-deploys.sh`, both PHP base images present:

| Fixture | Strategy | Cold | Warm | Restart | Layers | Cached |
|---|---|---|---|---|---|---|
| Spoon-Knife | static | 8.4s | 6s | 13.8s | 0 | — |
| node-js-getting-started | express | 71.3s | 11s | 14.0s | 10 | 6 (0.60) |
| listmonk | compose | 27.6s | 7s | 15.2s | 0 | — |
| Matomo | php | **93.8s** | 50s | 14.7s | 10 | 5 (0.50) |

`static` and `compose` build no image, so they have no layers and no hit ratio —
that is correct, not a cache failure, and the suite does not flag it.

**Matomo went 248s → 168s → 93.8s** across this session: 248s with no shared
base image at all, 168s once `panelalpha/php:8.1-cli-bookworm-pab1ff14ca` was
built (dropping `apt-get` 22.7s and the standard extension set 123.7s), and
93.8s once the `-xf4f426f8` extras variant absorbed `imagick` (77.5s). Both
images have to exist; the plain one alone leaves imagick compiling per deploy.

Matomo, the same breakdown, before and after the shared base image existed —
`artisan project:deploy:timings matomo` prints exactly this:

```
phase              no base image   with base image
preparing                     2s                2s
cloning                       7s                7s
detect                       <1s               <1s
image_transfer               51s               39s
build                     182.0s            115.1s
start_to_answer               6s               2.9s
                          ------             ------
total                       248s               168s

build layers, no base image        build layers, with base image
  install-php-extensions   123.7s    install-php-extensions imagick  77.5s
  apt-get git unzip         22.7s    composer install                 8.4s
  layer export              21.5s    COPY . .                         6.9s
  composer install           8.9s    FROM panelalpha/php:8.1…         1.3s
```

`composer install` is routinely blamed and is routinely not the problem: 8.4s
against 123.7s of extension compilation. Building both base images (plain and
`-x…` with imagick) took the same deploy to **93.8s**.

---

## 10. Host gotchas — the shared PHP base image

`InnerDocker::ensurePhpBaseImage()` builds `panelalpha/php:<php>-pa<hash>` **on
the host**, once, and loads it into each account, so no account compiles the
standard extension set. When it is missing every PHP deploy pays ~146s
(`apt-get` + `install-php-extensions`) and the only trace is one line:

```
Building shared PHP base image panelalpha/php:8.1-cli-bookworm-pab1ff14ca in the background
```

`runHostCommandInBackground()` never checks the result, so a build that dies
leaves that message and nothing else. Check for the image itself:

```bash
docker images | grep panelalpha/php     # empty means every PHP deploy is paying full price
```

On 10.10.10.25 it had never built, because **host `docker build` had no network
at all**:

```
iptables -P FORWARD DROP
  ACCEPT rules for br-<compose bridge> only, none for docker0
nat POSTROUTING: MASQUERADE for 172.25.0.0/24 only, none for 172.20.0.0/16
```

Builds attach to `docker0` (172.20.0.1), so every packet was dropped and apt
reported `Temporary failure resolving 'deb.debian.org'` — which reads as DNS and
is not. `docker run` was unaffected: those containers use the compose bridge,
which has both rules. Fix:

```bash
iptables -I FORWARD -i docker0 ! -o docker0 -j ACCEPT
iptables -I FORWARD -o docker0 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -t nat -A POSTROUTING -s 172.20.0.0/16 ! -o docker0 -j MASQUERADE
```

These are not persisted across a reboot, and CSF (`scripts/csf.sh`) is the likely
reason Docker's own rules went missing.

---

## 11. Onboarding a third-party application

> Baseline: [`docs/07-supported-projects/`](docs/07-supported-projects/how-detection-works.md)
> is what an operator needs. This section is the agent playbook for taking a
> GitHub URL to a working deploy and a (maybe new) YAML manifest.

Every claim is a measurement. "It deploys" is not a result. "HTTP 200,
`<title>Login - Adminer</title>`, 24 packages in `/app/vendor`" is.

### The loop

```bash
# 1. Look at the repository before touching the engine.
git clone --depth 1 <url> /tmp/app && ls -A /tmp/app

# 2. Ask the engine what it thinks this is — seconds, no container.
php scratch/detect.php /tmp/app

# 3. Deploy it for real.
php scripts/dind-test/deploy.php <src> --name=<app> --real-home --reuse-container

# 4. Verify the app, not the status code.
docker exec dind-test-<app> curl -sSL -o /tmp/o.html -w '%{http_code}\n' http://127.0.0.1:8000/
```

Iterate on step 2 until detection is right, *then* pay for step 3.

### Detect without deploying

Detection is pure and needs no container. A detect run is ~1s against ~2 minutes
for a deploy:

```php
<?php // scratch/detect.php
$core = '/path/to/engine/core';
require $core.'/vendor/autoload.php';
$app = require $core.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r = \App\Lib\Deploy\DetectProjectStrategy::detect($argv[1]);
foreach (['strategy','label','app_root','image','database','port_hint'] as $k) {
    printf("%-12s %s\n", $k, is_scalar($r[$k] ?? null) ? (string)($r[$k] ?? '') : json_encode($r[$k] ?? null));
}
echo "toolchain    ".json_encode($r['toolchain'] ?? null)."\n";
echo "start_cmd    ".($r['start_command'] ?? '')."\n";
```

`toolchain` is the important line: version **and the file it was read from**.
If that says something you did not expect, stop and fix detection before
deploying.

### Read the repository first

Five questions, from `ls -A` and the manifests:

| Question | How to tell | What it decides |
|---|---|---|
| Where is the application? | Root `composer.json` / `package.json`, or a subdirectory? | `app_root` |
| What is the entry point? | `index.php` at the root? in `public/`? none? | serve command `PA_DOCROOT` |
| Does it need a database? | no `.env`, no bundled compose, installer with a DB step | `database: mysql` |
| Does it need a build? | `.scss`/`.ts`, a `build` script, a `Makefile` | a `build`-stage command |
| Does it hold state on disk? | flat-file storage, uploads, generated config | there is **no** persist key; redeploy wipes `/app` |

A root `docker-compose.yml` is often a *developer environment*, not a
deployment. Compose has priority 980, so it wins by default. Read it first.

### Does it need a manifest?

Try the generic platform first. DokuWiki needs none: root `composer.json`,
`index.php` at the root, no database.

Write a manifest when the generic platform gets something *wrong*: document
root not `/app` or `/app/public`; entry point must be built first; config must
be written from account credentials; app in a subdirectory; needs a database
and cannot ask. Copy the closest shipped YAML (`matomo.yaml`, `adminer.yaml`,
`phpbb.yaml`).

`detect` paths stay relative to the **repository** root. Everything after
detection resolves inside `app_root`. The subtree is copied **as** `/app`.

Ask which of the two the repository means: *where the application is built* is
`app_root`; *what is on the web* is `PA_DOCROOT`. OpenCart is the latter
(`PA_DOCROOT=/app/upload` with Composer still at the repo root).

### Failures that actually come up

| Symptom | Cause / fix |
|---|---|
| `Strategy: Railpack` on an obviously-PHP repo | no root `composer.json`; a lint `package.json` claimed Node. Set `app_root`. |
| `Runtime 'php' is required but could not be resolved` | `PhpRuntime::resolve()` reads `composer.json` at the context root. Set `app_root` (prefer that over pinning `image:`). |
| `PHP strategy selected but composer.json is missing` | same, after clone. Skipped when `namedByItsOwnManifest()` — osTicket has no Composer on purpose. |
| `Strategy: fallback` on a PHP app without Composer | `php.yaml` matches on `composer.json`. Own `detect` rules + maybe `image: php:8.3-apache-bookworm`. |
| HTTP 200 that is not the app | Easy!Appointments without `config.php`; Adminer without `compile.php`. Assert on `<title>` and bytes, never status alone. |
| Repo `Dockerfile` / compose is not a deployment | `dockerfile` is 970, compose is 980. OpenCart's Dockerfile does not build the checkout. Outrank only with the reason in the manifest. |
| Compose sidecars / `profiles:` / bind mounts | Probe skips profiled services; `./subdir` bind on a build service is a workstation file; `database:` beats a workstation datastore. |
| `JavaScript heap out of memory` in host compile | container 2 GB, Node heap now ~1.4 GB. If that is not enough, measure **without** the engine and report the number. |
| `requires ext-… missing` | `PhpExtensions::BUNDLED` must match `docker run --rm php:8.3-apache-bookworm php -m`. Do not remember. |
| `file_put_contents` during composer | plugins running before the tree is copied. Host build uses `--no-plugins` then a second install. |
| Data disappears on redeploy | no persist key. Flag ephemeral, or copy Matomo's `restore-config` on `stage: upgrade`. |
| `database: mysql` PDOException locally | expected: panel MySQL is not in the DinD harness. Prove the rest, then confirm `database:` on a real install. |

Never put a JS command in a `runtime: php` manifest. PHP frontends compile on
the host via `HostCompile::runForPhp()`. Optional build commands are
`optional: true`; unmarked failures abort, on purpose (Adminer's `compile.php`).

If a frontend build fails, prove it **outside** the engine (`docker run … npm
install && npx gulp build`) before blaming the recipe.

### What counts as "it works"

1. Container running and HTTP < 400. **Weak.**
2. The app's own `<title>` and a plausible response size.
3. **Installer driven to completion**, schema/admin page confirmed. This is the
   bar for Supported.
4. Redeploy and confirm what survives.

Record the command that produced the verdict.

### Before you ship the manifest

```bash
cd core
./vendor/bin/phpunit --filter Platform
./vendor/bin/phpunit --testsuite Unit
```

`ShippedManifestsTest` enforces unique priorities. A duplicate `priority` fails
as `Failed asserting that 31 is identical to 32`. Prove pre-existing failures
the way §1 requires. Then re-deploy **one app that already worked** — the PHP
template is shared.

Worked examples in the tree: `php.yaml` (DokuWiki), `matomo.yaml` (database +
restore-config), `adminer.yaml` (build produces the entry point), `phpbb.yaml`
(`app_root`), `opencart.yaml` (docroot below repo, outranks Dockerfile),
`osticket.yaml` (PHP, no Composer).
