# NeonLink (github.com/alexscifier/neonlink)

Self-hosted bookmark service (Node/Fastify, SQLite).

## Deploying

Nothing to set. Open the site and register: upstream makes the first
registered user the admin.

## What the recipe does

- `overrides/docker-compose.yml` runs `alexscifier/neonlink:v1.4.21` on port
  3333. `/app/data` (SQLite database, `secrets.json`, `settings.json`) and
  `/app/public/static/media/background` (uploaded backgrounds) are on the named
  volumes `neonlink-data` and `neonlink-background`, kept across redeploys.
- The repository's compose bind-mounts `./data` and `./background` into
  `~/project`, which every deploy empties. The image runs as `node` (uid 1000),
  which cannot write there, so the app crash-looped with
  `EACCES: permission denied, open '/app/data/secrets.json'`.
- The image ships neither directory, so Docker creates the volumes root-owned.
  `volume-init` (same image, one-shot, root) chowns them to uid 1000 before the
  app starts. `ready` makes `compose up -d` wait for the app to answer.
