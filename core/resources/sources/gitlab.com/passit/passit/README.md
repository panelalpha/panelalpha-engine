# gitlab.com/passit/passit

Passit is an end-to-end-encrypted password manager. One Django/DRF service
(passit-backend) exposes the API and also serves the compiled Angular SPA
(passit-frontend) as whitenoise static files; PostgreSQL stores the data and a
Valkey/Redis cache backs the framework cache and the background-task queue.
It is one product, delivered by upstream as one image.

## One source, no grafting

The tracker Repository URL is the GitLab **group** `gitlab.com/passit`, which
is three repos: `passit-backend` (Django), `passit-frontend` (Angular), and
`passit` (the deployment meta-repo). A recipe may deploy only one source and
may pull official prebuilt images, but may not clone a second source repo and
stitch it in.

Passit does not need grafting: upstream publishes **`passit/passit`**, a single
self-contained image that bundles the backend and the *already-built* frontend.
Verified inside the image:

```
/code/dist/index.html          # Angular build output
/code/static/main.8b0a469c….js # collected + hashed, whitenoise-served
/code/manage.py  /code/bin/start.sh (granian ASGI on :8080)
```

The meta-repo `gitlab.com/passit/passit` is exactly what upstream tells users
to run — its README's "Deploy to DO" button and its own `Dockerfile` are both
`FROM passit/passit:latest` — so the recipe is anchored on it and pulls the
official image. `git ls-remote` on it needs no credentials.

## The image is `stable`, not `latest`

Docker Hub `passit/passit:latest` was pushed **2019-01-11** (Python 3.6,
uWSGI). `stable` was pushed **2026-04-10** (Python 3.14, granian) and is what
the backend's current master builds. `latest` predates the current settings
entirely — it has no Valkey cache/task backend and, measured on the control
deploy below, dies on boot with `ImproperlyConfigured: Set the SECRET_KEY
environment variable` and never loads the app. The recipe pins `stable`;
`PASSIT_IMAGE` in the account env overrides the tag.

## What a bare deploy does, and why the recipe exists

The meta-repo has only a `Dockerfile` (`FROM passit/passit:latest`) and a
DigitalOcean app spec — no runnable compose. So detection reads it as
`dockerfile` and builds one container with **no database, no cache, no
SECRET_KEY and no migrations**. Measured control (recipe hidden, same repo):

