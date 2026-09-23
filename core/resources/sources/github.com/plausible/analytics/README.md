# Plausible Analytics (github.com/plausible/analytics)

Privacy-friendly web analytics. An Elixir/Phoenix release serving the dashboard
and the ingestion endpoint on :8000, PostgreSQL for users, sites and goals, and
ClickHouse for the event stream.

Detection: `dockerfile` — the repository ships a root `Dockerfile` and no compose
file, so the image builds correctly and then starts alone. Everything that makes
Plausible run is outside the Dockerfile:

- `config/runtime.exs` raises without `BASE_URL`, and again without a
  `SECRET_KEY_BASE` of at least 32 bytes. The deploy created an empty `.env`.
- the image's entrypoint understands `run` and `db <script>` and nothing else,
  and `run` is only `bin/plausible start` — no migration step, and no database
  to migrate.
- `CLICKHOUSE_DATABASE_URL` defaults to a host named `plausible_events_db` that
  did not exist.

So the deploy reported success after an 8½-minute build and nothing answered on
8000. What the engine *did* find was epmd: a BEAM release binds `0.0.0.0:4369`
as soon as the VM comes up, `AppPortAlignment` polled for 24s, saw a reachable
port that was not 8000, logged `Application is listening on port 4369, not 8000;
forwarding there instead` and republished the account onto the Erlang port
mapper, which answers HTTP with an empty reply. Verdict: `serving-unknown`,
HTTP 502 through the proxy.

## The published image, not this checkout

`overrides/docker-compose.yml` is `plausible/community-edition`'s `compose.yml`
rewritten for a single hosting account. The stack that runs Plausible has always
lived in that second repository; this one is the source.

Building the source per account is what the failed deploy already measured:
502.8s, 35 layers, zero cache hits — `mix deps.get --only ce && mix deps.compile`
is 398s of it, then `npm install` for two frontends, the esbuild/tailwind asset
pipeline, the country database download and `mix release`. The result is the
image the project publishes on every release, so the recipe pulls it.

`hooks/prepare.sh` still takes the tag from the checkout. The repository has no
version constant — `mix.exs` reads `APP_VERSION` from the environment — so the
hook reads the first released `## vX.Y.Z` heading in `CHANGELOG.md` and uses
`vX` when ghcr.io has it, falling back to `v3`. Plausible publishes `vX`, `vX.Y`
and `vX.Y.Z`; the major keeps a redeploy on the same line without pinning a
patch. That line of `.env` is rewritten on every deploy; the secrets are not.

## Both databases are required

There is no single-database mode. `Plausible.Application` starts four ClickHouse
repos unconditionally (`ClickhouseRepo`, `IngestRepo`, `AsyncInsertRepo`,
`DeletionRepo`), every pageview is an insert into ClickHouse, and `/api/health`
runs `SELECT 1` against both engines before it answers 200.

ClickHouse is the expensive half, and it does fit — but only tuned.
`files/clickhouse/` installs upstream's four config drop-ins into the checkout
and the compose file mounts them read-only:

| File | What it does |
|---|---|
| `logs.xml` | upstream's, plus `query_log` removed. A default ClickHouse writes eight system log tables, each with a flush buffer and MergeTree parts; upstream drops seven and keeps a 30-day `query_log`, which one account does not need. |
| `ipv4-only.xml` | upstream verbatim. Docker bridge networks have no IPv6, so the default `::` listener fails and the server retries it through startup. |
| `low-resources.xml` | upstream's, taken further: mark cache 128 MB (upstream 500 MB), an explicit `max_server_memory_usage` of 512 MB rather than a ratio of the *host's* RAM, and background pools of 4. |
| `default-profile-low-resources-overrides.xml` | upstream verbatim — `max_threads=1`, small blocks, no parallel parsing or formatting. |

## Memory

`mem_limit` is set per service, because an account-wide cap is not a per-service
one and `ServiceLimits`' defaults (512 m, 384 m for an app-role service) are far
below what a BEAM plus ClickHouse needs:

| Service | Limit |
|---|---|
| `plausible` | 1024 m |
| `plausible_events_db` (ClickHouse) | 768 m |
| `plausible_db` (PostgreSQL) | 256 m |
| `ready` | 64 m |
| | **2112 m** inside a 2500 m account |

Postgres is small on purpose: it holds users, sites and goals, not the events.

Measured on an idle deploy just after the migrations: plausible 381 MiB,
ClickHouse 89 MiB, Postgres 62 MiB. The ceilings are headroom for an account
that actually collects events — ClickHouse's resident size follows its parts and
merges, not its idle startup.

## `pids_limit`

Both long-running services raise it above `ServiceHardener`'s 256, which is one
of the two things that stopped this recipe deploying:

- **ClickHouse, 1024.** `BackgroundSchedulePool`'s constructor allocates all of
  its threads at once and the default pool is 512, so the server aborted at
  startup with `Couldn't get 512 threads from global thread pool: Not enough
  threads` and the deploy failed on `dependency failed to start: container
  project-plausible_events_db-1 is unhealthy`. The config drop-in cuts the pool
  to 32 as well — a container that *may* create a thousand threads still should
  not.
- **plausible, 512.** The BEAM sizes its scheduler and async pools off the
  host's core count rather than the account's `cpus` share. Not observed to
  fail, but 256 is close enough to be worth not finding out.

