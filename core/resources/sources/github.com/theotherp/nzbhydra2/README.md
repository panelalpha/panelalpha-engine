# NZBHydra2 (github.com/theotherp/nzbhydra2)

Meta search for Usenet indexers and torrent trackers: one search fans out to
every configured indexer, and the *arr applications get a single
Newznab/Torznab API. Spring Boot compiled to a native binary, embedded H2
database, web UI on 5076. All state is under the data folder (`/config`).

## Strategy

The repository is the Maven/React source tree with no deployment compose file
(its `docker/` folder holds CI and system-test setups). `overrides/docker-compose.yml`
runs the linuxserver.io image the README points Docker users to, pinned to the
current release (`linuxserver/nzbhydra2:9.0.6`, the Docker Hub name of
`lscr.io/linuxserver/nzbhydra2`). The image carries the native `core` binary;
the heap is capped by `main.xmx` in `nzbhydra.yml` (256 MB, the default), the
container at 768 MB. NZBHydra2 disables its own updater when it runs in
Docker, so a redeploy decides the version.

`/config` is a named volume: `nzbhydra.yml`, `database/` (H2), `rememberMe.key`,
backups and logs.

## Security: upstream has no login

`auth.authType` starts as `NONE`: the whole UI, indexer credentials and the
API key are then open to anyone with the address, and the first visitor
decides the configuration.

- The admin login is declared in `panelalpha.yaml` (`credentials:`): the
  engine generates `NZBHYDRA_ADMIN_USER=admin` and a random password once and
  writes `~/.panelalpha/app-credentials.env` before the prepare hook on every
  deploy (`adopt_from` keeps the password of an account seeded from
  `~/.panelalpha/nzbhydra/nzbhydra.env`).
- `hooks/prepare.sh` writes that password's bcrypt hash and an API key once
  into `~/.panelalpha/nzbhydra/nzbhydra.env` (0600 in a 0700 dir).
  The hash is `{bcrypt}$2a$...`, which Spring's delegating password encoder
  (the one NZBHydra2 authenticates with) accepts and which is what the app
  itself writes when a password is set in the UI.
- The one-shot `init` service (same image, `files/panelalpha/nzbhydra-seed.sh`)
  writes `nzbhydra.yml` before the first boot, **only if it is absent**, from
  the image's own `/defaults/nzbhydra.yml` with: `authType: FORM`, every
  `restrict*` flag on (search, stats, admin, details, indexer selection), user
  `admin` with all rights, `main.apiKey`, `welcomeShown: true`. Each edit must
  match exactly once or the seed fails loudly instead of writing a half-edited
  file. A password or key changed later in the UI is never reset by a redeploy.
- `ready` gates `compose up -d` on `/actuator/health/ping`.

Log in with the username and password `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`) returns; the API key for the *arr apps is in
Config > Main.

## Verified (mariusz.panelalpha.tools, engine 705f250a, memory_limit 2500)

- Deploy 41s, rebuild 38s; app at ~358 MB.
- Anonymous `/` ends on `/login` (`<title>NZBHydra 2</title>`, 4.7 KB);
  `/internalapi/config` 401, `POST /internalapi/stats` 403, `/actuator/env` 302.
  `/internalapi/userinfos` shows `maySeeSearch/maySeeAdmin: false` and no API key.
- `/api?apikey=wrong&t=caps` returns `<error code="100" description="Wrong api key"/>`;
  the seeded key returns `<caps>` and an RSS search response.
- Wrong password: 302 to `/login?error`. Seeded password: 302 to `/`, userinfos
  `maySeeAdmin: true`, `/internalapi/config` 200 with the seeded API key.
- Round trip through `PUT /internalapi/config`: `searching.timeout` 33,
  `searching.userAgent`, and a disabled Newznab indexer read back; the stored
  password stayed the seeded bcrypt hash.
- After `rebuild`: init printed `nzbhydra.yml exists; left alone`,
  `nzbhydra.env` checksum unchanged, login works, config values, the indexer
  and the API key kept; the wrong key is still refused.
- `/.env`, `/.git/config`, `/nzbhydra.yml`, `/database/nzbhydra.mv.db` all 404.
