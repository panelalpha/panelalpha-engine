# CortenDesk (github.com/marcpope/cortendesk)

A RustDesk console: Laravel on nginx and PHP-FPM, with the RustDesk ID server
(hbbs) and relay (hbbr) in the same image, and MySQL 8.4.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`CORTENDESK_ADMIN_USER`, default `admin`, and `CORTENDESK_ADMIN_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's `docker-compose.yml` runs as before (its Dockerfile built,
  console on 8080, MySQL beside it, `/data` and the database on named
  volumes).
- `overrides/docker-compose.override.yml` adds the engine's login as
  `env_file`. On a database with no user the entrypoint's `DockerSeeder`
  creates the first admin from `CORTENDESK_ADMIN_USER` /
  `CORTENDESK_ADMIN_PASSWORD`; without them it was `admin` / `changeme`, the
  login the README prints.
- The seeder does nothing once a user exists, so a password changed in the
  console stays changed. A database seeded before this recipe keeps its
  `admin` / `changeme` until someone changes it there.
