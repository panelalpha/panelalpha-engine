# LeafWiki (github.com/perber/leafwiki)

Markdown-on-disk wiki with tree navigation (Go, single binary with embedded UI,
SQLite indexes).

## Deploying

Set one project environment variable, the initial admin password:

- `LEAFWIKI_ADMIN_PASSWORD`: write any `$` as `$$` (compose interpolates it).
- `LEAFWIKI_ADMIN_USERNAME` (optional, default `admin`).

They are applied only while no admin exists. Without the password the deploy
fails with:

```
leafwiki: missing project environment variable(s): LEAFWIKI_ADMIN_PASSWORD. ...
```

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/perber/leafwiki:v0.13.0` on
  port 8080 with `/app/data` (pages, assets, users) on the named volume
  `leafwiki-data`, kept across redeploys.
- `hooks/prepare.sh` generates `LEAFWIKI_JWT_SECRET` once into
  `~/.panelalpha/leafwiki/app.env`, so sessions survive redeploys.
- `env-check` (same image, one-shot) runs `files/leafwiki-env-check.sh`; the
  app starts only once it passes. `ready` makes `compose up -d` wait for
  `/api/health`.
- Auth cookies are `Secure`; the app trusts the engine's
  `X-Forwarded-Proto: https`, so `LEAFWIKI_ALLOW_INSECURE` is not set.
