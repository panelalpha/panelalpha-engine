# Gotenberg (github.com/gotenberg/gotenberg)

Stateless HTTP API converting HTML, Markdown and office documents to PDF
(Chromium, LibreOffice, PDF tools). No UI: `/` says so and links the docs.

## Deploying

Nothing is required. Call the API on the site's domain, e.g.
`curl -F files=@index.html https://<domain>/forms/chromium/convert/html -o out.pdf`.
Every Gotenberg flag is also an env var (`--api-enable-basic-auth` ->
`API_ENABLE_BASIC_AUTH`), so settings such as basic auth
(`GOTENBERG_API_BASIC_AUTH_USERNAME` / `_PASSWORD`) are project env vars.

## What the recipe does

- `overrides/docker-compose.yml` replaces upstream's development compose
  (unset Makefile variables, an unpublished `snapshot` tag, an OpenTelemetry
  stack) with the release image `gotenberg/gotenberg:8.37.0` on port 3000.
- 1.5 GB memory cap for Chromium and LibreOffice; a `ready` gate on `/health`.
