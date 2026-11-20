# ABC User Feedback (github.com/line/abc-user-feedback)

LINE's feedback collection and issue tracking tool: a Next.js admin web app,
a NestJS API and MySQL 8, in a pnpm monorepo, run here from upstream's
published images (`line/abc-user-feedback-web`, `line/abc-user-feedback-api`,
both pinned to 8.2644.112, the release of the repository's last commit).

## Deploying

Deploy as is, open the site (it redirects to `/auth/sign-in`) and sign in
with the login the engine generates (`ABC_ADMIN_EMAIL`, `admin@example.com`
unless the project sets it, and `ABC_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). It is the
tenant's super user. The first sign-in lands on "Create Project".

## Why a recipe

The repository has no compose file at its root, so the engine built it as a
Next.js app: only `apps/web` ran, with no API and no database, and the browser
called `http://localhost:4000` (the `NEXT_PUBLIC_API_BASE_URL` of
`apps/web/.env.example`), so the sign-in page could not sign anyone in.

`docker/docker-compose.yml` has the whole stack, for a laptop: the web app on
:3000 calls the API on :4000, every port is published, `JWT_SECRET` (it signs
every admin token) is `jwtsecretjwtsecretjwtsecret` and the MySQL password
is `userfeedback`.

A new install has no tenant. Its setup page posts to `POST /api/admin/tenants`,
which needs no login and makes the tenant and its super user from whatever
email and password it is given, so the install belonged to whoever opened the
address first.

## What the recipe does

- `overrides/docker-compose.yml` replaces the compose files: `mysql:8.0`
  (preview-sized: no performance schema, a 64 MB buffer pool), the API, the
  web app, a one-shot `setup` and an nginx `proxy`, the only published port
  (8080).
- One address: the proxy (`files/panelalpha/abc-user-feedback/nginx.conf`)
  sends `/api/` (every API route; NestJS's global prefix), `/docs` and
  `/admin-docs` (its API documentation) to the API and everything else to
  the web app. The web app's `NEXT_PUBLIC_API_BASE_URL` is `${PA_PUBLIC_URL}`;
  the image's entrypoint writes it into the built JavaScript in place of a
  placeholder at start, so no rebuild is needed. The API's `ADMIN_WEB_URL` and
  `BASE_URL` are the same address.
- `hooks/prepare.sh` generates `JWT_SECRET` and the MySQL password once, in
  `~/.panelalpha/abc-user-feedback/` (`api.env`, `db.env`), kept across
  redeploys: the password is baked into the mysql volume. Nothing connects as
  MySQL's root, whose password is random.
- The first super user: `setup`
  (`files/panelalpha/abc-user-feedback/setup-tenant.mjs`, in the API image)
  waits for the API and, only while no tenant exists, calls
  `POST /api/admin/tenants` with the engine's login and the site name
  "ABC User Feedback", the same call the setup page makes. Once a tenant
  exists it changes nothing, so a password changed in the app stays changed.
  The proxy waits for it, so the address answers only once the super user
  exists and nobody can take the setup page first.

## Limits

- No mail. Signing up (`/auth/sign-up`), inviting a member and resetting a
  password all send a code by email. There is no mail server (upstream's
  smtp4dev could not be read by anyone here), so the API's `SMTP_HOST` is
  `127.0.0.1:25`, where nothing listens: those requests fail at once with an
  error. The super user signs in with a password and can do everything else;
  set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD` and
  `SMTP_SENDER` on the `api` service to a real server to send them.
- No OpenSearch (off upstream too); search runs on MySQL.
- About 550 MB of memory at rest: MySQL about 140 MB, the API about 250 MB,
  the web app about 110 MB, the proxy about 20 MB.
