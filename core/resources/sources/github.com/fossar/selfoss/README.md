# selfoss (github.com/fossar/selfoss)

RSS reader and aggregator, PHP + SQLite, served by nginx/php-fpm on :8888.

## What the recipe does

- `overrides/docker-compose.yml` runs `rsprta/selfoss:2.19`, the image the
  selfoss installation docs link to, at the current release. `master` is the
  unreleased 2.20-SNAPSHOT, and its plain php deploy fails (engine#342,
  engine#417).
- `/selfoss/data` is the named volume `selfoss-data`: `config.ini`, the SQLite
  database, favicons and thumbnails survive redeploys.
- The image's own cron service fetches feeds every 15 minutes.
- A no-op `ready` service makes `compose up -d` return only once selfoss
  answers.

## Notes for the customer

- selfoss starts without authentication, as upstream ships it. To add a login,
  set `username`/`password` in `config.ini` on the data volume
  (`/selfoss/data/config.ini`, re-read at container start).
- The image serves `/data/config.ini` over HTTP (upstream's nginx rules deny
  `/config.ini`, but the image moves the file to `data/`). Use a password hash
  from selfoss's `/password` page, never a plain password, and set your own
  `salt`.
