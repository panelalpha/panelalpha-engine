# BugPin (github.com/aranticlabs/bugpin)

Self-hosted visual bug-reporting tool. **Bun + Hono + SQLite, one container**,
listens on 7300. All state lives under `/data`: the SQLite database
(`bugpin.db`), uploaded screenshots/attachments (`uploads/`), and BugPin's own
auto-generated signing key (`.secret`). There is no external database.

## Strategy: compose REPLACE (`overrides/docker-compose.yml`), official image

The upstream `docker-compose.yml` is a single service on the official image
with a host bind mount (`./data`). We replace it with the same image on a
**named volume** (`bugpin_data`), which survives `project_rebuild` and
storage-reclaim. No sidecar is needed — BugPin ships no external datastore —
and no secret is injected: BugPin generates and persists its own `.secret` on
the volume on first boot.

## First admin

BugPin hardcodes `admin@example.com` / `changeme123` (`src/server/config.ts`)
and seeds it on first boot when the users table is empty
(`auth.service.ts` `bootstrapAdmin`). There is **no env override** and **no
public registration** — accounts are created only by the admin — so the
documented default is the only account-takeover path.

`hooks/prepare.sh` generates a strong password once into
`~/.panelalpha/bugpin/admin.env` (0600, reused on every redeploy). The
`bugpin-init` one-shot service (`files/docker/pa-seed.ts`) runs BugPin's own
schema/migration code on the `/data` volume and inserts the admin **before**
the app starts, so the default credential is never written. The seed is
idempotent (no-op once any user exists), so a redeploy never disturbs the
operator's real account. Login is at `/admin/`.

## Persistence

`bugpin_data:/data` holds the DB and uploads together; both survive
`project_rebuild`. Verified: a created ticket and an uploaded screenshot both
persisted across a rebuild.

## SMTP

Optional. BugPin runs fully without it; SMTP is only needed for reporter
notification emails and admin-initiated user invitations. Not configured here.
