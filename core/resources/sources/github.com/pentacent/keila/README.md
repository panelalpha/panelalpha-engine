# Keila (github.com/pentacent/keila)

Newsletter and email-marketing tool (Elixir/Phoenix, PostgreSQL).

## Deploying

Keila will not start without an SMTP server for its system emails. Set these
project environment variables before deploying:

| Variable | Required | Notes |
|---|---|---|
| `MAILER_SMTP_HOST` | yes | SMTP relay |
| `MAILER_SMTP_FROM_EMAIL` | yes | sender address; also the SMTP user unless `MAILER_SMTP_USER` is set |
| `MAILER_SMTP_PASSWORD` | yes, unless `MAILER_SMTP_AUTH_METHOD=none` | |
| `MAILER_SMTP_PORT`, `MAILER_SMTP_USER`, `MAILER_SMTP_TLS_MODE`, `MAILER_SMTP_AUTH_METHOD` | no | see Keila's docs |
| `KEILA_USER`, `KEILA_PASSWORD` | no | root user created on the first start |

Without the required ones the deploy fails with:

```
Error: Keila needs an SMTP mailer to start. Set in the project's environment variables: MAILER_SMTP_HOST (...) ...
```

## What the recipe does

- `overrides/docker-compose.yml` runs `pentacent/keila:0.30.3` on port 4000
  beside `postgres:18-alpine`; `init` checks the mailer variables and hands
  the uploads volume to the image's user; `ready` waits on the app's
  healthcheck.
- `hooks/prepare.sh` generates the database password and `SECRET_KEY_BASE`
  once into `~/.panelalpha/keila/`.
- Data: PostgreSQL on `db_data`, uploads on `uploads`; both survive redeploys.

## First login

If `KEILA_USER`/`KEILA_PASSWORD` were not set, Keila creates
`root@localhost` with a random password and prints it once in the app log
(`KEILA_PASSWORD not set. Setting random root user password: ...`).
