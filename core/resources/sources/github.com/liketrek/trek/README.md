# TREK (github.com/liketrek/trek)

Self-hosted travel and trip planner: Node.js with SQLite under `/app/data` and
uploads under `/app/uploads`, on port 3000.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`ADMIN_EMAIL`, default `admin@example.com`, and `ADMIN_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). TREK
asks for a new password after that first sign-in, as it does for every
seeded admin.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's compose on the published image
  `mauriceboe/trek:4.3.3`, with `/app/data` and `/app/uploads` on named
  volumes and without the `cap_drop`/`cap_add` pair (see `panelalpha.yaml`).
- The engine's login is a second `env_file` after the project's `.env`.
  TREK's first-run seed (`seedAdminAccount`) creates the `admin` user with
  `ADMIN_EMAIL` / `ADMIN_PASSWORD` only while the `users` table is empty;
  without them it makes `admin@trek.local` with a random password printed
  only to the container log. Once a user exists the variables are ignored, so
  a password changed in TREK is never overwritten. A value the project's own
  env vars set for either variable wins over the generated one.
