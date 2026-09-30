# Multica (github.com/multica-ai/multica)

Issue board where AI coding agents and people work together. Web UI on the
site; agents run on your own machines through the `multica` CLI daemon,
pointed at this site.

## Deploying

Nothing is required. Login is by e-mail code: set `RESEND_API_KEY` (and
`RESEND_FROM_EMAIL`) or `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` /
`SMTP_PASSWORD` / `SMTP_FROM_EMAIL` in the project's environment variables
to have codes mailed. Without them the code is printed to the backend log
(`[DEV] Verification code for ...`), as upstream documents. Sign-up is open
by default; `ALLOW_SIGNUP`, `ALLOWED_EMAILS` and `ALLOWED_EMAIL_DOMAINS`
restrict it.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.selfhost.yml`
  with the v0.6.0 release images, only the frontend (:3000) published; the
  frontend proxies the API to the backend. The plain deploy never started the
  frontend and served the API's 404.
- `hooks/prepare.sh` generates the Postgres password and `JWT_SECRET` once
  into `~/.panelalpha/multica/app.env`.
- Backend `FRONTEND_ORIGIN`, `CORS_ALLOWED_ORIGINS` and `MULTICA_APP_URL`
  are the site address (upstream's reverse-proxy guide).
- Database and uploads on named volumes.
- Real-time updates use a WebSocket on `/ws` of the backend, which this
  single-port layout does not route; the board works over the REST API and
  refreshes on reload.
