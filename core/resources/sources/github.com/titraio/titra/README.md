# Titra (github.com/titraio/titra)

Time tracking for freelancers and small teams (Meteor, MongoDB).

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's compose file. That
  file passes `ROOT_URL=${ROOT_URL}`, which is empty on the engine, and Meteor
  exits at boot with `Must pass options.rootUrl or set ROOT_URL`. Here
  `ROOT_URL` is the account's public URL.
- Runs `titraio/titra:v1.1.0` (current release) on port 3000 and `mongo:7.0`
  with `/data/db` on the named volume `titra_db_volume`.
- `ready` makes `compose up -d` wait until Titra answers.

Nothing to configure: the first visitor registers an account on the sign-up
page, as upstream ships it.
