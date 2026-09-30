# NGINX (github.com/nginx/nginx)

HTTP server and reverse proxy. The site serves nginx's own welcome page until
content or config is replaced.

## Deploying

Nothing is required.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `nginx:1.31.6-alpine` image
  instead of building the C source, with port 80 published as 8080.
- `/usr/share/nginx/html` (site content) and `/etc/nginx/conf.d` (server
  blocks) are the named volumes `nginx-html` and `nginx-conf`. Both are seeded
  from the image on first start and survive redeploys; edit them with
  `docker compose -p project exec app sh` inside the account, then
  `nginx -s reload`.
- Not included: host ports 80/443, the mail and stream proxies (TCP/UDP on
  other ports are not routed to an account).
