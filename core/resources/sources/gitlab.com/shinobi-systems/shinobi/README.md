# Shinobi (gitlab.com/Shinobi-Systems/Shinobi)

Network video recorder (Shinobi CE): Node.js + ffmpeg + MariaDB in one image,
the dashboard on `/` and the Superuser console on `/super`, port 8080.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's own Dockerfile
  unchanged (it bundles Node 22, ffmpeg and MariaDB).
- The engine generates the Superuser login (`credentials:` in
  `panelalpha.yaml`: `admin@shinobi.video` and a random password).
- `hooks/prepare.sh` writes once, into `~/.panelalpha/shinobi/` (0600 files
  in a 0700 dir), and never rewrites:
  - `super.json` - that password as sha256, bind-mounted over
    `/home/Shinobi/super.json` (upstream would copy `super.sample.json`,
    password `admin`);
  - `db.env` - `DB_PASSWORD` for the bundled MariaDB user `majesticflame`
    (upstream creates it with an empty password).
- The service command refuses to start while `super.json` is missing or holds
  the default hash, then sets the generated database password and starts pm2.
- `ready` gates `compose up` on the app's health check.

## Using it

1. Open `https://<domain>/super` and log in with the login
   `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns.
2. Accounts > add an Admin account. That account logs in on `/` and owns the
   cameras (Monitors).
3. Change the Superuser password in /super if you like; it is saved back into
   `~/.panelalpha/shinobi/super.json` and survives redeploys.

## Persistence

- Named volumes: `shinobi-db` (`/var/lib/mysql`: users, monitors, video index)
  and `shinobi-videos` (`/home/Shinobi/videos`: recordings).
- `conf.json` is regenerated from `conf.sample.json` on every container start;
  changes made under /super > Configuration are lost on redeploy.

## Limits

- Cameras must be reachable from this account over outbound connections
  (RTSP/HTTP pull). Nothing inbound except the web port.
- Recordings count against the account's disk.
- Adding a monitor probes the camera first. An unreachable camera makes that
  request take ~12s; on `*.panelalpha.online` test names the shared edge gives
  up at ~10s and shows an error page, although the monitor is saved. On the
  account's own domain the request completes.
- Every redeploy leaves the previous image's anonymous `/home/Shinobi` volume
  (~330 MB, the upstream Dockerfile's `VOLUME`) behind until the engine prunes
  it (engine#324).
