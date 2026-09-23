# Koillection

<https://github.com/benjaminjonard/koillection> — a self-hosted collection
manager (Symfony 8, PHP 8.5, FrankenPHP, PostgreSQL or MySQL).

## Create the first account immediately

**There is no shipped default login, and there is also nothing stopping a
stranger from taking the account.** Koillection seeds no admin user.
`SecurityController::login` (route `/`) redirects to `/first-connection` for as
long as `userRepository->count([]) === 0`, and `security.yaml` marks
`^/first-connection` `PUBLIC_ACCESS`. A freshly deployed instance is therefore
an open form on which the first visitor becomes `ROLE_ADMIN`, and it stays open
until someone submits it.

Open `https://<domain>/` and create the account as the first thing you do after
the deploy. This is the pretix/grocy problem in a different shape: not a
published password, but an unclaimed one.

## What went wrong without the recipe

The tracker run ended `serving-unknown`: port 8000 answered `Recv failure:
Connection reset by peer`, the domain answered 502, and the health report's
`app-restart-looping` check named `postgresql (Restarting (1))`.

Two separate things:

1. **The Dockerfile was not used, and correctly so.** The repository's root
   `Dockerfile` is a real production file — its own `docker-release.yml`
   workflow builds and publishes from it — but it declares `ENV PUID=1001` /
   `ENV PGID=1001` and calls `addgroup --gid "$PGID"`.
   `ComposeFileInspector::isHostUidMappedDockerfile()` treats a uid-mapping
   Dockerfile as deployable only when the variable has an `ARG NAME=<digits>`
   default; an `ENV` is not that. So the `dockerfile` probe returned false and
   detection fell through to `php` (composer.json, no artisan).

2. **The dev compose file poisoned the sidecars.** `docker-compose.dist.yml`
   ("provided for dev purposes") is not run, but `RuntimeSidecars` mines it for
   backing services and took both `postgresql` and `mysql` verbatim — including
   `image: postgres:18` with `./docker/volumes/postgresql:/var/lib/postgresql/data`.
   Postgres 18 moved `PGDATA` to `/var/lib/postgresql/18/docker` and its
   entrypoint now refuses to start when the old path is a mount point:

   ```
   Error: in 18+, these Docker images are configured to store database data in a
          format which is compatible with "pg_ctlcluster" ...
   ```

   (reproduced directly with `docker run -v /tmp/x:/var/lib/postgresql/data
   postgres:18`; see docker-library/postgres#1259). Postgres exited 1 on every
   start, and because the generated app service inherited `depends_on:
   postgresql`, compose never started the app container at all — the deploy log
   shows `Container project-app-1 Created` and no `Started` line. Nothing was
   ever listening.

The docroot was never the problem: `PhpDocroot` probes `public` first and
Koillection's front controller is `public/index.php`.

## What the recipe does

`overrides/docker-compose.yml` replaces the stack. Writing that filename is
what makes this a compose project — an app config is applied before detection,
and the `compose` platform (priority 980) then wins the walk over `php` (930).

It runs Koillection's **published image** (`koillection/koillection:<version>`,
602 MB) rather than the checkout, because the php strategy cannot build this
app's frontend:

- the Vite bundle's `package.json` is in `assets/`, and `HostCompile::runForPhp`
  only runs the Node pass when the **root** `package.json` declares a `build`
  script;
- that build consumes `assets/js/translations`, which only exists after
  `bin/console app:translations:dump` has run inside a working install — an
  ordering a prepare hook cannot produce.

Building the root Dockerfile here instead would apt-install chromium, run
Composer, run a `node:26` yarn build and download curl-impersonate, inside an
account capped at 2500 MB. The published image is the same software, already
built.

`docker/entrypoint.sh` is the whole install procedure: it runs
`doctrine:migration:migrate`, `app:refresh-cached-values` and
`lexik:jwt:generate-keypair` **before** FrankenPHP binds port 80. That is why
the `ready` gate matters — `docker compose up -d` runs without `--wait`, so the
engine would otherwise probe a container that is still migrating.

PostgreSQL is not optional: `migrations/` holds only `Postgresql/` and `Mysql/`,
so there is no SQLite path.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | Description only — no `extends`, so detection reads the compose file this recipe writes |
| `overrides/docker-compose.yml` | The stack: postgres:18 on a named volume at `/var/lib/postgresql`, the published Koillection image on `8000:80`, a `ready` gate on its healthcheck |
| `hooks/prepare.sh` | `DB_PASSWORD`, `APP_SECRET`, `JWT_PASSPHRASE` and the image tag into `.env` |

## Secrets, and why they are pinned

`docker/entrypoint.sh` writes `.env.local` on every boot with
`${APP_SECRET:-$(openssl rand -base64 21)}` and the same shape for
`JWT_PASSPHRASE`. Left unset:

- `APP_SECRET` rotates on every restart, invalidating every session and every
  remember-me cookie (`security.yaml` signs `remember_me` with it);
- `JWT_PASSPHRASE` rotates too, but `lexik:jwt:generate-keypair
  --skip-if-exists` keeps the keypair it already wrote — so after the first
  `docker restart` the passphrase no longer opens the private key and
  `/api/authentication_token` stops working.

The generated values live in `~/.panelalpha/koillection.env`, **not** in
`~/project`: `GitRepository::cloneConfiguredRepository()` calls
`clearContents()` on the project directory before every clone, while the
postgres and uploads volumes persist. A database password regenerated on
redeploy would be one the database no longer accepts.

The hook also has to *delete before appending*: Koillection commits a root
`.env` with `DB_PASSWORD=koillection` and an empty `APP_SECRET`, and compose
reads that same file for interpolation. Ghost's `if [ ! -f .env ]` guard would
never fire here.

## Verified

Deployed on a 2500 MB account: `deploy-ok`, 75 s end to end (about 45 s of that
is the image pull), loopback `:8000` HTTP 200, domain HTTP 200 titled
"Koillection", all `_baseline` checks pass. `project-ready-1` exited 0 seven
seconds after the app container started, and `RestartCount` stayed 0.

Driven as a user, not just probed: `/` → `/first-connection`, form submitted,
landed authenticated on `/collections`; logged out; logged back in with
`_login` + `_password` and got `PHPSESSID` + `REMEMBERME` back. The session
cookie comes back `secure; httponly; samesite=strict`, which is
`cookie_secure: auto` resolving correctly through `SYMFONY_TRUSTED_PROXIES`.
`/build/*` assets and `/api/docs` answer 200. After `docker restart` of the app
container, `POST /api/authentication_token` still returns a token and the
existing session still works — the point of pinning `JWT_PASSPHRASE` and
`APP_SECRET`.

## Not configured

- **Mail.** Koillection needs no SMTP to run and the first-connection flow sends
  nothing.
- **CORS.** Left at the entrypoint's localhost-only default. It guards `/api`
  only; the web interface is same-origin. Widen `CORS_ALLOW_ORIGIN` yourself if
  you write a browser-based API client.
- **Scraping.** The image ships chromium and curl-impersonate and the feature
  works; it only makes outbound requests.
