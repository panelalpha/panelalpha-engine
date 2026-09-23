# LinkAce

A self-hosted bookmark manager — Laravel 11 served by Caddy and php-fpm under
supervisord, with a MariaDB schema and a four-step web setup wizard.

Upstream: <https://github.com/Kovah/LinkAce>

## What this recipe does

`overrides/docker-compose.yml` replaces the repository's own compose file with
a three-service stack: `linkace/linkace:<major>.x` from Docker Hub, MariaDB
12, and a no-op `ready` service that holds `docker compose up -d` open until
LinkAce answers. `hooks/prepare.sh` generates `APP_KEY` and the database
passwords into `.env`, which is read for compose interpolation only.

## First run

Open the site. LinkAce redirects to `/setup/start` and walks through four
steps:

1. **Welcome**
2. **Requirements** — PHP version and extensions, all satisfied by the image
3. **Database** — the form arrives prefilled from the container's environment,
   including the generated password. Nothing needs typing; submit it. This
   runs `migrate:fresh`.
4. **Account** — you choose the admin username, e-mail and password here, and
   are logged in immediately.

**No credentials are shipped or generated for a human.** LinkAce has no
default admin login, and this recipe does not create one: the first account is
whatever the person who opens the site enters in step 4. That also means the
wizard is open to whoever reaches the site first — finish it right after the
deploy.

If the wizard cannot complete (it needs `/app/.env` writable, which it is
inside the image), the same thing can be done from the CLI:

```
docker compose exec app php artisan migrate --force
docker compose exec app php artisan setup:complete
docker compose exec app php artisan registeruser <name> <email> <password> --admin
```

## What the deploy needed that the engine could not infer

| Value | Why |
|---|---|
| `APP_KEY` | `SetupCheckMiddleware` rewrites `/app/.env` and re-redirects whenever `config('app.key')` still equals its `GENERIC_APP_KEY` constant. An environment variable beats a `.env` line, so the rewrite never takes effect and the site redirects to itself forever. |
| `TRUSTED_HOSTS=.*` | LinkAce registers `TrustHosts` globally and defaults it to `APP_URL`'s host. The engine probes the app at `http://127.0.0.1:<port>/` with no `Host` header, which Symfony then rejects with a 400. |
| `TRUSTED_PROXIES=*` | Without it Laravel ignores the proxy's `X-Forwarded-Proto`, generates `http://` links on an https site and does not mark the session cookie secure. |
| `APP_URL` | Written as `http://localhost` so `ComposePlaceholders` substitutes the account's public https URL; the hook could not, because hooks run before the domain exists. |
| The image | The checkout is not a runnable LinkAce (no `public/build`), and both release Dockerfiles build `FROM linkace/base-image`, which this repository does not contain. |
| Not migrating at boot | A pre-migrated database makes the wizard's `databaseHasData()` true and forces an "overwrite existing data" confirmation on a database that is empty. |

## Deliberately left out

- **Meilisearch.** `APP_SEARCH_DRIVER=database` uses MariaDB full-text search,
  which is LinkAce's own default. Meilisearch is a fourth container and a
  second data volume for a search index this size.
- **Redis.** Sessions and cache stay on the filesystem, inside the persisted
  `/app/storage` volume.
- **Mail.** LinkAce boots and the wizard works without SMTP, but user
  invitations, password-reset mail and the contact form need a mail service the
  engine does not provide. Set `MAIL_*` to use them.
- **The cron endpoint.** LinkAce runs link checks, thumbnail refreshes and
  backups from `GET /cron/{token}` (the token is in Admin → System Settings)
  or `php artisan schedule:run`. Nothing calls either here; bookmarks work,
  the periodic jobs do not.

## Known consequences

- `LOG_CHANNEL=stderr` sends Laravel's log to `docker logs` so a failed boot
  is visible to `container_service_logs`. The trade is that the admin log
  viewer at `/admin/logs`, which reads `storage/logs`, stays empty.
- The wizard writes the database credentials back into the image's `/app/.env`,
  which is not persisted. Nothing depends on that copy — every value there is
  also an environment variable, and "setup completed" lives in the database.

## Memory

`app` 768 MB, `db` 512 MB, `ready` 64 MB — about 1.35 GB of a 2.5 GB account.
MariaDB runs with a 128 MB buffer pool and `performance_schema` off, because
its defaults assume a machine it does not share.
