# Ocular (github.com/simonwep/ocular)

Budgeting app: Vue SPA served by Caddy, Go backend ("genesis") storing data as
files under `/data/genesis`.

## Deploying

Nothing is required. To get a first login, set the project environment
variable `GENESIS_CREATE_USERS` before (or at any) deploy:

```
GENESIS_CREATE_USERS=admin!:a-long-password
```

`!` after the name makes the user an admin; separate several users with `,`.
Users that already exist are left alone. Write any `$` as `$$`.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/simonwep/ocular:v2.4.1` on
  port 80 with `/data/genesis` on the named volume `ocular-data`.
- `hooks/prepare.sh` generates `GENESIS_JWT_SECRET` once into
  `~/.panelalpha/ocular/genesis.env`; `GENESIS_JWT_TOKEN_EXPIRATION` is 60
  (genesis panics at boot when it is unset, which is why the plain deploy of
  the repository's Dockerfile crash-looped).
- `ready` makes `compose up -d` wait for the image's healthcheck.
