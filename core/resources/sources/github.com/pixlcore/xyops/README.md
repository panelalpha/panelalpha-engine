# xyOps (github.com/pixlcore/xyops)

Job scheduler, workflow engine and server monitoring system (Node.js, storage
on local disk under `data/`).

## Deploying

Deploy as is, open the site and sign in with the login the engine generates:
`XYOPS_ADMIN_USERNAME` (always `admin`) and `XYOPS_ADMIN_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile as the
  dockerfile strategy does: port 5522, `/opt/xyops/data` on the named volume
  `data-opt-xyops-data` (the name that strategy gives it, so an account
  deployed before keeps its data), plus the engine's login as `env_file` and
  the wrapper below as the entrypoint.
- xyOps's first start creates the stock `admin` with the password `admin`
  (`internal/setup.json`), and there is no sign-up.
- `files/panelalpha-xyops-start.sh` runs `files/panelalpha-xyops-admin.js`
  first, which opens the storage read-only with upstream's own
  `pixl-server-storage` (as `bin/storage-cli.js` does, before the server
  runs) and says whether storage is new, `users/admin` still has the stock
  password hash, or neither. In the first two cases it exports
  `XYOPS_recover_admin=admin:<password>` for this start only: upstream's
  recovery setting, which writes `users/admin` with that password when the
  conductor becomes primary, right after a new install's initial records.
  Then it execs `bin/container-start.sh`, the image's CMD. A password changed
  in xyOps, or an admin deleted there, is never touched.
- The username stays `admin`: a recovery account under another name would be
  a second, hidden admin next to the stock one.

## Caveats

- The recovery logs a critical activity entry, "Created emergency admin
  account: admin", once on the first start.
- On a new install the stock record exists for the moment between the
  initial records and the recovery, inside the same startup step.
