# authentik

<https://github.com/goauthentik/authentik> — identity provider (OAuth2/OIDC,
SAML, LDAP) with a web admin UI; Django server + worker on PostgreSQL.

The plain deploy detects the source tree as `django` and crash-loops with
`ModuleNotFoundError: No module named 'django'`.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's release compose
  (`https://goauthentik.io/docker-compose.yml`) with
  `ghcr.io/goauthentik/server:2026.8.3` (current release), changed only where
  the account needs it:
  - named volumes instead of the `./data`, `./certs`, `./custom-templates`
    bind mounts (`~/project` is wiped on every deploy);
  - no `/var/run/docker.sock` on the worker (only used for outposts managed
    through Docker), so no `user: root` either;
  - only plain HTTP `9000` published; the engine terminates TLS.
  - A no-op `ready` service gates `compose up -d` on `/-/health/ready/`
    (the first boot runs all migrations).
- `hooks/prepare.sh`: generates `AUTHENTIK_SECRET_KEY` and the PostgreSQL
  password once into `~/.panelalpha/authentik/` (`app.env`, `db.env`).

## First run

Open the site: `/` redirects to `/if/flow/initial-setup/`, where you set the
`akadmin` email and password, as upstream ships it.
