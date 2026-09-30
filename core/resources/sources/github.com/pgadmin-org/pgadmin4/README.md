# pgAdmin 4 (github.com/pgadmin-org/pgadmin4)

Web administration tool for PostgreSQL (Flask/gunicorn, SQLite config DB).

## Deploying

Set two project environment variables, the initial admin login:

- `PGADMIN_DEFAULT_EMAIL`: an email address on a real domain (pgAdmin rejects
  special-use domains such as `.local`).
- `PGADMIN_DEFAULT_PASSWORD`: write any `$` as `$$` (compose interpolates it).

They are read only when `pgadmin4.db` is first created. Without them the deploy
fails with:

```
pgadmin: missing project environment variable(s): PGADMIN_DEFAULT_EMAIL PGADMIN_DEFAULT_PASSWORD. ...
```

## What the recipe does

- `overrides/docker-compose.yml` runs `dpage/pgadmin4:9.18` on port 8080
  (`PGADMIN_LISTEN_PORT`) with `/var/lib/pgadmin` on the named volume
  `pgadmin-data`, kept across redeploys.
- `env-check` (same image, one-shot) runs `files/pgadmin-env-check.sh`; the app
  starts only once it passes. `ready` makes `compose up -d` wait for
  `/misc/ping`.
- The repository's own Dockerfile is not built: its webpack step ran out of
  memory in a 2000 MB account.
