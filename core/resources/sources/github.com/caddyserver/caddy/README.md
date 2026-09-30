# Caddy (github.com/caddyserver/caddy)

Web server and reverse proxy. The site serves Caddy's own "Caddy works!" page
until the Caddyfile or the content is changed.

## Deploying

Nothing is required.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `caddy:2.11.4-alpine` image
  instead of building the Go source, with port 80 published as 8080.
- The image's Caddyfile (`:80`, `file_server` over `/usr/share/caddy`) is
  used as shipped. The engine terminates TLS for the domain, so Caddy's own
  automatic HTTPS is not needed; a site block naming a hostname would make
  Caddy try to obtain a certificate it cannot get (ports 80/443 of the host
  are not the account's).
- Named volumes: `caddy-etc` (`/etc/caddy`, the Caddyfile), `caddy-site`
  (`/usr/share/caddy`), `caddy-data` (`/data`) and `caddy-config` (`/config`).
  Edit inside the account with `docker compose -p project exec app sh`, then
  `caddy reload --config /etc/caddy/Caddyfile`.
- The admin API listens on the container's `localhost:2019`, as upstream
  ships it; it is not published.
