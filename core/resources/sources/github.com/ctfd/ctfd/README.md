# CTFd (github.com/ctfd/ctfd)

Capture-the-flag platform: challenges, scoreboard, teams (Flask/gunicorn,
MariaDB, Redis).

## Deploying

No project environment variables are needed. After the deploy, open the site:
CTFd's own setup wizard (`/setup`) asks for the event name and creates the
first admin.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `ctfd/ctfd:3.8.7` image on
  port 8000 with `mariadb:11.8` and `redis:8.2-alpine`. Uploads, the database
  and Redis live on the named volumes `uploads`, `db` and `cache`, kept across
  redeploys.
- `hooks/prepare.sh` generates the database passwords and CTFd's `SECRET_KEY`
  once into `~/.panelalpha/ctfd/` (0600), so a rebuild keeps sessions valid.
- `ready` makes `compose up -d` wait until `/healthcheck` answers (the image
  runs `flask db upgrade` on boot).
- `REVERSE_PROXY=true` makes CTFd trust one proxy hop (the last one, which the
  account proxy adds) for the scheme and client address.

The repository's own compose is not used: the engine rewrites its
`DATABASE_URL=mysql+pymysql://...` to a generic `mysql://...`, which the image
cannot load (`No module named 'MySQLdb'`).