- Deploy reported **`success` in 36 s**, then the public domain answered
  **000** (nothing served). The inner container ran uWSGI in "no app loaded /
  full dynamic mode" after `ImproperlyConfigured: Set the SECRET_KEY
  environment variable`, crash-looping. A green deploy in front of a dead site.

The recipe replaces that with the real stack:

- `db` — `postgres:16-alpine`, named volume, `pg_isready` healthcheck, 384 MB.
- `cache` — `valkey:8-alpine`. **Required, not optional:** `settings.py` wires
  `CACHES` to `django_vcache.ValkeyCache` and `TASKS` to
  `django_vtasks.ValkeyTaskBackend`, and registration enqueues the email-
  confirmation code through that task backend, so a missing cache makes sign-up
  500 on enqueue. 128 MB.
- `init` — one-shot: waits for Postgres through Django, runs `migrate
  --noinput`, exits 0. The image's default CMD (`./bin/start.sh`) launches
  granian and never migrates, so without this the first boot answers 500
  against an empty schema. `app` waits on it with
  `service_completed_successfully`, so a failed migration fails the deploy.
- `app` — the official image on 8080, `/_health/` healthcheck (returns "ok"),
  768 MB. Its entrypoint derives `EMAIL_CONFIRMATION_HOST` from the account's
  public URL.
- `ready` — `alpine`, `exit 0`, gated on `app` health, so `docker compose up
  -d` (run without `--wait`) returns only once the site actually answers.

Secrets — `SECRET_KEY` and the database password — are generated once and kept
in `~/.panelalpha/passit/` (0600 in 0700), the only writable place a deploy
does not delete. `~/project` is re-cloned and emptied on every rebuild, so a
key written there would rotate on every deploy (logging everyone out) and a new
DB password would lock the app out of the `pgdata` volume that still holds the
old one.

## First run is self-registration, not a seeded admin

Passit is end-to-end encrypted. `apps/access/models.py` builds each user's RSA
keypair from the **client-side-hashed** password, and the browser app encrypts
every secret under keys only it can derive (`passit_sdk/sdk.py`).
`createsuperuser` builds the keypair from the **raw** password — the code says
so: *"The password won't be usable to login with the client due to lack of
hashing."* So a seeded Django admin is a broken vault account the SPA can never
decrypt. Registration is open (`UserViewSet` → `AllowAny`), which is Passit's
designed onboarding, so the recipe seeds **no** product user: the owner
registers in the SPA, which does the crypto correctly.

Django's `/admin/` is a second, session-only login surface that lists every
registered email, is not the vault, and whose login POST is rejected by CSRF
over the proxy anyway. The recipe turns it off (`ENABLE_DJANGO_ADMIN=False`,
overridable) — measured: `/admin/` → 404.

## SMTP limitation, stated plainly

A registered user cannot open a vault until their email is confirmed
(`HasVerifiedEmail` on `SecretViewSet`), and confirmation is by emailed code.
Upstream lists `EMAIL_URL` / `DEFAULT_FROM_EMAIL` as **required settings** (in
Passit's install docs and the DigitalOcean spec). With no SMTP the code is
written to the app container log instead of sent, so no outside user can
confirm from the internet. This is an operator responsibility — surfaced as env
vars and documented in `~/.panelalpha/passit/credentials.txt` — not a recipe
repair and not grafting. Everything except the email delivery works without it.
(Passit only *sends* mail; it receives none, so the "mail-receiving apps"
policy does not apply.)

## Verified on mariusz.panelalpha.tools, over the public HTTPS domain

- **Deploy 48 s** (image warm), `deploy_strategy: compose`. `/` → 200 with
  `<title>Passit</title>` (SPA), `/api/ping/` → `{"ping":"pong"}`, `/_health/`
  → 200. Chain came up `db→cache→init(exit 0)→app(healthy)→ready(exit 0)`.
  Control (same repo, no recipe): `success` in 36 s, then **000**, app
  crash-looping on the missing SECRET_KEY.
- **Product exercised end to end, with the real E2E crypto** (via
  `passit_sdk`, over the public domain): registered a user, confirmed the email
  with the code read from the DB (the console-backend path), logged in for a
  knox token, **created a secret and read it back decrypted** to its original
  plaintext. The server-stored blob is ciphertext only — the plaintext never
  appears in it.
- **Anonymous refused:** `/api/secrets/` → 403; a non-existent secret → 403,
  no traceback (DEBUG off).
- **Exposure by body:** `/.env`, `/.env.default`, `/.git/config`,
  `/docker-compose.yml`, `/Dockerfile`, `/passit/settings.py`, `/manage.py`,
  `/pa/init.sh`, `/pa/entrypoint.sh`, `/admin/`, `/media/` all 404 with no
  `SECRET_KEY` / password / private key in any body. `/api/conf/` (an
  intentional `AllowAny` endpoint the SPA reads) returns non-secret config only.
- **Redeploy survival (rebuild, 200 / `success`):** the three files in
  `~/.panelalpha/passit/` were **byte-identical** (same sha256) — `prepare.sh`
  reused them — the user and the secret persisted in the `pgdata` volume, and
  the pre-existing account still logged in and **its secret still decrypted**.
- **Memory at idle:** app 110 MiB / 768, db 24 MiB / 384, cache 6 MiB / 128;
  ~140 MiB across the app services, 236 MiB for the whole account container.

## Where things live

| Path | What |
|---|---|
| `~/.panelalpha/passit/app.env` | `SECRET_KEY`, `DATABASE_URL` (with password), 0600. Read by `init` and `app` |
| `~/.panelalpha/passit/db.env` | `POSTGRES_PASSWORD`, 0600. Read by the database container only |
| `~/.panelalpha/passit/credentials.txt` | onboarding + the SMTP requirement, for the account owner |
| `~/project/.env` | tunables (`IS_DEBUG`, `SECURE_SSL_REDIRECT`, `ENABLE_DJANGO_ADMIN`), 0644, merged over by the account's env vars |
| `pgdata` volume | every account, secret and session |

## Known rough edges

- Email confirmation needs operator SMTP (above). Until then a registered
  account cannot be confirmed from outside.
- `ALLOWED_HOST` is left at upstream's default `*` (it is a single-value env, so
  it cannot list both the domain and `127.0.0.1` for the healthcheck).
  Confirmation and reset links are built from `EMAIL_CONFIRMATION_HOST`, not the
  Host header, so this is not a link-poisoning vector; operators who want strict
  Host validation can set `ALLOWED_HOST` to their domain.
- `migrate` prints upstream's own "models have changes not yet reflected in a
  migration" notice; nothing fails.
