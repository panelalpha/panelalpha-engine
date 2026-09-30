# VibraVid (github.com/astraelabs/vibravid)

Web downloader for films, series, anime and music (Django + SQLite, the
image's own `runserver` on :8000).

Plain deploy: the repository compose runs `ghcr.io/astraelabs/vibravid:latest`
with `ALLOWED_HOSTS: "localhost,127.0.0.1,vibravid"` as a literal, so the site
domain gets Django's `Bad Request (400)` (143 bytes); project env vars cannot
override a literal in `environment:`.

## What the recipe does

- `overrides/docker-compose.yml`: the release image `v1.4.4`, `ALLOWED_HOSTS`
  and `CSRF_TRUSTED_ORIGINS` from `${PA_PUBLIC_HOST}`/`${PA_PUBLIC_URL}`, plus
  upstream's documented reverse-proxy flags (`USE_X_FORWARDED_HOST`,
  `SECURE_PROXY_SSL_HEADER_ENABLED`, secure cookies).
- Drops the `/var/run/docker.sock` mount (in-app "Update now"; redeploy
  instead) and the optional `bypasser`/`telegram` profile services.
- Upstream's named volumes for the database, `Conf`, downloads, logs and
  fetched binaries. `DJANGO_SECRET_KEY` is left unset: the app writes its own
  into `/app/data/.django_secret_key` on the database volume.
- `.env` as an optional env_file, so `TMDB_API_KEY` set on the project reaches
  the app. A no-op `ready` service waits for the healthcheck.

## First run

Upstream's: no login, the search page opens directly.
