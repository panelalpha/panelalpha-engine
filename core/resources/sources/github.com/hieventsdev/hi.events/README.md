# Hi.Events (github.com/HiEventsDev/hi.events)

Event management and ticketing: event pages, ticket sales, check-in and
embeddable widgets. Laravel API and a React SSR frontend in one image.

## Deploying

Nothing is required. The first visit offers sign-up; the first account
becomes the organizer admin (as upstream ships it; registration stays open
until `APP_DISABLE_REGISTRATION=true` is set). Mail goes to the log until the
`MAIL_*` variables are set; Stripe payments need the `STRIPE_*` keys.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/all-in-one` stack
  (app, Postgres 17, Redis 7) on the `daveearley/hi.events-all-in-one`
  v1.11.1-beta release image instead of building `Dockerfile.all-in-one`,
  whose frontend build is OOM-killed inside an account.
- `hooks/prepare.sh` generates `APP_KEY`, `JWT_SECRET` and the database
  password once into `~/.panelalpha/hievents/app.env`.
- Uploads (`storage/app`), the database and Redis live on named volumes.
- A no-op `ready` service holds the deploy until migrations ran and the site
  answers.
