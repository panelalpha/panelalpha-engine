# HortusFox

Collaborative plant manager: PHP (Asatru framework) under Apache in upstream's
`ghcr.io/danielbrendel/hortusfox-web` image, with MariaDB.

Upstream: <https://github.com/danielbrendel/hortusfox-web>

## What this recipe does

`overrides/docker-compose.yml` replaces the repository's compose file, which
runs `:latest` with the README's default admin (`admin@example.com` /
`password`), fixed database passwords and MariaDB published on 3306.

| Service | What it is |
|---|---|
| `db` | `mariadb:11.4` (LTS), 128 MB buffer pool, not published |
| `app` | `ghcr.io/danielbrendel/hortusfox-web:v6.1`, the current release. Its entrypoint runs the migrations and creates the admin |
| `backup-sweeper` | deletes web backup exports 15 minutes after they are written (see below) |
| `ready` | no-op gated on the app's healthcheck, so the deploy ends once the login form renders |

`hooks/prepare.sh` generates, once, into `~/.panelalpha/hortusfox/` (0600 in 0700):

- `db.env`: MariaDB user and root passwords
- `app.env`: `DB_PASSWORD`

They are kept because the database volume outlives `~/project`. Values are hex
because the image's entrypoint pastes them into SQL and a `php -r` string. The
admin login (`APP_ADMIN_EMAIL`, `APP_ADMIN_PASSWORD`) is declared under
`credentials:` in `panelalpha.yaml`; the engine generates it (letters and
digits) and writes `~/.panelalpha/app-credentials.env`.

## Login

`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns the
e-mail and password. Accounts deployed before the engine owned the login keep
their admin `admin@<site domain>`. The entrypoint creates the admin only while
no admin exists, so a password changed in the UI survives redeploys. There is
no public registration; the admin adds further users.

## Release mode

The entrypoint's migrate and seed steps call the `asatru` CLI, which refuses to
run unless `APP_DEBUG` is true, so the app starts with `APP_DEBUG=true`. The
service's `command` (run by the entrypoint's final `exec "$@"`) then rewrites
the generated `.env` to `APP_DEBUG=false` and sets `display_errors = Off`
before Apache starts, so visitors never see the debug exception page (file
paths and stack traces).

## Backup exports

Admin > Backup writes `public/backup/hf_backup_<Y-m-d_H-i-s>.zip` and never
deletes it. That directory is served, so the archive (locations, plants,
photos, tasks, inventory, calendar) is downloadable by anyone who guesses the
second it was made. `backup-sweeper` deletes exports 15 minutes after they are
written, which bounds the exposure but does not remove it: download an export
right away, and for scheduled backups set a backup path outside `public/` in
the admin settings.

## Persistence

Named volumes, as upstream: `db_data`, `app_images`, `app_attachments`,
`app_logs`, `app_backup`, `app_themes`, `app_migrate`. All survive redeploys.

## Memory

Limits: `app` 512 MB, `db` 512 MB. At idle `app` uses about 60 MB and `db`
about 90 MB.

## Mail

No SMTP is configured, so password-reset and reminder mail is not sent until
the admin enters SMTP settings in the UI.
