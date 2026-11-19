# Open Payment Host (github.com/abishekmuthian/open-payment-host)

A storefront for subscriptions, newsletters and digital files (Stripe,
Square, PayPal, Razorpay): one Go binary with SQLite in `db/`, its config in
`secrets/fragmenta.json`, built from the repository's `Dockerfile`.

## Deploying

Deploy as is, open `/users/login` and sign in with the login the engine
generates (`OPH_ADMIN_EMAIL`, default `admin@example.com`, and
`OPH_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). As upstream does with its default password, the
first sign-in asks for a new password, then signs out; sign in again with the
new one. Payments need the providers' keys in `secrets/fragmenta.json`.

## Why a recipe

On its first start the binary writes `secrets/fragmenta.json` with
`admin_email` `admin@openpaymenthost.com` and `admin_default_password`
`OpenPaymentHost`, both published in its README, and creates the admin from
them, so every deploy signed in with a published login.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file` and runs
  `files/panelalpha/oph-start.sh` before the image's own command
  (`./exec/open-payment-host`).
- `oph-start.sh`, only while there is no `secrets/fragmenta.json`: runs the
  binary in a scratch directory, where it writes its config (random keys
  included) and stops before serving because the directory has no `log/`; puts
  `OPH_ADMIN_EMAIL` / `OPH_ADMIN_PASSWORD` into `admin_email` /
  `admin_default_password` of both sections; moves the file into place. The
  binary then creates the admin from it, as upstream does. A config that
  exists is never touched, so a password changed in the app stays changed.
- `hooks/prepare.sh` makes the mounted script readable by the image's
  `default` user.

`secrets/` and `db/` are not volumes (the Dockerfile declares none), so a
redeploy that recreates the container starts a new store, with the generated
login again.

The login form also needs Cloudflare Turnstile; the development config the
binary writes (`FRAG_ENV` is not `production`) has Cloudflare's always-passing
test keys, which the app checks with Cloudflare over the network.

Checked locally (2026-11-19, 70341e30, the image built from the repository's
Dockerfile, the override over a stand-in for the generated service): the
binary wrote the config in the scratch directory and stopped, the admin was
created with the generated login and the config kept its own random keys; the
generated login signs in (`POST /users/login` 302 to
`/users/1/password/change`, that page 200); `admin@openpaymenthost.com` /
`OpenPaymentHost` (which signs in without the recipe) and a wrong password
are refused (`?error=not_a_valid_login`, 401); a password changed there
survived a restart, and the generated one was refused after it.
