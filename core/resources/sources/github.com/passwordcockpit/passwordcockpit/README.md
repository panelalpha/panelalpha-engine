# Passwordcockpit (github.com/passwordcockpit/passwordcockpit)

A web password manager for teams: PHP on Apache with an Ember frontend, on
MariaDB. The repository's `docker-compose.yml` runs the published
`passwordcockpit/passwordcockpit:1.4.0` image and `mariadb:10`.

## Deploying

Deploy as is, open the site and sign in at `/login` with the login the engine
generates (`PASSWORDCOCKPIT_ADMIN_USERNAME`, default `admin`, and
`PASSWORDCOCKPIT_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Change the
password in the app under the user menu. The same answer has
`PASSWORDCOCKPIT_VAULT_KEY`, the key the stored passwords and files are
encrypted with: keep a copy, without it a backup of the vault cannot be
decrypted.

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
- **The keys.** The compose file sets `PASSWORDCOCKPIT_BLOCK_CIPHER_KEY`
  (`supersecretkey`, which encrypts every stored password and file) and
  `PASSWORDCOCKPIT_AUTHENTICATION_SECRET_KEY` (`authenticationsecretkey`,
  which signs the JWTs) to values published in the repository, the same on
  every install: a leaked dump or volume decrypted with them.

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
  then runs `admin-login.php`, sets the keys and runs Apache:
  - `PASSWORDCOCKPIT_AUTHENTICATION_SECRET_KEY` is the engine's
    `PASSWORDCOCKPIT_JWT_SECRET` (a changed secret only signs everyone out);
  - `PASSWORDCOCKPIT_BLOCK_CIPHER_KEY` is what `vault-key.php` prints: the
    engine's `PASSWORDCOCKPIT_VAULT_KEY` for a vault with no encrypted
    password or file yet, the published `supersecretkey` for one that has
    them (a new key would make it unreadable). The choice is made once and
    recorded in `.panelalpha-vault-key-source` on the upload volume; a vault
    recorded as the engine's whose key is missing stops the start instead of
    encrypting with another key.
- `files/panelalpha/passwordcockpit/admin-login.php` (PDO on the app's own
  database settings) changes user 1 only when:
  - it still has `Admin123!` (a database installed before this recipe): it
    gets the engine's username and password;
  - it has exactly the engine's password but another name (a new database:
    `entrypoint.sh` only sets the password): it gets the engine's username.

  A password changed in Passwordcockpit matches neither, so it is never
  touched.

A vault that keeps `supersecretkey` is as readable as before to anyone with
its database or volume. It gets a key of its own only as a new, empty vault
(a new project), with its entries entered there again.

Checked locally (2026-11-19, c05e2bf, the image 1.4.0, MariaDB 10): `POST
/api/auth` with the engine's login, then `GET /api/v1/users/1` with its token;
`admin` / `Admin123!` refused; a database reset to `Admin123!` got the engine's
login on the next start; a password changed in the app survived a restart and
a redeploy (down and up, volumes kept) and the engine's was refused after it;
the frontend's token endpoint was the site's address.

Not checked on a deploy yet: the generated keys and `vault-key.php`.
