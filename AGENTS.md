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
| MCP | [`docs/04-connecting-your-ai/`](docs/04-connecting-your-ai/your-assistant.md) | `tests/mcp/`; do not invent client UIs |
| Detection / stacks | [`docs/07-supported-projects/`](docs/07-supported-projects/how-detection-works.md) | §6a Railpack; §11 onboarding an app |
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

Report a failure as pre-existing **only after proving it** — `git stash`, re-run,
confirm the same tests fail on the baseline, `git stash pop`. Never wave a failure
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
environmental reasons (no firewall on the host, IP management absent, `pae-artisan`
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
php scripts/tools/dind-test/deploy.php <git-url|local-path> [flags]
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
  Build:     <n> layer(s), <n> cached, Ns spent building
  Time:      Ns total (create Ns, daemon-wait Ns, daemon-settle (test-only) Ns,
             seed (test-only) Ns, clone/detect Ns, start Ns, settle (test-only) Ns,
             http-check (test-only) Ns, unaccounted Ns)
  Production critical path: ~Ns

  Build steps (inside the account's Docker, slowest first):
      Ns  RUN HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund
      Ns  RUN apt-get update && apt-get install -y --no-install-recommends git && ...
      Ns  RUN npm run build
      Ns  RUN node -e '...strip git-hook scripts...'
      Ns  COPY . .
```

### The phases

| Phase | Real deploy cost? | What it is |
|---|---|---|
| `create` | **yes** | Render the account's compose file and `docker compose up` the DinD container |
| `daemon-wait` | **yes** | Inner dockerd becoming reachable |
| `daemon-settle` | no — test only | Fixed 2s margin for a loaded host |
| `seed` | no — **backgrounded in production** | Copies the `ImageCatalog` images into the inner daemon. It dominates wall time; production uses `InnerDocker::seedBaseImagesInBackground()`, which does not block a deploy |
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
php scripts/tools/dind-test/deploy.php <any-app> --real-home --name=NAME --seed-only

# Then every app deploys into that seeded account:
php scripts/tools/dind-test/deploy.php <app> --real-home --name=NAME --reuse-container            # COLD
php scripts/tools/dind-test/deploy.php <app> --real-home --name=NAME --reuse-container --keep-cache # WARM
```

**Global seed is not a per-deploy cost.** It imports the `ImageCatalog` images
into the account's inner daemon. Production does it in
the background at account creation (`InnerDocker::seedBaseImagesInBackground()`),
so no deploy ever waits on it. Measure it once, report it once, and keep it out
of every app's number.

### Two rules that make shared-account results valid

**Tear down between apps.** All apps in one account build the same
`project-app:latest` under the same compose project. Without a teardown, app N
silently *runs app N-1's image* and reports a meaningless HTTP 200.

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
> #2, so running it twice measures cold twice, and reads as "caching does not
> help". Use `--reuse-container`.

---

## 6a. Railpack

> Baseline: [`docs/07-supported-projects/how-detection-works.md`](docs/07-supported-projects/how-detection-works.md)
> (order, Deno/Elixir gap). This section is the cache path and how to verify it.

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
hapi, Restify, Feathers, or a plain Node server.

For a library repo (`expressjs/express`, `hapijs/hapi`) Railpack builds the
image fine, but there is no server and no `start` script, so nothing listens.
Build success is the signal there, not HTTP.

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

What a correct run shows:

- the build reads the `railpack-*` stack tags it was given (all seven unless
  narrowed — see "Narrowing `--cache-from`" in §6b)
- a warm build makes fewer requests and fetches fewer blobs than a cold one,
  because BuildKit already holds them
- **GET and HEAD only, no PUT/POST/PATCH.** Not one write. That is
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
`scripts/tools/dind-test/cache-usage.php` does:

```bash
MARK=$(php scripts/tools/dind-test/cache-usage.php --mark)
php scripts/tools/dind-test/deploy.php <app> --real-home --name=NAME
php scripts/tools/dind-test/cache-usage.php --from=$MARK
```

Distinguish the two columns; conflating them is the easy mistake:

- **MANIFEST READS** — the build asked about a tag. Every Railpack build asks
  about *every* stack, because `BuildCache::tenantFlags()` attaches one
  `--cache-from` per stack unconditionally. A read means "considered".
- **BLOB PULLS / MB** — cached layer data actually transferred. This is real
  usage. A tag with reads and ~0 MB did nothing for that build.

The report has one row per tag:

```
CACHE TAG                  MANIFEST READS   BLOB PULLS         MB   VERDICT
railpack-node-22                      <n>          <n>        <n>   USED
railpack-node-20                      <n>          <n>        <n>   consulted, no payload
...

Writes during this window: <n>
```

**For a Node project, one cache of seven does the work.** The non-Node tags
cost a manifest round trip each and deliver nothing. That is what motivated
narrowing `--cache-from` (below).

Do **not** read this as "the other six tags are useless". They are unused *by a
Node build*, and Node is all Railpack normally sees because the recipes claim
the other languages first. When a project does fall through to Railpack, those
tags are worth a great deal. See "Does the shared cache make Railpack faster?"
below for how to run the per-stack A/B.

### Does the shared cache make Railpack faster?

How to A/B it: the same fixtures, a fresh container every run (so the account's
own BuildKit cache is never what is measured), and the cache disabled by
renaming or stopping the registry container so `lookUpCacheRegistry()` resolves
null. Stopping is safe now that `deploy.php` removes only its own container by
name; with the old unscoped `docker container prune -f` a *stopped* registry
container was deleted outright. Metric is `clone/detect`, because for a Railpack
app the build runs inside `prepareUserAppFromSources()`, not in `start`.

The cache works, and the size of the win tracks what `mise install` costs per
language: ruby compiles from source and gains the most, python and node download
prebuilt binaries and gain less, go's toolchain is cheap enough that the cache
is noise.

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
path was slow. The cache narrows that gap, but the recipe is still faster, so
bypassing it would be wrong; it does mean the ruby/python/go warm tags are
insurance for projects the recipes decline, not everyday load. Whether that
insurance is worth its registry disk is a product call, and it should be made
against an A/B like the one above rather than against the assumption that the
tags are simply dead.

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

Narrowing does not change the payload: the cache does exactly as much work, and
only the round trips to tags the build cannot use are gone. The decision is
logged, so you can tell which path a deploy took:

```
Railpack cache narrowed for <account>: node-22
Railpack plan for <account> names no warmed runtime; reading every cache tag.
```

A repo that pins a warmed version narrows (`feathers-chat/quick-start` to
`node-22`); one whose range resolves to a version we do not warm
(`expressjs/express`, `"node": ">= 18"`) falls back to all seven. **A repo
pinning an unwarmed runtime gets no cache benefit at all, narrowing or not.**
Warming that runtime (`node-18` here) would help those repos more than
narrowing does.

Writes are expected over the whole log (cache warming writes these tags from
synthetic projects) and must be **zero inside a deploy window**. The script
distinguishes the two: unscoped it reports warming writes as normal, and with
`--from` a non-zero count means a tenant build exported layers into a registry
every other account can read, which `tenantFlags()` exists to prevent.

---

## 7. Host gotchas

- **`/tmp` may be a RAM-backed tmpfs.** It often is (`/etc/fstab`).
  A DinD account keeps its entire inner Docker storage — every seeded image and
  build layer — under its account dir, so a tester rooted in `/tmp` writes
  gigabytes into RAM, and a run dies with `no space left on device` while the
  disk still has room. The tester now uses
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
  "total_seconds": N,
  "phases": [
    {"name": "preparing",       "seconds": N},
    {"name": "cloning",         "seconds": N},
    {"name": "detect",          "seconds": N},
    {"name": "image_transfer", "seconds": N},
    {"name": "build",           "seconds": N},
    {"name": "start_to_answer", "seconds": N}
  ],
  "stages": [
    {"name": "preparing", "started_at": …, "finished_at": …, "seconds": N},
    {"name": "cloning",   "started_at": …, "finished_at": …, "seconds": N},
    {"name": "running",   "started_at": …, "finished_at": …, "seconds": N}
  ],
  "build": {
    "total_seconds": N, "step_count": N, "cached_steps": N,
    "cache_hit_ratio": N,
    "slowest": [{"step": "#8", "command": "RUN install-php-extensions imagick",
                 "seconds": N, "cached": false}]
  }
}
```

`phases` is the block to read. The three recorded `stages` are kept because the
API has always returned them, but `running` is one blob covering
detection, base images, the build and the app booting — four things with four
different fixes. `phases` splits it using milestones the pipeline already logs
(`Detected project type`, `Loaded base image`, `Starting application`,
`Health check … answered`). See "Reading a run" in §9 for what each means.

Stage durations come from `latest.json` and are always present. The build
breakdown re-reads the whole log — large on a real build — so it
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
scripts/tools/benchmark-deploys.sh                      # the whole fixture set
scripts/tools/benchmark-deploys.sh --apps benchphp --keep
scripts/tools/benchmark-deploys.sh --json               # for CI
```

> Measuring what an **API client** experiences instead — seven apps across
> three runtimes (PHP 8.1 and 8.3, Node, Python), in four modes that differ by
> one thing each (no prewarm → prewarmed → rebuild → restart), driven over REST
> with a full per-phase breakdown of every run — is
> [`scripts/tools/rest-speed-test.php`](scripts/tools/rest-speed-test.php). Both suites read
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
engine's is much slower than the latter because it recreates the container and
waits for the inner daemon. Compare it against itself over time, not against
compose.

It exits non-zero when a fixture detects as the wrong strategy, when a deploy
fails, or when the warm rebuild's cache hit ratio falls below `MIN_WARM_RATIO`
(default `0.5`). `static` and `compose` build no image, so they have no layers
and no hit ratio — that is correct, not a cache failure, and the suite does not
flag it.

**Do not measure "warm" by deleting and redeploying the account.** That destroys
cache #2 along with the account and measures cold twice — the trap §5 describes
— and reads as "caching does nothing".

### Reading a run: the phases

Totals tell you a fixture got slower. Phases tell you which part did, and they
are printed under every fixture:

```
<fixture>      php           Ns       Ns       Ns     <n>     <n>  ok (cache <ratio>)
    phase                cold     warm
    preparing              Ns       -s
    cloning                Ns       Ns
    detect                 Ns       Ns
    image_transfer         Ns       -s
    build                  Ns       Ns
    start_to_answer        Ns       Ns
      cold layer  RUN composer install --no-dev --no-interacti     Ns
      warm layer  COPY . .                                          Ns
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

**How to read it.** A cold PHP deploy can be dominated by `image_transfer`
rather than the build, and certainly rather than `composer install`: that phase
is loading the shared PHP base *into the account's DinD*, which every new
account pays even when the image is already on the host. A warm rebuild whose
layers, `composer install` included, come back `CACHED` is what a working cache
looks like, and it is why the suite fails a warm rebuild whose hit ratio is low.

`start_to_answer` should barely move between cold and warm: it is the app
booting, not anything the engine caches. A fixture that regresses *there* is an
application problem, not a build one.

### Every step, not just the phases

`artisan project:deploy:timings <user> --timeline` charges every line the pipeline
announces with the time until the next one. Nothing is aggregated away:

```
At    Took  Step
Ns    Ns    Starting stage: preparing
Ns    Ns    Cloning repository <url> (branch: <branch>)
Ns    Ns    Repository cloned
Ns    Ns    Detected project type: PHP
Ns    Ns    Preparing shared PHP base image panelalpha/php:<minor>-cli-bookworm-pa<hash>
Ns    Ns    Detected application port: <port>
Ns    Ns    Using default environment variables (source: none)
Ns    Ns    Loaded base image composer:2 from host cache
Ns    Ns    Starting application (docker compose up -d)
Ns    —     Deploy finished successfully
```

`--timeline` also prints **every** build layer rather than the ten slowest,
because a layer that regressed from 0.1s to 30s does not appear in a top ten
taken from the run before it regressed.

**Read the label as "time from this milestone to the next", not "time this step
took".** Work is charged to the last thing announced before it, so time charged
to *"Using default environment variables"* above is really the
`composer:2` transfer that finishes on the following line. Fixing that means
logging around the work rather than after it; until then the timeline tells you
*when* the time went, and the phase and layer views tell you *to what*.

`dim` lines are excluded — they are the raw output of whatever is running and
number in the thousands. Only `info`/`ok`/`warn`/`error` are milestones.

### What `docker compose up` is doing

A long `Starting application (docker compose up -d)` sounds like
orchestration. It is not. Compose announces `<Kind> <name> <Verb>` around
everything it does, and pairing the verbs gives:

```
What docker compose did:
  Image project-app        building     Ns
  Container project-app-1  starting     Ns
  Network project_default  creating     Ns
  Container project-app-1  creating     Ns
```

**Nearly all of it is the image build.** Creating the network and the
container, and starting it, is negligible. If a compose-up looks slow, it is
the build inside it — go to the layer table, not to Compose.

`Running` is not an action: Compose prints it for a container it did not have to
touch, and it is skipped. A `Starting` with no `Started` is skipped too — the
container never came up, and inventing a duration for it would hide the failure.

The layer table is where the build time resolves:

```
#8    Ns  RUN install-php-extensions imagick
#19   Ns  exporting to image                  ← writing + unpacking the image
#14   Ns  RUN composer install --no-dev --no-interaction --no-scripts
#15   Ns  COPY . .
```

`exporting to image` has no `[stage x/y]` descriptor; it still counts as
`build`, not as the app's boot. Writing and unpacking a large image is not
bookkeeping. Any BuildKit step is counted, bracketed or not; only `[internal]`
ones are dropped.

### Transferring base images: registries only, and what baking costs

There is no `docker save | docker load` any more: it copied incomplete
containerd-store images without complaint. `DindImageStore::seedCommand()`
is the whole ladder, used by `ensure()` and the parallel compose seed alike:
account already has it → `panelalpha-cache-registry` → then, for **our** images
only, the host that built it (push, then pull); for public images, the image's
own registry (Docker Hub through `panelalpha-registry-proxy`). A public image is
never taken from the host's copy, and the host no longer pulls anything on a
deploy's behalf. So cache-registry holds the prewarmed catalogue, which prewarm
keeps, plus our images built on demand by a deploy, which the weekly trim
removes.

**Check the registry through the daemon, never over the network.** The probe is
`docker inspect` of the container. A `curl 127.0.0.1:5000` run by
`System::exec()` inside the core container hits core's own loopback, fails on
every real install, and sends nothing to the registry; the dind-test harness
runs on the host and cannot catch that.

**The two ends address it differently and must.** The host pushes to
`127.0.0.1:5000`; the account pulls `panelalpha-cache-registry:5000`.
`panelalpha-cache-registry` is a name on the engine's docker network and the
host is not on that network, so pushing to it there resolves against the host's
own DNS, misses, and falls back to HTTPS against a plain-HTTP registry. That was
the code for a long time: the push failed every time, the `||` guard caught it,
and every transfer silently took `save | load` while the registry sat there
looking installed. Loopback needs no daemon config — docker treats 127.0.0.0/8
as insecure by default.

It is a required compose service, behind no profile: a PHP base exists on no
public registry, so without `cache-registry` it has no way into an account.

**Two containers, one storage.** Every account reaches `panelalpha-cache-registry`
and pulls from it by tag, so it runs read-only (`maintenance.readonly`, storage
mounted `:ro`): anything but GET/HEAD answers 405. The host pushes to
`panelalpha-cache-registry-writer`, core deletes tags there, and garbage-collect
runs inside it. The writer runs with `network_mode: host` and listens on the
host's `127.0.0.1:5000` only (debug listener off), so no account can resolve or
route to it, guard or no guard: accounts older than the egress guard have none,
and a tenant can remove its own. Core reaches it with `nsenter --net` into the
host's namespace (`CacheRegistry::hostArgv()`). To verify it with throwaway
copies: `docker push` to the writer, then `docker pull` from the read-only one
works; a push, manifest PUT or DELETE against the read-only one is 405; a tag
deleted and collected on the writer is 404 on the reader at once (the
descriptor cache is off on both).

The registry is not about compression — the wire is loopback. It is that
`docker save` streams every layer whatever the target holds, while `docker pull`
asks what is missing, and pulls compressed blobs. So the gain depends on layer
overlap: a plain base and its imagick variant of the *same minor* differ by one
layer, while two different PHP minors share only the Debian base, and the PHP
build and the extension layers are unique to each.

`system:image:prewarm` fills the registry: after warming the host it pushes
every catalogue image the host holds. The weekly schedule runs it with
`--refresh`, which also rebuilds (`docker build --pull`) and re-pulls what is
already there, strictly one image at a time, so nothing in the registry is more
than a week old. Accounts that already hold an image keep it; only new pulls see
the refresh. It then removes every registry tag outside the catalogue (never
one sharing a digest with a kept tag) and runs `garbage-collect
--delete-untagged`, which also frees the layers a refresh left under a
re-pushed tag. Both hold `/tmp/panelalpha-cache-registry.lock` exclusive in the
core container; every deploy-time push holds it shared, because a GC during an
upload deletes that upload's layers. `--runtimes=` skips the trim, since a
subset of the catalogue would delete the rest.

On registry 3.1.1, `garbage-collect --delete-untagged` keeps an OCI index's
platform manifest and attestation, and the image still pulls and runs. The
anonymous Docker Hub limit is **100 per hour per egress IP**
(`ratelimit-limit: 100;w=3600`), shared by every machine behind the same NAT,
when registry-proxy has no credentials, and test deploys can exhaust it. So the
pull planner must not size items with `docker manifest inspect`, which runs in
core's CLI and goes to Hub anonymously, around the host daemon's mirror. It
asks cache-registry, then registry-proxy
(`RegistryImageConfig::downloadBytes()`), and falls back to
`docker manifest inspect` only for what neither answers, such as an uncached
ghcr.io image.

#### Baking extensions into the base is not free

What comes off the build goes onto `image_transfer`. Every extension baked in
makes the image bigger, and **every account pays that size**, while the compile
it replaces was paid once per account too. Baking only wins once the transfer
stops scaling with image size — which means overlapping layers, which means the
account already had a related base.

#### Why seeding PHP bases into every account is the wrong fix

The obvious next move is to add `panelalpha/php:*` to `AccountSeedPlan` so
`seedBaseImagesInBackground()` loads it at account creation, off the deploy's
critical path. **Do not.** It backfires three ways:

- **The head start is shorter than the transfer.** The seed fires at container
  creation and the base is needed after `preparing` + `cloning` + `detect`; a
  PHP base transfer does not fit inside that window.
- **It would collide with itself.** If the background seed is still loading when
  the deploy calls `ensurePhpBaseImage()`, `hasImage()` is false and the deploy
  starts *its own* transfer of the same image into the same account. Two
  concurrent loads of the same image are slower than one.
- **Disk multiplies by account.** Each DinD keeps its own store under
  `/home/<user>/docker`, so a base seeded into every account is stored once per
  account.

`ImageCatalog::SCOPE_RECIPE` exists for on-demand preloading and currently has
no consumer, so adding entries there would be inert rather than harmful — but it
would not help either.

**What would actually help**, in rough order of value: stop each account keeping
a private copy of a shared base (an architectural change to DinD storage, not a
tuning knob); or keep the base small and accept the compile, since the compile
and the transfer it would replace are close to a wash.

### What `image_transfer` is, and is not

It is **not** pulling base images from Docker Hub. A PHP app builds `FROM
panelalpha/php:<minor>-cli-bookworm-pa<hash>` — our image, with the standard
extension set already baked in. Nothing on the PHP path pulls a stock `php:`
image at deploy time.

The phase is the cost of copying that image from the host into the account's own
Docker daemon. Each DinD account has an isolated image store, so a fresh account
holds nothing and the engine does `docker save | docker load` across the
boundary: the PHP base
plus `composer:2` (needed by the `COPY --from=composer:2` in the generated
Dockerfile).

On a cold PHP deploy it can be the largest phase, larger than the build itself.
Every new account pays it, however many accounts received the same bytes before.

`panelalpha-cache-registry:5000` already runs on the host and a DinD daemon can
pull from it directly (start command in §9, "Transferring base images"). Pushing
the PHP base images there would turn an uncompressed save/load into a
compressed pull over loopback. Not done; it is the obvious next lever on
cold-deploy time.

`composer install` is routinely blamed and is routinely not the problem;
compiling PHP extensions is. An app that needs an extension outside the plain
base (Matomo's `imagick`) needs the `-x…` extras variant too: the plain base
alone leaves that extension compiling on every deploy.

---

## 10. Host gotchas — the shared PHP base image

`InnerDocker::ensurePhpBaseImage()` builds `panelalpha/php:<php>-pa<hash>` **on
the host**, once, and loads it into each account, so no account compiles the
standard extension set. When it is missing every PHP deploy pays for `apt-get`
and `install-php-extensions` itself, and the only trace is one line:

```
Building shared PHP base image panelalpha/php:8.1-cli-bookworm-pab1ff14ca in the background
```

`runHostCommandInBackground()` never checks the result, so a build that dies
leaves that message and nothing else. Check for the image itself:

```bash
docker images | grep panelalpha/php     # empty means every PHP deploy is paying full price
```

Where it had never built, the cause was that **host `docker build` had no
network at all**:

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

These are not persisted across a reboot, and CSF (since replaced by ufw) is the likely
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
php scripts/tools/dind-test/deploy.php <src> --name=<app> --real-home --reuse-container

# 4. Verify the app, not the status code.
docker exec dind-test-<app> curl -sSL -o /tmp/o.html -w '%{http_code}\n' http://127.0.0.1:8000/
```

Iterate on step 2 until detection is right, *then* pay for step 3.

### Detect without deploying

Detection is pure and needs no container. A detect run takes seconds; a deploy
takes minutes:

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

Six questions, from `ls -A` and the manifests:

| Question | How to tell | What it decides |
|---|---|---|
| Where is the application? | Root `composer.json` / `package.json`, or a subdirectory? | `app_root` |
| What is the entry point? | `index.php` at the root? in `public/`? none? | serve command `PA_DOCROOT` |
| Does it need a database? | no `.env`, no bundled compose, installer with a DB step | `database: mysql` |
| Does it need a build? | `.scss`/`.ts`, a `build` script, a `Makefile` | a `build`-stage command |
| Does it hold state on disk? | flat-file storage, uploads, generated config | there is **no** persist key; redeploy wipes `/app` |
| Does it derive a secret from its own path? | `realpath(`, `__DIR__`, `getcwd()`, `DOCUMENT_ROOT` near `salt`/`key`/`secret`/`session_name` | every account's checkout is `/app`, so that secret is the same on every tenant: use `PA_INSTANCE_SECRET` instead |

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
