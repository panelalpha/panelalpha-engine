# BookWyrm (github.com/bookwyrm-social/bookwyrm)

Federated social reading platform (Django, Celery, Postgres, Redis).

## Deploying

No variables are required; give the project about 2.5 GB of memory (~850 MB
at idle). The first visit redirects to `/setup`, which asks for the instance
admin code, printed by:

    docker compose -p project exec web python manage.py admin_code

Optional SMTP: `EMAIL_HOST`, `EMAIL_PORT` (587), `EMAIL_HOST_USER`,
`EMAIL_HOST_PASSWORD`, `EMAIL_USE_TLS` (true), `EMAIL_SENDER_NAME`.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's production stack built from the
  repository's Dockerfile: nginx with upstream's `reverse_proxy.conf` and
  Anubis, gunicorn, Celery worker and beat, flower at `/flower/`, Postgres 17
  and two Redis. certbot and the backup job are left out; the engine
  terminates TLS.
- `hooks/prepare.sh` writes SECRET_KEY, the Postgres, Redis and flower
  passwords once to `~/.panelalpha/bookwyrm/bookwyrm.env` (0600), which every
  service reads as an env_file. The flower login is `admin` / FLOWER_PASSWORD
  from that file.
- Static files, uploaded images, exports, Postgres and Redis data are named
  volumes.

## Upstream behaviour kept

Anubis sends browsers through a proof-of-work page on the first visit. Every
visitor reaches nginx from the engine's address, so upstream's login rate limit
(1 request/s per client address) is shared by all visitors.
