# SignServer CE (github.com/keyfactor/signserver-ce)

Keyfactor's PKI signing server (code, documents, timestamps) on WildFly.

## What the recipe does

- `overrides/docker-compose.yml` runs `keyfactor/signserver-ce:7.7.1` (the
  current release) instead of building the repository, which is an Ant/Maven
  EJB source tree with no container build.
- `PROXY_HTTP_BIND=0.0.0.0` is upstream's documented behind-a-proxy mode: a
  plain HTTP back-end on 8081 that honours `X-Forwarded-Proto`. Only 8081 is
  published; 8082 (which trusts an `SSL_CLIENT_CERT` header) is not.
- `REDIRECT_PATH=/signserver/`: the image's default for the `/` redirect
  resolves to `-/signserver`, a relative redirect loop.
- The bundled H2 database lives on the named volume `signserver-data`
  (`/mnt/persistent`), so workers, keys and the audit log survive redeploys.
- `ready` makes `compose up -d` wait for the Public Web (WildFly takes ~70s).
  The healthcheck does not use `/signserver/healthcheck/signserverhealth`: it
  answers 500 while any configured worker is offline.

## Using it

- Public Web: `/signserver/`, Client Web: `/signserver/clientweb/`.
- The Admin Web (`/signserver/adminweb/`) logs in with a TLS client
  certificate, which the engine's front proxy does not pass through; configure
  OAuth/OIDC login via the image's environment variables, or manage workers with
  the CLI: `docker compose -p project exec app /opt/keyfactor/signserver/bin/signserver getstatus brief all`.
- Do not set `ADMINWEB_ACCESS=false` expecting it to protect anything: it is an
  IP allow-list and the proxy listener trusts `X-Forwarded-For`.
