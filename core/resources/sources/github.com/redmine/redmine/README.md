# Redmine (github.com/redmine/redmine)

Project management and issue tracking (Ruby on Rails).

## What the recipe does

- The repository ships only `config/database.yml.example`, and its Gemfile
  installs a database adapter only once `database.yml` exists, so a plain
  deploy builds without an adapter or a server gem and restart-loops.
- `overrides/docker-compose.yml` runs the official `redmine:7.0.1` image on
  port 3000. With no `REDMINE_DB_*` variables the image uses SQLite
  (`sqlite/redmine.db`) and runs `db:migrate` on every start.
- Named volumes: `db` (`/usr/src/redmine/sqlite`) and `files` (attachments),
  kept across redeploys.
- `hooks/prepare.sh` writes `REDMINE_SECRET_KEY_BASE` once to
  `~/.panelalpha/redmine/secret.env` (0600) so sessions survive a rebuild.
- `ready` makes `compose up -d` wait until `/login` answers.

## The admin's login

`db:migrate` gives a new database Redmine's built-in `admin` with the password
`admin`, and the Register page only queues an account for an administrator to
activate (`self_registration` is manual by default), so nobody could get in
without being told upstream's default. The engine generates the login
(`credentials:` in `panelalpha.yaml`): `REDMINE_ADMIN_USERNAME` (default
`admin`) and `REDMINE_ADMIN_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`); sign in at
`/login`.

The service's command is `bundle exec /bin/bash /panelalpha-redmine-start.sh`,
so the image's own entrypoint still writes `database.yml`, sets the secret and
runs `db:migrate` first. `files/panelalpha-redmine-start.sh` then runs
`rails runner` with Redmine's `User` model: while the admin still has the
password `admin` it gets the engine's username and password (and no "must
change password" flag). Then it execs `rails server -b 0.0.0.0`, the image's
CMD. A password changed in Redmine is never overwritten.
