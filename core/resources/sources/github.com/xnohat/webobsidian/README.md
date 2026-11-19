# WebObsidian (github.com/xnohat/webobsidian)

Obsidian-style markdown vault in the browser: a Node server with the React
web app, settings and search index under `/data`, the vault on `/vault` (the
bundled `sample-vault` here).

## Deploying

Deploy as is, open the site and unlock it with the password the engine
generates (`WEBOBSIDIAN_LOGIN_PASSWORD`; there is no username): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's `docker-compose.yml` runs as before (its Dockerfile,
  `webobsidian-data` volume on `/data`, port 8787).
- Until a password is chosen in the app, WebObsidian's login is its built-in
  default `123456`. `overrides/docker-compose.override.yml` adds the engine's
  password as `env_file` and its `command` runs
  `files/panelalpha-webobsidian-login.mjs` first: while `/data/settings.json`
  has no `userPasswordHash` it stores the engine's password with WebObsidian's
  own `setUserPassword` (what its change-password form writes), so `123456`
  no longer signs in and the app does not ask for a change. Then it runs the
  image's `node server/dist/index.js`.
- A password changed in WebObsidian sets `userPasswordHash`, so the next start
  leaves it alone; the engine keeps reporting the password it generated.
- The password is not passed as `WEBOBSIDIAN_PASSWORD`: WebObsidian accepts
  that one as a recovery password on every login, also after a change.
