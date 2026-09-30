#!/bin/bash
# The image refuses all access without a /cfg/*.conf (Docker failsafe). Write
# upstream's example config once, minus its example account; ~/project is wiped.
set -e
STORE="${HOME}/.panelalpha/copyparty"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/copyparty.conf" ]; then
    cat > "${STORE}/copyparty.conf" <<'CONF'
# copyparty config (docs/examples/docker/basic-docker-compose/copyparty.conf).
# Edit and redeploy. Add accounts under [accounts] ("name: password") and
# grant them rights under accs, e.g. "rwmda: name"; see docs/example.conf.

[global]
  e2dsa  # enable file indexing and filesystem scanning
  e2ts   # enable multimedia indexing
  ansi   # enable colors in log messages
  # The PanelAlpha proxy is a private address; take the hop it added,
  # never the client-supplied first X-Forwarded-For entry.
  xff-src: lan
  rproxy: -1

[/]            # create a volume at "/" (the webroot), which will
  /w           # share /w (the docker data volume)
  accs:
    rw: *      # everyone gets read-write access
  flags:
    e2ds       # enable filesystem-scanning for this volume only
CONF
fi
# Read by the container's user (root in the image) through a bind mount.
chmod 644 "${STORE}/copyparty.conf"
