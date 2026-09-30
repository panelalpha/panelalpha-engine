# Fider (github.com/getfider/fider)

Feedback and feature-voting board: one Go server on :3000 with PostgreSQL.

## Deploying

Fider will not start without a mail transport (users sign in with e-mailed
links). Set these project environment variables before deploying:

- `EMAIL_NOREPLY` - the sender address, e.g. `noreply@example.com`
- `EMAIL_SMTP_HOST`, `EMAIL_SMTP_PORT` (and `EMAIL_SMTP_USERNAME`,
  `EMAIL_SMTP_PASSWORD` if the server needs a login)
- or instead Mailgun (`EMAIL_MAILGUN_API`, `EMAIL_MAILGUN_DOMAIN`) or AWS SES
  (`EMAIL_AWSSES_REGION`, `EMAIL_AWSSES_ACCESS_KEY_ID`,
  `EMAIL_AWSSES_SECRET_ACCESS_KEY`)

Without them the deploy fails with:

```
fider: set in the project's environment variables: EMAIL_NOREPLY EMAIL_SMTP_HOST EMAIL_SMTP_PORT (...)
```

Any other Fider setting (`SIGNUP_DISABLED`, `OAUTH_*`, ...) can be set the
same way. The first visit opens Fider's sign-up page to create the site.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's developer compose
  with `getfider/fider:v0.38.0` and `postgres:17` (data on the `pgdata`
  volume; Fider's default SQL blob storage keeps uploads there too).
- `BASE_URL` is the site's address; `JWT_SECRET` and the database password are
  generated per account by the engine and stay stable across redeploys.
- `email-check` (one-shot, runs `files/fider-email-check.sh`) mirrors Fider's
  own startup check; the app starts only once it passes.
