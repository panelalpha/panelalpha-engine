# Genealogy (MGeurts)

Laravel 13 / Livewire 4 family-tree manager on Jetstream Teams. PHP 8.4, MySQL.
`extends: laravel` (php strategy, docroot `public`).

What this recipe adds over the shipped `laravel` recipe:

- **`database: mysql`** — relational data (people, teams, users, sessions,
  cache, queue) on the account's own MySQL, which survives a redeploy. Without
  it Laravel falls back to a SQLite file in `~/project`, which does not.
- **`hooks/prepare.sh`** — creates the `~/.panelalpha/genealogy` bind-mount
  source with account ownership before the container starts.
- **`overrides/docker-compose.override.yml`** — mounts that directory at
  `/app/.pa-data`.
- **`files/panelalpha/persist.sh`** (before install/upgrade, every boot) —
  symlinks `storage/app` (photos, GEDCOM, backups) and `public/storage` onto
  the mount, and restores a generated `APP_KEY` into `.env` so it is stable
  across redeploys (a lost key breaks 2FA secrets and all sessions).
- **`files/panelalpha/seed.sh`** (install only) — seeds Settings and Gender
  reference data (never the demo seeder, which ships known-password accounts)
  and creates one owner account (`is_developer=true`, personal team) with a
  password generated once into `~/.panelalpha/genealogy/owner-password` (0600).

## Onboarding / auth

Registration is **open by Jetstream design**. New sign-ups get an isolated
personal team and zero privileges (`is_developer` is a database column, never
granted at registration), so open registration exposes no existing family data.
The owner account created by `seed.sh` is the admin. Email verification is not
enforced (`User` does not implement `MustVerifyEmail`), so no SMTP is required;
configure `MAIL_*` if you want password-reset / invitation email.

Owner password: `cat ~/.panelalpha/genealogy/owner-password` on the account.
