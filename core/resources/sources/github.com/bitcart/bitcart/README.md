# Bitcart (github.com/bitcart/bitcart)

Cryptocurrency payment processor. The repository is the backend; upstream's
deployment is bitcart-docker (a compose generator plus a docker-gen nginx).

## Deploying

Nothing to set. Open `/admin` and register the first user (upstream's first
run). The store is on `/`, the API on `/api`. Only BTC is enabled
(upstream's default `BITCART_CRYPTOS=btc`).

## What the recipe does

- `overrides/docker-compose.yml`: bitcart-docker's generated compose for the
  default pack in one-domain mode, on the `0.10.3.0` images of
  `bitcart/bitcart`, `bitcart-admin`, `bitcart-store` and `bitcart-btc`.
- `files/panelalpha-bitcart/router.conf`: the routes bitcart-docker's
  `nginx.tmpl` writes in one-domain mode, served by a plain nginx (docker-gen
  needs the Docker socket).
- `hooks/prepare.sh`: PostgreSQL password generated once into
  `~/.panelalpha/bitcart/` (0600 in 0700), passed as `POSTGRES_PASSWORD` and
  `DB_PASSWORD`.
- Named volumes: `dbdata`, `bitcart_datadir`, `backup_datadir`, `plugins`,
  `bitcoin_datadir`.
