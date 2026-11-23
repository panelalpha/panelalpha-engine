# linkding (github.com/sissbruecker/linkding)

Bookmark manager: Django with SQLite under `/etc/linkding/data`, on port 9090.

## Deploying

Deploy as is, open `/login/` and sign in with the login the engine generates
(`LD_SUPERUSER_NAME`, default `admin`, and `LD_SUPERUSER_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.yml`
  (`sissbruecker/linkding` on 9090, the project's `.env`) with:
  - the image pinned to the current release, `1.47.0`, in place of `:latest`.
  - the engine's login as a second `env_file`. linkding's `bootstrap.sh` runs
    `manage.py create_initial_superuser`, which creates `LD_SUPERUSER_NAME`
    as a superuser with `LD_SUPERUSER_PASSWORD` only while no user of that
    name exists, so a password changed in linkding is never overwritten.
  - `/etc/linkding/data` on the named volume `data` instead of `./data`,
    which sits inside `~/project` and is wiped by every redeploy (with it the
    database: the bookmarks and any changed password).
  - `LD_CSRF_TRUSTED_ORIGINS` defaulting to the public address (`PA_PUBLIC_URL`;
    a value set in the project's env vars wins). linkding does not trust the
    proxy's `X-Forwarded-Proto`, so Django sees a plain http request, compares
    it with the browser's https `Origin` and refuses the login form with a 403
    unless the origin is trusted.
