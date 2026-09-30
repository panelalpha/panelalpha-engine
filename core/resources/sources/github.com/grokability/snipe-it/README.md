# Snipe-IT

IT asset management: Laravel under Apache in upstream's `snipe/snipe-it`
image, with MariaDB.

Upstream: <https://github.com/grokability/snipe-it>

## What this recipe does

`overrides/docker-compose.yml` replaces the repository's compose file. That
file is upstream's production stack, but it reads everything from a `.env` the
checkout does not ship, so MariaDB started with `MYSQL_ROOT_PASSWORD` unset.
The replacement runs the same two services plus two one-shots:

| Service | What it is |
|---|---|
| `db` | `mariadb:11.4.7` (upstream's pin), 128 MB buffer pool |
| `app` | `snipe/snipe-it:v8.7.2`, the current release (`latest` tracks master). Its own startup runs `artisan migrate` |
| `setup` | completes the setup wizard with the generated admin, then exits |
| `ready` | no-op gated on `setup`, so the deploy ends once the admin exists |

`hooks/prepare.sh` generates, once, into `~/.panelalpha/snipeit/` (0600 in 0700):

- `db.env`: MariaDB user and root passwords
- `app.env`: `APP_KEY` (`base64:<32 bytes>`) and `DB_PASSWORD`
- `admin.env`: `SNIPEIT_ADMIN_USER=admin` and its password

They are kept because the database and storage volumes outlive `~/project`.

## Login

User `admin`, password `SNIPEIT_ADMIN_PASSWORD` in
`~/.panelalpha/snipeit/admin.env`. Its e-mail is `admin@<domain>`. You can
change both in the UI; redeploys do not reset them.

## Closing the setup wizard

Until a user **and** the settings row exist, every URL redirects to `/setup`,
and whoever gets there first creates the superuser. `artisan
snipeit:create-admin` creates only the user, not the settings row, so the
wizard would stay open. The `setup` service goes through the wizard over the
internal network instead: `POST /setup/migrate` (this also creates the Passport
keys the API needs) and then `POST /setup/user`. Once that is done, `/setup*`
redirects to the site. If setup is already complete, `setup` does nothing.

## Configuration

| Setting | Why |
|---|---|
| `APP_URL: ${PA_PUBLIC_URL}` | Laravel builds redirects and links from it |
| `APP_TRUSTED_PROXIES` private ranges, `APP_TRUSTED_HEADERS` | honours the engine proxy's `X-Forwarded-Proto`, so links are https |
| `SECURE_COOKIES=true` | the site is only served over HTTPS |
| `MAIL_MAILER=log` | the account has no mail service; set `MAIL_*` to send |

## Persistence

`storage` volume at `/var/lib/snipeit`: uploads, backups and OAuth keys.
`db_data` holds MariaDB. Both survive redeploys.

## Memory

Limits: `app` 768 MB, `db` 512 MB. At idle `app` uses about 130 MB and `db`
about 100 MB.

## Known limitation

On a `*.panelalpha.online` test domain, file uploads (multipart POSTs) fail at
the shared test edge (engine#170). Uploads work through the engine host itself
or through a real domain.
