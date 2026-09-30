# Loomio

Collaborative decision-making. Upstream's `deploy/docker-compose.yml` on the
`loomio/loomio:3.9.0` release image: `app`, `worker`, `postgres:17`, `redis:8.4`.

- The repo Dockerfile needs `NODE_VERSION`/`NPM_VERSION` build args, so the plain
  deploy fails; the release image is used instead.
- Left out: nginx-proxy/acme (engine does TLS), haraka (reply-by-email, inbound :25),
  hocuspocus (its own `hocuspocus.<host>` subdomain; the editor falls back to plain
  editing when it cannot connect).
- Secrets (`DATABASE_URL`, Postgres password, `SECRET_COOKIE_TOKEN`,
  `RAILS_INBOUND_EMAIL_PASSWORD`) are generated once into `~/.panelalpha/loomio/`.
- Sign-in codes and invitations are e-mailed: set `SMTP_SERVER`, `SMTP_PORT`,
  `SMTP_USERNAME`, `SMTP_PASSWORD` (plus `SMTP_USE_SSL=1`/`SMTP_AUTH=plain` as your
  provider needs) as project env vars. Without them Loomio starts but sends no mail.
- Other settings from upstream's `deploy/env_template` (`FEATURES_*`, `OAUTH_*`,
  `THEME_*`) can be set the same way.
