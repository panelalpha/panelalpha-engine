# useSend (github.com/usesend/usesend)

Open-source e-mail sending platform (transactional API, campaigns, contacts)
on top of your own AWS SES account.

## Deploying

Required, in the project's environment variables:

- `GITHUB_ID` and `GITHUB_SECRET` from a GitHub OAuth app whose callback
  URL is `https://<site>/api/auth/callback/github`, or `GOOGLE_CLIENT_ID`
  and `GOOGLE_CLIENT_SECRET` (callback `/api/auth/callback/google`).
  useSend has no password login, and e-mail sign-in only works once a
  sending domain exists. The deploy fails naming these when neither pair
  is set.

To send mail: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and
`AWS_DEFAULT_REGION` (default `us-east-1`) of an IAM user with SES and SNS
access, then add and verify a domain in the dashboard.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/prod/compose.yml` with
  the release image `usesend/usesend:v1.9.8`, Postgres 16 and Redis on
  named volumes (MinIO left out: nothing in the app is pointed at it).
- `hooks/prepare.sh` generates the Postgres password and `NEXTAUTH_SECRET`
  once into `~/.panelalpha/usesend/app.env`.
- `files/.env` (comments only) keeps the repository's development
  `.env.example` (`NEXT_PUBLIC_IS_CLOUD=true`, SES on localhost) from
  becoming the app's defaults.
- A one-shot `check` service fails the deploy when no sign-in provider is
  set; a no-op `ready` service holds the deploy until the app answers.
