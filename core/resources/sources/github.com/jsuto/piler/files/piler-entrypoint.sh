#!/bin/bash
# Wraps the image's own /start.sh with the one value it demands and the engine
# cannot hand it under that name.
#
# start.sh's pre_flight_check aborts the container unless PILER_HOSTNAME is set,
# and PILER_HOSTNAME is not a URL -- it is a bare hostname that ends up as
# `hostid=` in piler.conf, as `server_name` in the nginx vhost and as
# SITE_NAME_CONST in config-site.php. ComposePlaceholders only rewrites keys
# matching /(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i, which PILER_HOSTNAME does not,
# so the compose file carries PA_PUBLIC_URL (which does match, and is rewritten
# to the account's https address) and the hostname is split out of it here --
# the same split the Seafile recipe makes for SEAFILE_SERVER_HOSTNAME.
set -o pipefail

say() { echo "[panelalpha] $*"; }

if [ -z "${PILER_HOSTNAME:-}" ]; then
    # Strip scheme, then anything from the first / or : -- PA_PUBLIC_URL is
    # https://<domain> in a deployed account and http://localhost when the
    # engine had no domain to substitute.
    host=$(printf '%s' "${PA_PUBLIC_URL:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/:].*##')
    if [ -n "${host}" ] && [ "${host}" != "localhost" ]; then
        PILER_HOSTNAME="${host}"
    else
        # Not fatal. Piler needs *a* hostname to write into its configs, and an
        # account whose domain is not yet known still has to come up so the
        # deploy reports something better than "missing env variable". The
        # setup service re-asserts SITE_URL from PA_PUBLIC_URL afterwards.
        PILER_HOSTNAME="archive.local"
        say "PA_PUBLIC_URL gave no usable hostname; falling back to ${PILER_HOSTNAME}"
    fi
    export PILER_HOSTNAME
fi

say "PILER_HOSTNAME=${PILER_HOSTNAME}"

# imapfetch.py, the IMAP/POP3 pull driver that util/import.sh runs from cron
# every five minutes, does `pushd /var/piler/imap` and writes its per-mailbox
# state there. The .deb creates it, but a redeploy onto a fresh container with
# an existing piler_store volume can leave it missing, and import.sh's
# `set -o errexit` turns that into a silent no-op import.
mkdir -p /var/piler/imap
chown piler:piler /var/piler/imap 2>/dev/null || true

exec /start.sh
