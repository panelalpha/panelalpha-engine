# github.com/papra-hq/papra

Papra is a minimalist document storage and archiving platform. One Node (Hono)
server serves the API and the built SolidJS client on port 1221; the database
is embedded SQLite (libsql) and documents are stored on the local filesystem.

## Why a compose-replace from the official image

The repository is a pnpm monorepo with no compose file. Bare detection picks
Railpack, which built for ~220s in the batch test and then served nothing.
Upstream's release workflow publishes the same image to Docker Hub
(`corentinth/papra`) and GHCR (`ghcr.io/papra-hq/papra`). The recipe runs
`docker.io/corentinth/papra:26.6.2-rootless`, the current release (Docker Hub
rather than GHCR because of engine#229).

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
owner is `admin@<account domain>`, and its generated password is in
`~/.panelalpha/papra/credentials.txt`. Email verification is off by default
(`AUTH_IS_EMAIL_VERIFICATION_REQUIRED=false`), so login works without SMTP.
To let more people in, invite them from an organization or set
`AUTH_IS_REGISTRATION_ENABLED=true` in the project env.

## Secrets and persistence

| Path | What |
|---|---|
| `~/.panelalpha/papra/app.env` | `AUTH_SECRET` (signs sessions; without it Papra uses a built-in default), read by `seed` and `app` |
| `~/.panelalpha/papra/admin.env` | `PAPRA_ADMIN_PASSWORD`, read only by `seed` |
| `~/.panelalpha/papra/credentials.txt` | owner login for the account holder |
| `papra-data` volume | `db/db.sqlite` and `documents/` |

## Verified on mariusz.panelalpha.tools (engine 705f250a), 2026-09-28

- Deploy `success` in 87s cold, strategy `compose`. `/` returned
  `<title>Papra - Document archiving and sharing platform</title>` (5007 B).
- `/api/config` returned `isRegistrationEnabled:false`. An anonymous sign-up
  returned 400 `EMAIL_PASSWORD_SIGN_UP_DISABLED`. A wrong password returned 401.
- The owner logged in and holds the admin permissions (`bo:access`,
  `users:view`, ...). It created an organization and uploaded a text document,
  then downloaded it byte-identical.
- After a rebuild (`success`, 37s) the three `~/.panelalpha/papra` files kept
  the same sha256, `seed` logged `owner already seeded`, and the owner logged
  in and downloaded the same document.
- `/.env`, `/.git/config`, `/db.sqlite`, `/app-data/db/db.sqlite`,
  `/package.json` and `/docker-compose.yml` return only the SPA shell, with no
  secrets or SQLite bytes. Anonymous `GET /api/organizations/<id>/documents`
  returned 401.
- Idle memory: app 301 MiB of 768.

## Known rough edges

- The `*.panelalpha.online` test edge stalls every `multipart/form-data` POST
  (engine#170), so uploads through a test name fail with a 302 to
  withoutdns.com. The upload above was run against the engine host directly
  (`--resolve <domain>:443:178.104.84.45`), where it answered 200. A real
  domain is not affected.
- Emails (password reset, invitations) are dry-run until the operator
  sets `EMAILS_DRY_RUN=false`, `EMAILS_DRIVER` and the `SMTP_*` settings in the project env.
