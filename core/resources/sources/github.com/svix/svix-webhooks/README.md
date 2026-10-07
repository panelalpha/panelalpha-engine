# Svix — svix/svix-webhooks

Svix is a webhook-sending service. An application registers endpoints, sends a
message, and Svix fans it out, signs each request, retries on a schedule and
records every delivery attempt. It is an HTTP API over Postgres and Redis. There
is no web interface, no users, no sign-up and no installer.

## Why the repository root does not deploy

Not the application's fault, and not the engine's either — the root of
`svix/svix-webhooks` is the client SDKs.
`csharp/`, `go/`, `java/`, `javascript/`, `kotlin/`, `php/`, `python/`, `ruby/`,
`rust/`, plus `codegen/` and `svix-cli/`, with a `composer.json` that says
`"type": "library"`, `"description": "Svix PHP Library"`. Detection finds PHP,
chooses the php strategy, looks for an `index.php` and finds none.

The server is `server/`: a Rust workspace with its own `Cargo.toml`,
`Dockerfile`, `docker-compose.yml` and `openapi.json`, MIT-licensed.

One directory down puts it out of reach of every compose lookup the engine has.
`ComposeFileInspector::COMPOSE_FILE_CANDIDATES` is four basenames joined to the
project root with a single `/`, and the only glob is `RuntimeSidecars`'
`$projectDir . '/docker-compose.*.yml'` and three siblings — no `**`. So
`server/docker-compose.yml` is neither auto-loaded nor mined. **Sidecar mining does
not arise here**: there is nothing at the root to be greedy about.

## Can a headless API satisfy the serving criterion?

Yes, and without a fake index page. Two things make it work.

**Svix redirects `/` to its own documentation.** `GET /` answers `307` with
`Location: /docs`, and `AppHealth::probeScript()` follows *relative* redirects
up to five hops (`AppHealth.php:1133-1150`). `/docs` is a 200 ReDoc page —
`<title>Svix API - ReDoc</title>` — carrying none of the baseline checks'
needles. The health probe therefore sees a 200 with a real body, the same as any
web application.

**And a 4xx would have been fine anyway.** `checks/_baseline/no-server-error.yaml`
says so in as many words: *"4xx deliberately stays healthy here. Plenty of
applications legitimately 404 on `/`, and an API that answers 401 to an
unauthenticated probe is working."* With `runtime: compose` the baseline group is
the whole of what runs — there is no `checks/compose/` and no `entry-served`
outside the `php` and `nginx` groups — so nothing asks a compose deploy for an
entry document. Without this recipe the `missing_entry` failure comes from
`checks/php/entry-served.yaml`, reached only because detection chooses php.

**This recipe declares no `check:` block, deliberately.** It would not run.
`AppHealth::declaredChecks()` resolves the account's stored platform id against
`PlatformRegistry::all()` (`AppHealth.php:346`), which reads
`resources/platforms/` and `resources/apps/` only (`PlatformRegistry.php:38-45`)
— never `resources/sources/`. A source recipe's `check:` list and its `checks/`
directory are both inert at health time.

## The stack

Upstream's own `server/docker-compose.yml`, minus pgbouncer.

**The published image, not a build.** `server/Dockerfile` is a cargo-chef
multi-stage on `rust:1.96-slim-trixie` that runs `cargo chef cook --release` and
then `cargo build --release` across a two-crate workspace with `hyper` patched
from a git fork. That is a rustc and a linker sized like the whole account, for a
binary upstream publishes as `svix/svix-server`. `hooks/prepare.sh` reads the version from
`server/Cargo.toml`'s `[workspace.package]`, confirms `v<version>` is published
on Docker Hub before using it, and falls back to `:latest` for a clone of `main`
between releases.

**pgbouncer dropped, and the pool capped to match.** pgbouncer multiplexes a
SaaS-scale connection count in front of one Postgres; a single account has one
Svix process and talks to Postgres directly over `SVIX_DB_DSN`. Dropping it is
not free by itself: `config.default.toml` ships `db_pool_max_size = 100` against
a Postgres whose own `max_connections` default is 100, which only works because
upstream puts a pooler in between. `SVIX_DB_POOL_MAX_SIZE: "10"` is the
configured minimum and far more than one account uses.

