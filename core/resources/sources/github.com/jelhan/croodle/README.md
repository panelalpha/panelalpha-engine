# Croodle (github.com/jelhan/croodle)

End-to-end encrypted date scheduling and polls. Everything is encrypted in
the browser; the server only stores encrypted text files.

## Deploying

Nothing is required. Open the site and create a poll.

## What the recipe does

- `overrides/docker-compose.yml`: a one-shot `fetch` service unpacks the
  release tarball `croodle-v0.7.0.tar.gz` (sha256-checked, once per version)
  into the `web` volume; `app` serves it read-only with
  `php:8.3-apache-bookworm` on port 8080, with the image's `php.ini-production`
  (the default has `display_errors` on, which puts Slim 3's PHP 8 deprecation
  notices into the API's JSON).
- Polls go to the `data` volume at `/data` (`CROODLE__DATA_DIR`), outside the
  web root, so they survive redeploys and are never served.
- Upstream's `api/cron.php` is not scheduled. The API deletes an expired poll
  when it is next opened; the cron only sweeps expired polls nobody opens.

Bumping: change `CROODLE_VERSION` and `CROODLE_SHA256` together.
