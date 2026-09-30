# mygpo / gpodder.net (github.com/gpodder/mygpo)

The Django web service behind gpodder.net: podcast directory, web UI and the
subscription/episode sync API used by gPodder, AntennaPod and others.

## What the recipe does

The repository ships no Dockerfile or compose file, so
`overrides/docker-compose.yml` runs the checkout itself:

- `web`: `python:3.12.14-bookworm` (has the libpq/compiler bits that
  `psycopg2cffi` builds against). `files/mygpo-start.sh` installs
  `requirements.txt` into a venv on the `mygpo-venv` volume on first boot, and
  again only when the file changes; every start runs `migrate` and
  `collectstatic`, then gunicorn on 8000.
- `worker`: the same image and venv running Celery with the embedded beat
  (database scheduler). Subscribing from the web UI and adding podcasts queue
  tasks here.
- `db` (PostgreSQL 16, `django.contrib.postgres` is used) and `redis` (the Celery broker).
- `app`: nginx on 8080 serving the collectstatic output (Django does not serve
  static files with `DEBUG` off) and proxying everything else to gunicorn.
- `ready` holds `compose up` until the site answers (first boot ~2 min).

`hooks/prepare.sh` generates `SECRET_KEY` and the database password once
into `~/.panelalpha/mygpo/` (0600), delivered with `env_file`. Media, the
database, Redis and the venv are on named volumes.

## Settings the owner may want

- `EMAIL_BACKEND`: defaults to the console backend (activation mails go to the
  `web` log), because mygpo has no SMTP host setting of its own.
- `FEEDSERVICE_URL`: podcast metadata is parsed by the separate
  mygpo-feedservice; the upstream default `http://feeds.gpodder.net/` answered
  404 on 2026-09-30, so new podcasts keep an empty title until one is set.
- Admin: `docker compose -p project exec web /opt/venv/bin/python /src/manage.py createsuperuser`.