**Postgres 16, not the 13.4 upstream pins**, which reached end-of-life in
November 2025. The migration set runs clean on 16. The tag floats within the major on purpose — a
major change would leave the volume unreadable.

**Redis with `noeviction`.** This Redis is the queue, not a cache. An eviction
policy that drops keys under pressure would silently drop undelivered webhooks;
refusing the write makes the failure visible. The cache is not in Redis at all —
`SVIX_CACHE_TYPE: memory` is safe with a single API process, which is what an
account runs, and it saves a second connection pool and the memory behind it.

**`ready` is the readiness gate.** `ready` waits for `token`, which
waits for the backend's healthcheck, which is upstream's own
`svix-server healthcheck` — a HEAD of `/api/v1/health`, which answers only once
the database, the queue and the cache have each been reached, and therefore only
after the migration set has run against an empty database. The image is
debian-trixie-slim with no curl and no wget, so the binary's own subcommand is
also the only probe available inside it.

## Credentials

The opposite of most of this catalogue. There is no installer to race and no
first-visitor window to close.

Every request is authenticated by a bearer JWT signed with `jwt_secret`. No
endpoint issues one — the only way to mint a token is the `svix-server jwt
generate` CLI, which reads the configured secret. And there is no default secret
to inherit: `cfg.rs` refuses to start the server at all when it is absent —

```rust
if !figment.contains("jwt_secret") {
    bail!("missing field `jwt_secret`");
}
```

So an unauthenticated caller cannot obtain a credential, and a misconfigured
instance does not start rather than starting open. `GET /api/v1/app` with no
token or a bogus one answers
`401 {"code":"authentication_failed","detail":"Invalid token"}`.

`default_org_id()` is a hard-coded constant, `org_23rb8YdGqMT0qIzpgGwdXfHirMu`,
identical on every Svix deployment in the world. That is harmless: it is the
*subject* of the token, not a secret, and a token bearing it is only accepted by
an instance whose `jwt_secret` signed it.

