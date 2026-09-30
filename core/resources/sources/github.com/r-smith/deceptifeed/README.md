# Deceptifeed (github.com/r-smith/deceptifeed)

Honeypot servers (SSH 2222, HTTP 8080, HTTPS 8443) with a threat-feed web
dashboard on port 9000, which is what the site's domain serves.

## Deploying

Nothing is required. The recipe runs the published `deceptifeed/server:0.69.0`
image with upstream's built-in defaults (no config file). The threat-feed
database, the honeypot log and the generated SSH/TLS keys live in `/data` on
the `deceptifeed-data` volume and survive redeploys. The app writes the feed
to disk every 20 seconds and not on shutdown, so a hit recorded in the last
20 seconds before a redeploy is lost (upstream behaviour).

## Limits

- Only the dashboard is reachable. The account publishes one HTTP port behind
  the site's domain, so the SSH/HTTP/HTTPS honeypots start but receive no
  traffic from the internet, and the feed stays empty unless something inside
  the account talks to them.
- The dashboard restricts itself to private client addresses. Behind the
  engine's proxy every visitor arrives from a private address, so the
  dashboard is open to anyone who has the URL (upstream documents that this
  restriction is bypassed behind a proxy).

## What the recipe does

- `overrides/docker-compose.yml` replaces the plain Dockerfile deploy with the
  published image, publishes only 9000 and keeps `/data` on a named volume.
