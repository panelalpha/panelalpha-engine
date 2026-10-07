# Bagisto (github.com/bagisto/bagisto)

Laravel e-commerce platform: storefront and admin panel, MySQL.

## What the engine does on its own

The shipped `laravel` recipe detects it, installs Composer and builds the Vite
assets on the host, and keeps the `mysql` and `redis` services of the repo's
Laravel Sail compose (a workstation stack) as sidecars, with a generated
database password.

## What the recipe adds

- `overrides/docker-compose.override.yml`:
  - `mysql` mounts only its data volume. Sail also mounts
    `vendor/laravel/sail/.../create-testing-database.sh`, and Sail is a dev
    dependency that `--no-dev` never installs.
  - `elasticsearch` is a one-shot no-op with its ulimits reset: the memlock
    ulimit fails in an account, and Bagisto's catalog search
    defaults to the database.
  - `storage-init` seeds the `bagisto-storage` volume with the repo's
    `storage/app` (import samples) without overwriting and hands it to the
    app's uid; the volume is mounted at `/app/storage/app`, so product,
    category and theme images survive a redeploy.
- `panelalpha.yaml`: the laravel commands, with `storage:link` on every start
  (`public/storage` lives in the re-cloned `~/project`), and `APP_DEBUG=false`
  (`.env.example` is a development template).

## First visit

Bagisto's own web installer (`/install`), left as upstream ships it. Database
step: connection MySQL, host `mysql`, port 3306, database `app`, user `app`,
password = `DB_PASSWORD` of the app service (in
`~/project/docker-compose.panelalpha.yml`). The installer is done once an
admin exists; after that `/install` redirects to the storefront.
