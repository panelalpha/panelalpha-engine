# Prometheus (github.com/prometheus/prometheus)

Monitoring system and time series database; web UI on :9090.

## What the recipe does

- `overrides/docker-compose.yml` runs `prom/prometheus:v3.15.0` with the
  image's default configuration (Prometheus scrapes itself).
- `/etc/prometheus` is the named volume `config` (filled from the image on
  first start) and `/prometheus` the named volume `data`; both survive redeploys.
- A no-op `ready` service holds `compose up` until `/-/ready` answers.

To scrape other targets, edit `prometheus.yml` in the `config` volume and
restart the project. The web UI has no authentication of its own.
