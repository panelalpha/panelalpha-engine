# Passwordcockpit (github.com/passwordcockpit/passwordcockpit)

A web password manager for teams: PHP on Apache with an Ember frontend, on
MariaDB. The repository's `docker-compose.yml` runs the published
`passwordcockpit/passwordcockpit:1.4.0` image and `mariadb:10`.

## Deploying

Deploy as is, open the site and sign in at `/login` with the login the engine
generates (`PASSWORDCOCKPIT_ADMIN_USERNAME`, default `admin`, and
`PASSWORDCOCKPIT_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Change the
password in the app under the user menu.

## Why a recipe

- **The login.** A new database gets the built-in admin `admin` with the
  password `Admin123!`, published in the README: nobody deploying it was told,
  and anyone with the address could sign in.
- **The API's address.** The frontend calls the API at
  `PASSWORDCOCKPIT_BASEHOST`, which the image's `entrypoint.sh` writes into
  `public/` on every start. The compose file says `http://localhost:8080`,
  which in a visitor's browser is their own machine, so the login form never
  reached the API.
- **The data.** The upload directory and the MariaDB data were binds under
  `./volumes/`, inside the checkout a redeploy re-clones: every stored password
  and the admin's own were gone after one.

## What the recipe does

- `overrides/docker-compose.override.yml` layers over the repository's
  compose file: the engine's `app-credentials.env` as the app's `env_file`,
  `panelalpha/passwordcockpit/start.sh` as its entrypoint, and the named
  volumes `passwordcockpit-upload` and `passwordcockpit-mariadb` in place of
  the `./volumes/` binds.
- `files/panelalpha/passwordcockpit/start.sh` sets `PASSWORDCOCKPIT_BASEHOST`
  to `PA_PUBLIC_URL` and runs the image's own `entrypoint.sh`, which installs
  or migrates the database (on a new one it sets
  `PASSWORDCOCKPIT_ADMIN_PASSWORD`, the image's own variable, on the admin),
  then runs `admin-login.php` and Apache.
- `files/panelalpha/passwordcockpit/admin-login.php` (PDO on the app's own
  database settings) changes user 1 only when:
  - it still has `Admin123!` (a database installed before this recipe): it
    gets the engine's username and password;
  - it has exactly the engine's password but another name (a new database:
    `entrypoint.sh` only sets the password): it gets the engine's username.

  A password changed in Passwordcockpit matches neither, so it is never
  touched.

Not changed: `PASSWORDCOCKPIT_BLOCK_CIPHER_KEY` and
`PASSWORDCOCKPIT_AUTHENTICATION_SECRET_KEY` keep the repository's published
values (`supersecretkey`, `authenticationsecretkey`). The JWT secret alone is
not a way in (every request's token must match the one stored for the user at
login), and a new cipher key would make a vault encrypted with the old one
unreadable.

Checked locally (2026-11-19, c05e2bf, the image 1.4.0, MariaDB 10): `POST
/api/auth` with the engine's login, then `GET /api/v1/users/1` with its token;
`admin` / `Admin123!` refused; a database reset to `Admin123!` got the engine's
login on the next start; a password changed in the app survived a restart and
a redeploy (down and up, volumes kept) and the engine's was refused after it;
the frontend's token endpoint was the site's address.
