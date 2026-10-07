# github.com/papra-hq/papra

Papra is a minimalist document storage and archiving platform. One Node (Hono)
server serves the API and the built SolidJS client on port 1221; the database
is embedded SQLite (libsql) and documents are stored on the local filesystem.

## Why a compose-replace from the official image

The repository is a pnpm monorepo with no compose file. Bare detection picks
Railpack, which builds for minutes and then serves nothing.
Upstream's release workflow publishes the same image to Docker Hub
(`corentinth/papra`) and GHCR (`ghcr.io/papra-hq/papra`). The recipe runs
`docker.io/corentinth/papra:26.6.2-rootless`, the current release (Docker Hub
rather than GHCR, whose image seed fails with NotFound).

## Services

| Service | What it does |
|---|---|
| `seed` | One-shot, first boot only. Runs migrations, starts Papra bound to 127.0.0.1 inside its own container with registration enabled, creates the owner through `POST /api/auth/sign-up/email`, stops it, and writes `/app/app-data/.panelalpha-owner-seeded`. Every later boot sees the marker and exits 0 |
| `app` | The official image, registration disabled, `APP_BASE_URL` set to the public URL, SQLite and documents on the `papra-data` volume, healthcheck on `/api/health` via node `fetch` (no curl in the image), 768 MB |
| `ready` | Exits 0 once `app` is healthy, so `compose up -d` returns only when Papra answers |

## First run is closed

Papra's defaults are `AUTH_IS_REGISTRATION_ENABLED=true` and
`AUTH_FIRST_USER_AS_ADMIN=true`, so the first visitor to sign up becomes the
platform admin. The published app never runs with registration enabled. The
owner's login is declared under `credentials:` in `panelalpha.yaml`; the
engine generates it into `~/.panelalpha/app-credentials.env`, and
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns it;
the owner is `admin@<account domain>`. Email verification is off by default
(`AUTH_IS_EMAIL_VERIFICATION_REQUIRED=false`), so login works without SMTP.
To let more people in, invite them from an organization or set
`AUTH_IS_REGISTRATION_ENABLED=true` in the project env.

## Secrets and persistence

| Path | What |
|---|---|
| `~/.panelalpha/papra/app.env` | `AUTH_SECRET` (signs sessions; without it Papra uses a built-in default), read by `seed` and `app` |
| `~/.panelalpha/app-credentials.env` | `PAPRA_ADMIN_EMAIL`, `PAPRA_ADMIN_PASSWORD` (the engine's), read only by `seed` |
| `papra-data` volume | `db/db.sqlite` and `documents/` |

## Known rough edges

- The `*.panelalpha.online` test edge stalls every `multipart/form-data` POST,
  so uploads through a test name fail with a 302 to
  withoutdns.com. A real domain is not affected.
- Emails (password reset, invitations) are dry-run until the operator
  sets `EMAILS_DRY_RUN=false`, `EMAILS_DRIVER` and the `SMTP_*` settings in the project env.
