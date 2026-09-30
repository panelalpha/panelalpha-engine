# remark42 (github.com/umputun/remark42)

Self-hosted comment engine: one Go server on :8080 serving the API and the
comment widget's static files, BoltDB data under `/srv/var`.

## Deploying

Nothing is required. `REMARK_URL` is the site's address and `SECRET` is
generated per account. The domain root answers 404 (upstream has no page
there); the demo page is `/web/`, and sites embed the widget from
`https://<domain>/web/embed.js`.

Add login providers and other settings as project environment variables, e.g.
`AUTH_ANON=true`, `AUTH_GITHUB_CID` / `AUTH_GITHUB_CSEC`, `SITE`,
`ADMIN_SHARED_ID`, `ADMIN_PASSWD`. Without any provider remark42 starts and
logs `no auth providers defined`: comments can be read but nobody can log in.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's compose (a source
  build that runs the test suites and runs out of memory) with
  `ghcr.io/umputun/remark42:v1.17.1`, `/srv/var` on the `remark-var` volume.
- A no-op `ready` service holds the deploy until `/ping` answers.
