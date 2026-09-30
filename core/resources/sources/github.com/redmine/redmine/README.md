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

The first login is upstream's default `admin` / `admin`; Redmine asks for a
new password on first sign-in.
