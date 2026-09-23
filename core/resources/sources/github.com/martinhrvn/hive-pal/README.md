# github.com/martinhrvn/hive-pal

Hive-Pal — a self-hosted beekeeping / apiary manager (track apiaries, hives,
inspections, queens, harvests). NestJS API + React SPA, backed by PostgreSQL.

## Strategy: compose-REPLACE, official all-in-one image

Upstream ships **one** published image, `ghcr.io/martinhrvn/hive-pal`, that
bundles the API and the React frontend and serves both on port `3000` (the
backend serves the built SPA as static files). `overrides/docker-compose.yml`
replaces the repo's source-build compose with that prebuilt image (pinned to
`0.18.0`) plus a `postgres:16-alpine` sidecar. No source build, no grafting, no
second repo — a single origin, so no fronting nginx is needed.

## Secrets and the public URL

`hooks/prepare.sh` generates the DB password, the Better Auth session secret and
the admin password **once** into `~/.panelalpha/hive-pal/secrets.env` (0600) and
reuses them on every redeploy — `~/project` is wiped each deploy (engine#173), so
regenerating them would log everyone out or lock the app out of its pgdata
volume. The generated admin login is also written to
`~/.panelalpha/hive-pal/admin-credentials.txt` (0600).

`BETTER_AUTH_URL` and `FRONTEND_URL` are set to `http://localhost` in the compose
`environment:` and rewritten to the account's public https URL by the engine's
`ComposePlaceholders` (both keys end in `_URL`, the value matches the local-host
pattern, port 80 is allow-listed). `BETTER_AUTH_URL` is where `/api/auth/*` is
served and the trusted origin for session cookies; `FRONTEND_URL` is used in
email links.

## First user / admin

The image (pinned `0.19.0`, the release whose entrypoint carries the Better Auth
seed) waits for postgres, runs `prisma migrate deploy`, then runs `seed-admin.js`,
which creates the owner admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD` **before the
app starts serving** — so the admin exists before the site is publicly reachable
and there is no race for the admin address. The generated login is written to
`~/.panelalpha/hive-pal/admin-credentials.txt`. Better Auth email+password sign-in
(`/api/auth/sign-in/email`) works immediately; the admin gets the `ADMIN` role.

Two upstream behaviours to know about (neither is patched — configuration only):

- **The seed logs `Admin seeding failed (continuing startup)` but still creates a
  working admin.** `seed-admin.js` instantiates Better Auth outside the Nest
  context, so a post-create event-emit hook throws on an unwired `EventEmitter`
  *after* the user, its credential and its default apiary have already committed.
  Verified: the seeded admin logs in with the generated password and reads back
  data. As a further backstop, Better Auth's `session.create` hook self-heals —
  anyone who signs in with `ADMIN_EMAIL` is (re)promoted to `ADMIN` — so even a
  genuine seed rollback is recoverable by signing in once with that address.
- **Self-registration is open by app design** (`emailAndPassword.enabled`, no
  `disableSignUp`, and no env toggle to close it). Additional visitors can sign up
  as ordinary `USER`s, but all apiary/hive data is strictly scoped to the owning
  user, and anonymous requests are refused (`401`). Closing signup would require
  patching upstream source and is out of scope.

Magic-link and password-reset need SMTP (see `.env.example` `SMTP_*`); out of
scope here and left unconfigured — email+password sign-in does not need it.

## Persistence

- Postgres data on the named volume `hivepal-pgdata` (survives rebuild).
- Uploaded hive / inspection images: `STORAGE_TYPE=local`,
  `STORAGE_LOCAL_PATH=/data/uploads`, on the named volume `hivepal-uploads`
  (not S3, not `~/project`).

## Left disabled (optional upstream features)

- Ollama / whisper AI (a separate `ai-app` image + `docker-build-ai.yml`) — not
  part of the prod image; no LLM pulled.
- S3 audio storage (`STORAGE_TYPE=s3`, `S3_*`) — local storage used instead.
- Sentry / Grafana Faro telemetry (`SENTRY_*`, `VITE_FARO_*`) — unset.
- HiveScale / HiveHub device integration (`HIVEHUB_*` / `HIVESCALE_*`) — unset.

## Health

`app` has a healthcheck on `GET /api/health`; `postgres` on `pg_isready`, and the
app gates on `postgres: condition: service_healthy` (engine#204).
