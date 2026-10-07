# Invidious (github.com/iv-org/invidious)

Alternative YouTube front end: the Crystal server on :3000, PostgreSQL, and
Invidious companion, which talks to YouTube for video streams.

## Deploying

No variables are required. Other options take the upstream top-level
`INVIDIOUS_<KEY>` env vars (e.g. `INVIDIOUS_REGISTRATION_ENABLED=false`).

Video playback needs YouTube to accept the server's IP. From a datacenter
address the companion's PO-token check can get only non-200 answers from
YouTube; the companion then keeps restarting and `/watch` answers 500
"Companion is starting", while home, search and channel pages work. That is
YouTube's behaviour towards the host, not the recipe; see
docs.invidious.io/youtube-errors-explained.

## What the recipe does

- The repository's compose is a development build from source (its
  `shards install` fails cloning will/crystal-pg with HTTP 401) with the
  placeholder `hmac_key: "CHANGE_ME!!"`. `overrides/docker-compose.yml` runs
  `quay.io/invidious/invidious:2.20260804.1` (current release), the companion
  (`2026.09.19-bb3b37f`; upstream publishes only rolling builds) behind
  Invidious' built-in `/companion` proxy, and `postgres:14`, as the
  installation docs' compose.
- `INVIDIOUS_DOMAIN` is the site's address, https on 443.
- `hooks/prepare.sh` generates the HMAC key, the 16-character companion key
  (shared by both services) and the database password once into
  `~/.panelalpha/invidious/secrets.env`.
- Data: `postgresdata` (users, subscriptions, playlists) and `companioncache`
  named volumes. Invidious creates its tables on start (`check_tables`).
