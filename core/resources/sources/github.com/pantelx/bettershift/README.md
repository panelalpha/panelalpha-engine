# BetterShift (github.com/panteLx/BetterShift)

A shift calendar: Next.js with Better Auth and SQLite in the published
`ghcr.io/pantelx/bettershift` image, data under `./data`.

## Deploying

Deploy as is, open the site and register: sign-up is open
(`ALLOW_USER_REGISTRATION=true`) and the first account becomes superadmin.
There is no seeded login, so the engine has no credentials to report.

## What the recipe does

- The repository's `docker-compose.yml` runs as before (port 3000,
  `./data` and `./uploads` bind-mounted, `env_file: .env`, which the engine
  writes from `.env.example`).
- `.env.example` sets `BETTER_AUTH_URL=http://localhost:3000`, which Better
  Auth also uses as its only trusted origin: every sign-up and sign-in from
  the site's address got `403 Invalid origin`, so nobody could get in. Its
  `BETTER_AUTH_SECRET` is blank but for an inline comment, which Compose
  reads as the value, so every install signed sessions with the same text.
- `overrides/docker-compose.override.yml` wraps the image's entrypoint
  (`dumb-init -- /app/docker-entrypoint.sh`, then the image's command): while
  `BETTER_AUTH_URL` is empty or `http://localhost:3000` it becomes the site's
  address (`PA_PUBLIC_URL`, which the engine gives the service), and while
  `BETTER_AUTH_SECRET` is empty or a comment it becomes
  `BETTERSHIFT_AUTH_SECRET`, generated once by `hooks/prepare.sh` into
  `~/.panelalpha/bettershift/secret.env`. Values set in the project's
  environment variables are kept.
