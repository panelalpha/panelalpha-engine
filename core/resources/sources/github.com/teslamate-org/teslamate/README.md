# TeslaMate (github.com/teslamate-org/teslamate)

Self-hosted data logger for Tesla vehicles. Elixir/Phoenix release serving a
LiveView interface on :4000, PostgreSQL for data.

Detection: `dockerfile` — the repository ships a root `Dockerfile` and no
compose file, so the image builds correctly and then starts alone. What breaks
is everything outside the Dockerfile:

- `elixir/config/runtime.exs` reads `DATABASE_USER`, `DATABASE_PASS`,
  `DATABASE_HOST` and `DATABASE_NAME` through `Util.fetch_env!`, which is
  `System.fetch_env!` in `:prod` — no defaults, a raise when missing. `MQTT_HOST`
  is a fifth required variable unless `DISABLE_MQTT=true`.
- `entrypoint.sh` blocks in `while ! nc -z "${DATABASE_HOST:-127.0.0.1}" 5432`
  before the release ever boots, then runs
  `bin/teslamate eval "TeslaMate.Release.migrate"`.

So an unassisted deploy reports success, the container stays up, and :4000
answers `Recv failure: Connection reset by peer` — the verdict this recipe
turns into a served page.

What the recipe adds:

- `hooks/prepare.sh` writes `.env` (the generated app service already reads it
  through `env_file:`) with one generated password shared by `DATABASE_PASS` and
  `POSTGRES_PASSWORD`, plus `ENCRYPTION_KEY`, `SECRET_KEY_BASE` and
  `SIGNING_SALT`. Guarded by `[ ! -f .env ]`: a redeploy must not roll the
  password out from under the existing postgres volume.
- `overrides/docker-compose.override.yml` adds the `database` service
  (postgres:17-alpine, named volume, healthcheck, account-sized settings) and
  makes `app` wait on it. `mem_limit: 768m` on `app` replaces the hardener's
  384m app-role default — the BEAM plus the migration run sits near that ceiling.
- `panelalpha.yaml` `env:` carries the non-secret settings: `DISABLE_MQTT=true`,
  `HTTP_BINDING_ADDRESS=0.0.0.0`, `DATABASE_POOL_SIZE=5`, `DATABASE_PORT=5432`
  and `RELEASE_DISTRIBUTION=none`.

`RELEASE_DISTRIBUTION=none` is the line that decides whether the deploy serves.
An Elixir release starts epmd, which binds `0.0.0.0:4369` as soon as the VM
comes up — while `entrypoint.sh` is still waiting on Postgres and running the
migrations, so `:4000` does not exist yet. `AppPortAlignment` polls for 24s
(12 × 2s), sees a reachable web-candidate port that is not 4000, logs
`Application is listening on port 4369, not 4000; forwarding there instead`
and republishes the account's port onto epmd — which speaks the Erlang
port-mapper protocol and answers HTTP with `Empty reply from server`, forever.
That was the second failed verdict on the way to this recipe. TeslaMate is a
single node with no libcluster and no `:global` usage, so distribution buys it
nothing, and without epmd there is exactly one listening socket to find.

`ENCRYPTION_KEY` is worth its line in the hook: `TeslaMate.Vault` encrypts the
stored Tesla API tokens with SHA-256 of it and, unset, generates one per boot
and only logs a warning — every restart would then invalidate the saved tokens.

Deliberately not reproduced from the upstream compose:

- **Mosquitto.** MQTT is an outbound publishing integration for Home Assistant;
  the web interface never reads it. `DISABLE_MQTT=true` is what makes the
  otherwise-required `MQTT_HOST` optional.
- **Grafana.** A second image on a second port (:3000), and the account
  publishes one. The dashboards work by pointing a Grafana at this database;
  TeslaMate's Settings → URLs page is where the two are linked.

`VIRTUAL_HOST` stays unset, as it is in the upstream compose. It only feeds
`TeslaMateWeb.Endpoint`'s `url:` host for absolute link generation; the engine's
public-URL aliases deliberately exclude that name because elsewhere it addresses
a proxy sidecar. Set it through the account's `env_vars` if absolute links
matter.

The first page is a Tesla API token sign-in form — the app serves and is fully
usable, but logs nothing until a vehicle account is signed in.
