# Pomerium (github.com/pomerium/pomerium)

An identity-aware access proxy: visitors sign in before they reach the
application behind it.

## Deploying

A fresh deploy works with no settings: opening the site sends the visitor to
Pomerium's hosted sign-in (`authenticate.pomerium.app`), and after signing in
they land on a bundled placeholder page. To protect a real application, set the
project environment variable `UPSTREAM` to its address (for example
`http://10.0.0.5:3000` or another site's URL) and redeploy.

The route lets **any** signed-in user through (`allow_any_authenticated_user`).
Before protecting anything real, narrow it to your users, for example with a
policy on an email domain; see Pomerium's policy documentation. The hosted
authenticate service needs the site's address to be reachable over HTTPS from
the visitor's browser.

## What the recipe does

- `overrides/docker-compose.yml` runs `pomerium/pomerium:v0.33.4` on port 8080
  with `INSECURE_SERVER=true` (TLS ends at the hosting proxy).
- A one-shot `config` service writes `/pomerium/config.yaml` with one route,
  from the site's public address to `UPSTREAM` (default: the placeholder), on
  every deploy. It runs in compose because the site's address is only known
  there.
- `hooks/prepare.sh` generates `SHARED_SECRET` and `COOKIE_SECRET` once into
  `~/.panelalpha/pomerium/secrets.env` (0600), so a redeploy does not sign
  everyone out.
- `backend` is `nginx:alpine` serving `files/panelalpha/pomerium/index.html`.

No identity provider is configured; to use your own (Google, GitHub, OIDC),
the route file has to carry `idp_provider` and its client settings, which this
recipe does not set.