The other failure was self-inflicted and is recorded in
`files/clickhouse/low-resources.xml`: `background_pool_size: 4` refuses to start
because `number_of_free_entries_in_pool_to_execute_mutation` (20) is validated
against `background_pool_size * background_merges_mutations_concurrency_ratio`.

## Secrets and `BASE_URL`

`hooks/prepare.sh` writes `.env`, each key only if absent — the data volumes
outlive the checkout, so a regenerated password locks Plausible out of its own
database and a regenerated `TOTP_VAULT_KEY` makes every enrolled second factor
undecryptable:

- `SECRET_KEY_BASE` — session and LiveView signing; `runtime.exs` raises below
  32 bytes.
- `TOTP_VAULT_KEY` — base64 of **exactly** 32 bytes. Any other shape is refused
  at boot with the message that also tells you how to generate it.
- `POSTGRES_PASSWORD` — hex, because it is embedded in `DATABASE_URL` and a
  base64 `/` or `+` would have to be percent-encoded there.

`BASE_URL` is the value the recipe cannot generate: it has to be the account's
public address, which does not exist when the prepare hook runs. The compose file
ships `BASE_URL: http://localhost`, exactly the shape
`ComposePlaceholders::isLocalPublicUrl()` rewrites, and `UserComposeStrategy`
replaces it with the account's `https://…` URL before the stack starts. It is
required outright — without it the app does not boot — and it also decides
`secure_cookie`, which follows the scheme.

`DATABASE_URL` and `CLICKHOUSE_DATABASE_URL` match the same key pattern, but
their values are service names rather than localhost, so they are left alone.

## The command, and readiness

The image entrypoint does not migrate, so the compose file spells the boot out
the way upstream's does:

```
sh -c "mkdir -p /var/lib/plausible/tmp && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"
```

`db migrate` is `Plausible.Release.interweave_migrate`, which migrates
PostgreSQL and ClickHouse in one pass. The `mkdir` is for `TMPDIR`: the named
volume starts as the image's `/var/lib/plausible`, which has no `tmp/`.

`AppLauncher` runs `docker compose up -d` without `--wait` and calls the deploy
finished when that returns, which is when the containers have been *started* —
here, before the ClickHouse schema exists. Compose does honour
`depends_on: condition: service_healthy` during startup, so `ready` (the
Postgres image, `entrypoint: exit 0`, `restart: "no"`) waits on Plausible's
health check and nothing else, and `up -d` blocks until the site answers. A
clean exit 0 is explicitly not a crash loop to `AppHealth::isCrashing()`.

The health check is `/api/health`, Plausible's own readiness endpoint:
`SELECT 1` on both databases plus the critical caches. `start_period: 300s`
covers the first boot.

Observed with the images already on the host: `docker compose up -d` returns in
38 s, whole deploy 75 s, and the account then answers `HTTP 200` on the loopback
port and `302` to `/register` through the proxy. `/api/health` returns
`{"sessions":"ok","postgres":"ok","clickhouse":"ok","sites_cache":"ok"}`.

## `RELEASE_DISTRIBUTION=none`

Belt and braces here rather than the fix it is for TeslaMate: `AppPortAlignment`
returns early unless the compose file is one the engine generated, so a recipe
with its own compose file is out of that path already. But Plausible CE has no
use for distribution — `libcluster` is started `on_ee` only, and the release
names no node — so there is no reason to leave epmd listening in the container
for anything else to find.

## Not set on purpose

- **`HTTPS_PORT`.** Community Edition starts its endpoint under `site_encrypt`
  when this is present: certbot, an ACME challenge listener and a second port,
  all duplicating the certificate the account already has. Unset, Plausible
  serves plain HTTP on 8000 and the engine's proxy terminates TLS.
- **`DISABLE_REGISTRATION`.** Left at the CE default, `invite_only`.
  `Plausible.Auth.check_registration_enabled/1` short-circuits while
  `should_be_first_launch?` is true, so the site comes up on a registration form
  that works exactly once and is invite-only afterwards — `/` 302s straight to
  `/register` on a fresh deploy. There is no admin password to generate.
- **Mail.** `MAILER_ADAPTER` defaults to `Bamboo.Mua`, which delivers direct to
  MX — which is to say, not from here. Registration works without it; email
  reports, invitations and password resets need an SMTP service the engine does
  not provide. Set `MAILER_ADAPTER=Bamboo.SMTPAdapter` and `SMTP_HOST_ADDR` and
  friends through the account's env vars.
- **`ulimits`.** Upstream raises `nofile` to 262144 for ClickHouse and 65535 for
  the app. Inside the account's Docker-in-Docker daemon a hard limit above the
  outer container's is refused and the container never starts, so the images'
  defaults stand; ClickHouse logs a warning about it and runs.
- **`app.sh`.** Not written. Plausible's release has no user-management CLI —
  `rel/overlays` is `createdb`, `migrate`, `rollback`, `seed` and nothing else —
  and its API keys are per-user tokens issued from the dashboard, so any
  `users:*` implementation would be direct SQL against the `users` and
  `team_memberships` tables plus Bcrypt. That is strategy 5, and worth doing
  only once the recipe is more than "it serves".
