# Versus Incident (github.com/versuscontrol/versus-incident)

Incident alert gateway with a web console: one Go binary with the React
console embedded (`//go:embed`), built from the repository's Dockerfile,
incidents in `/app/data/incidents.json`.

## Deploying

Deploy as is (the build compiles the console and the Go binary), open the site
and sign in with the gateway secret the engine generates (`GATEWAY_SECRET`;
there is no username): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). The same secret is the `X-Gateway-Secret` header
for `/api/admin/*` and `/api/agent/*`. Alert channels (Slack, Telegram, email
and the rest), on-call (Redis) and the AI agent are off until configured.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port
  3000 (`config/config.yaml`'s port), as the dockerfile strategy did, with the
  engine's secret and the project's `.env` as `env_file`.
- The console's only sign-in is `GATEWAY_SECRET`; unset, it fails closed and
  nobody gets in. Versus Incident reads `GATEWAY_SECRET` from the environment
  over `config.yaml`, so the engine's value is the sign-in on every start. The
  console has no form to change it.
- `PUBLIC_HOST` is the site's https address (`${PA_PUBLIC_URL}`). Versus
  Incident compares a signed-in browser's `Origin` with it before an admin
  change; unset, it expects `http://` plus the Host, which a browser on the
  https site never sends, so every save in the console was refused. It also
  marks the session cookie `Secure`. The app copies `config/config.yaml`'s
  values over its environment, and upstream's file says `public_host: ''`, so
  `hooks/prepare.sh` makes it `${PUBLIC_HOST}` (the file's values are
  expanded) before the image is built.
- Incidents are kept in the container (`/app/data`), as before; a redeploy
  starts them empty.
