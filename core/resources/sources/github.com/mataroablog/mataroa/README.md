# Mataroa (github.com/mataroablog/mataroa)

Minimalist blogging platform. Nothing is required to deploy: open the site
and sign up.

## Domains

The project's host is `DOMAIN` (landing page, sign-up, dashboard, editor).
A user's blog is served on `<username>.<DOMAIN>`, and the app recognises that
only when `DOMAIN` has exactly two labels (`example.com`, with a wildcard DNS
record and the subdomains routed to the project). On the engine's default
host blogs are reachable only through a user's custom domain (set under
Settings) routed to the project. Email uses Postmark SMTP when
`EMAIL_HOST_USER` / `EMAIL_HOST_PASSWORD` are set (not wired by default).

## What the recipe does

- `hooks/prepare.sh` generates `SECRET_KEY` and the PostgreSQL password once
  into `~/.panelalpha/mataroa/` (`app.env`, `db.env`).
- `overrides/docker-compose.yml`: `web` builds the repo's Dockerfile and runs
  `migrate`, `collectstatic` and gunicorn on :5000 (as `deploy/`);
  `postgres:17` on the `pgdata` volume; `caddy` serves `/static` from the
  `static` volume and proxies the rest on 8080, sending
  `X-Forwarded-Proto: https` so Django's CSRF check accepts the forms.
- `files/.dockerignore` keeps `.git` and env files out of the built image.
