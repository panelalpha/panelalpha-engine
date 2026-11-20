# wallabag (github.com/wallabag/wallabag)

Read-it-later service (Symfony, PHP): saves web pages for reading later.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates:
`WALLABAG_USERNAME` (always `wallabag`) and `WALLABAG_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs the official image of the current
  release, `wallabag/wallabag:2.6.14`, on SQLite: the database
  (`data/db/wallabag.sqlite`) and saved images on the named volumes
  `wallabag-data` and `wallabag-images`, kept across redeploys.
  `SYMFONY__ENV__DOMAIN_NAME` is the account's public URL. The repository's
  own `compose.yaml` is a developer stack and the source build does not serve.
- A new database gets wallabag's first user `wallabag` with the password
  `wallabag` (`wallabag:install -n`, run by the image's entrypoint), and there
  is no sign-up: registration is off in the image.
- `files/panelalpha-wallabag-start.sh` is the entrypoint. While the database
  is new or the user `wallabag` still has the password `wallabag` (checked
  read-only against the hash, sha512 as `security.yml` encodes it), it runs
  the image's provisioner once (`/entrypoint.sh migrate`: parameters.yml, the
  install, composer, the migrations) and sets `WALLABAG_PASSWORD` with
  wallabag's own `fos:user:change-password`. Then it execs
  `/entrypoint.sh wallabag` as the image does. A password changed in wallabag
  is never overwritten; on a database whose password is no longer the default
  the script goes straight to the image's entrypoint.
- The username stays `wallabag`: wallabag has no command that renames a user.
