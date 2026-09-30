# OpenBot (github.com/copilotkit/openbot)

AI coworker platform: the built app, the API and a Chromium the Bots drive, all
served on :3001 by one container.

## What the recipe does

- `overrides/docker-compose.yml` runs the published release image
  `ghcr.io/copilotkit/openbot:v0.0.15` with `EMBEDDED_POSTGRES=on` (upstream's
  documented one-container deployment) instead of the repo compose, which is a
  9-service development stack. The image runs its own migrations at start;
  `ready` makes `compose up -d` wait for `/health`.
- `hooks/prepare.sh` writes `KEY_ENCRYPTION_KEY` and `BETTER_AUTH_SECRET` once
  to `~/.panelalpha/openbot/secrets.env`.
- `BETTER_AUTH_URL` and `TRUSTED_ORIGINS` are the site address.
- `/var/lib/postgresql`, `/workspace` and `/profiles` are named volumes.

## What the customer sets

OpenBot refuses to start without these; `env-check`
(`files/openbot-env-check.sh`) fails the deploy naming the missing ones:

- `INTELLIGENCE_API_KEY` (CopilotKit Intelligence, free plan available).
- One sign-in provider: `GOOGLE_OAUTH_CLIENT_ID` + `GOOGLE_OAUTH_CLIENT_SECRET`,
  or `MICROSOFT_OAUTH_*`, or `OKTA_OAUTH_*` (+ `OKTA_OAUTH_ISSUER`). Redirect
  URI: `https://<site>/api/auth/callback/<google|microsoft|okta>`.
- `INITIAL_ADMIN_EMAILS`: who becomes administrator on first sign-in.

Optional: `OPENAI_API_KEY` (model key), `SIGNIN_ALLOWED_EMAIL_DOMAINS`,
`MICROSOFT_OAUTH_TENANT_ID`, `COMPOSIO_API_KEY`, `COPILOTKIT_LICENSE_TOKEN`.

Not included, as upstream's single image: the per-Bot supervisor (needs the
Docker socket) and the routines scheduler (an external cron).

Known upstream behaviour: Better Auth logs that it cannot resolve a client IP
and rate-limits sign-in per path in one shared bucket; OpenBot exposes no
setting for its `ipAddress` options.
