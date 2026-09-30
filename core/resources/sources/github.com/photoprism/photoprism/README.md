# PhotoPrism (github.com/photoprism/photoprism)

AI-powered photo library (Go, TensorFlow).

## Deploying

Set the project environment variable `PHOTOPRISM_ADMIN_PASSWORD`, the initial
admin password. It must be 8-72 characters; write any `$` as `$$`. Optionally
set `PHOTOPRISM_ADMIN_USER` (default `admin`). PhotoPrism reads the password
when it creates the admin account on first start. If the variable is unset, the
deploy fails with:

```
photoprism: project environment variable PHOTOPRISM_ADMIN_PASSWORD is missing or not 8-72 characters ...
```

## What the recipe does

- The repository's `Dockerfile` builds the developer image, which has no
  server command, and its `compose.yaml` is the developer stack.
- `overrides/docker-compose.yml` follows upstream's production compose:
  - `app` runs `photoprism/photoprism:260919` on port 2342. TLS is disabled
    because the engine terminates it, and `PHOTOPRISM_SITE_URL` is the site's
    address.
  - `db` runs `mariadb:12.3` with upstream's options and a 256M buffer pool.
  - `env-check`, a one-shot service, fails the deploy while the admin
    password is unset.
  - `ready` makes `compose up -d` wait until `photoprism status` passes.
- `hooks/prepare.sh` writes the database password once, to
  `~/.panelalpha/photoprism/{app,db}.env` (0600).
- Named volumes: `originals`, `storage` and `database`, all kept across
  redeploys.