What this recipe generates, per account, into `~/.panelalpha/` at 0600 in a 0700
directory (the account's home is root-owned 0755, so the directory is created):

| file | contents |
|---|---|
| `~/.panelalpha/svix.env` | `SVIX_JWT_SECRET`, `SVIX_MAIN_SECRET` (32 random bytes each), `SVIX_DB_DSN`, `SVIX_REDIS_DSN` |
| `~/.panelalpha/svix-db.env` | `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` |
| `~/.panelalpha/svix/admin-token` | the account's ten-year organisation token |
| `~/.panelalpha/svix-credentials.txt` | what a human is pointed at |

The token is minted by a one-shot `token` compose service running
`svix-server jwt generate` once the backend is healthy, guarded on the file
being non-empty so a redeploy does not issue a second token and overwrite the
one the account's owner was given. It runs as the account's own uid (passed
through `SVIX_RUN_AS` in `.env`) because the image runs as 1001, and it writes
into a file `prepare.sh` pre-creates at 0600 so a truncating redirect preserves
the mode.

**`SVIX_MAIN_SECRET` matters more than it looks.** It is the key for encrypting
endpoint signing secrets at rest, and `ConfigurationInner::encryption` carries
`#[serde(default)]` — which resolves to `Encryption::default()`, which is
`new_noop()`, whose `encrypt()` is `Ok(data.to_vec())`. Left unset, every
`whsec_…` key is stored in Postgres in the clear. Set, the column is ciphertext
— a 24-byte XChaCha20 nonce and a Poly1305-tagged body. Svix's own note is *"IMPORTANT: Once
set, it can't be changed."*

Nothing here is derived from the account's filesystem path. Svix
reads `config.toml` relative to its own working directory, which in the published
image is `/`, not the `/app` every account is mounted at; this recipe does not
bind-mount the checkout into the server container at all; and all three secrets
are `openssl rand` per account. Two accounts deployed from this recipe share
nothing but the public `default_org_id`.

## SSRF: private address space is blocked, on purpose

`SVIX_WHITELIST_SUBNETS` is deliberately left unset. With it unset Svix refuses
to dispatch to an endpoint whose URL resolves into private address space. This is
the only thing between a webhook URL a customer can type and a request into the
network the account is hosted on. An endpoint at a private address such as
`http://10.0.0.1:8080/hook` records every attempt as

```
status=2 fail -> requests to this IP range are blocked (see the server configuration)
```

while public endpoints in the same fan-out are delivered. An operator
who needs it can set the variable in `~/.panelalpha/svix.env`; the file says so
and says why.

## What the engine could not infer

- **The server is not at the repository root.** Nothing the engine looks at
  reaches `server/`: `ComposeFileInspector::COMPOSE_FILE_CANDIDATES` is four
  root basenames, and `RuntimeSidecars`' glob is one directory deep.
- **The image, and its tag.** The checkout's only real version string is
  `server/Cargo.toml`'s `[workspace.package] version`, and Docker Hub prefixes
  it with `v`.
- **`jwt_secret` and `main_secret`.** The server will not start without the
  first, and silently stores signing secrets in the clear without the second.
  Neither has a default the engine could have supplied, and both must survive a
  clone that empties `~/project`.
- **The connection pool.** Upstream's 100 assumes the pgbouncer this recipe
  drops.
- **That an API on `/docs` is a working site.** The engine gets this right on
  its own, but only because Svix redirects; a headless API that answered 404 on
  `/` would also have passed, by `no-server-error`'s explicit rule.

## Do not add `extends: compose` to `panelalpha.yaml`

It looks like the obvious tidy-up, it makes the inspect endpoint's answer
correct, and it fails the deploy outright with *"Compose strategy selected but
compose file is missing."* Without it (as shipped), inspect answers
`strategy=php` and the deploy runs as compose.

Both halves come from the same place. `PlatformSelector::forContext()` tries
`fromSource()` before the file walk (`PlatformSelector.php:49`), and
`SourceRecipes::fromAppConfig()` returns null for a `panelalpha.yaml` that
declares no manifest key (`SourceRecipes.php:150-153`,
`AppConfig.php:491-493`). So a description-only recipe is invisible to
detection, and detection instead runs against whatever is on disk.

That is why the *deploy* works: `AppConfigBootstrap` writes the recipe's
`overrides/docker-compose.yml` into `~/project` at `PrepareFromSource.php:59`,
and `DetectProjectStrategy::detect()` only runs at `:64`, by which time
`ComposeUsableProbe` sees a real compose file and `compose` (priority 980) beats
`php` (930). And it is why *inspect* answers `php`: it detects against the raw
clone (`SourceInspectionController.php:264` → `AppInspector.php:86`), where the
only thing to find is the SDKs' `composer.json`.

Naming the base closes the inspect gap and opens a worse one. `fromSource()`
then claims the repository, but the decision it builds carries no
`compose_path`, and `DeployabilityCheck::assertCompose()`
(`DeployabilityCheck.php:127-132`) requires one — so the deploy is rejected at
`PrepareFromSource.php:82`, *after* the compose file has already been written
one line earlier. The recipe cannot name the strategy it actually uses.

The cost of leaving it out is cosmetic: inspect reports `strategy: php` for an
app that deploys as compose. Every other
compose-override recipe in this catalogue (rocketchat, budibase, n8n, cal.diy,
wordpress) has the same skew.

## Not provided

- **The App Portal.** The consumer-facing portal is not part of the open-source
  server: `default_app_portal_url()` returns `https://app.svix.com`, which this
  instance is not, so `POST /api/v1/app/{id}/dashboard-access` hands back a link
  to Svix's hosted product. The API, the SDKs and the API reference are all
  served from the account.
- **Operational webhooks.** `SVIX_OPERATIONAL_WEBHOOK_ADDRESS` makes an instance
  send its own events to a second Svix API, which a single-account deployment
  does not have.
- **The ReDoc page needs the internet.** `/docs` loads
  `redoc.standalone.js` from jsdelivr. The health probe reads bytes and does not
  care; a browser on an air-gapped network would see an empty page. The OpenAPI
  document itself is served locally at `/api/v1/openapi.json`.
