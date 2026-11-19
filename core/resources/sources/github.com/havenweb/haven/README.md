# Haven (github.com/havenweb/haven)

Private blog and RSS reader: Rails on PostgreSQL, the repository's own
`docker-compose.yml` (services `haven` and `postgresql`).

## Deploying

Deploy as is, open `/users/sign_in` and sign in with the login the engine
generates (`HAVEN_ADMIN_EMAIL`, default `admin@example.com`, and
`HAVEN_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). That user is Haven's admin (it adds the other
users); change the password on its account page (`/users/edit`).

## Why a recipe

Haven has no sign-up. Its `bin/docker-start` creates the first admin from
`HAVEN_USER_EMAIL` / `HAVEN_USER_PASS`, and the repository's compose file sets
them to `changeme@havenweb.org` / `ChangeMeN0W`: a login anyone can read in the
repository, which nobody deploying it was told. Without a recipe a new Haven
was a sign-in form whose only account was that published one.

## What the recipe does

- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/haven-start.sh` in place of the image's command and mounts
  the engine's `app-credentials.env` read-only at `/pa/app-credentials.env`
  (an `env_file` would lose to the compose file's own `HAVEN_USER_*`).
- `haven-start.sh` sets `HAVEN_USER_EMAIL` / `HAVEN_USER_PASS` to the
  generated login and runs upstream's `bin/docker-start` unchanged. Its
  `create_user.rb` creates the admin only while no user has that email, so a
  password changed in Haven is never reset and the published login is never
  created.

Nothing else changes. As upstream, a user whose email was changed in Haven
away from the generated one gets a second admin with the generated login on
the next start (create_user.rb looks the user up by email).

Checked locally (2026-11-19, 293df123, the repository's compose file with this
override): the generated login signs in (Devise `POST /users/sign_in` 303 to
`/`, `/posts` 200), `changeme@havenweb.org` / `ChangeMeN0W` and a wrong
password are refused (422); after the password was changed on `/users/edit` a
restart kept the new one ("already exists, skipping user creation") and
refused the generated one.
