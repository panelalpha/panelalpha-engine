#!/bin/bash
# Completes Wiki.js's setup wizard, from inside the wiki container, beside the
# server it talks to.
#
# A fresh Wiki.js has no admin and no site URL: server/core/config.js reads one
# YAML file and merges the rest from the `settings` table, which is empty, so
# WIKI.config.setup is true and kernel.js boots server/setup.js instead of the
# real app. That wizard is the only way in -- there is no CLI, no seed, no
# env var for any of it -- and its POST /finalize is what writes the admin
# user, the guest account, the two system groups, the default locale, the
# session secret, the RSA keypair and `host`. Without it the account's site is
# a wizard nobody has the answers to: `siteUrl` defaults to
# https://wiki.yourdomain.com in the form (client/components/setup.vue), so
# even a customer who finds the page has to know their own address.
#
# /finalize takes a plain JSON body and has no CSRF token, no session and no
# other guard -- it is reachable precisely because the wiki is unconfigured.
# It exists only in setup mode: once WIKI.config.setup is false the route is
# gone (master.js mounts a different app), so a repeat call on a configured
# wiki 404s. Which matters, because the wizard's error path truncates the
# `settings` table -- hence the check below runs first and this never posts to
# a wiki that already answers in master mode.
#
# Writes /wiki/data/.panelalpha-ready when the wiki is configured and
# answering, or when it has given up. That file is half of the container's
# healthcheck, and the readiness gate in docker-compose.yml waits on it.

URL=http://127.0.0.1:3000
MARKER=/wiki/data/.panelalpha-ready
SITE_URL="${WIKI_SITE_URL:-http://localhost}"

log() { echo "[panelalpha-setup] $*"; }

rm -f "$MARKER"

# `"ok":true` is the JSON master mode answers /healthz with
# (server/controllers/common.js). In setup mode the same path returns the
# wizard's HTML, because its Express app answers `app.get('*')` with the setup
# page -- so this is the one question that distinguishes the two modes.
configured() {
    curl -fsS --max-time 5 "$URL/healthz" 2>/dev/null | grep -q '"ok":true'
}

# Migrations against an empty database before anything listens. ~4 minutes of
# patience; the observed first boot is well under one.
log "waiting for Wiki.js to listen on 3000"
for _ in $(seq 1 120); do
    curl -fsS -o /dev/null --max-time 5 "$URL/healthz" 2>/dev/null && break
    sleep 2
done

if configured; then
    log "already configured, nothing to do"
else
    if [ -z "$WIKI_ADMIN_EMAIL" ] || [ -z "$WIKI_ADMIN_PASSWORD" ]; then
        log "no admin credentials in the environment; leaving the setup wizard up"
        touch "$MARKER"
        exit 0
    fi
    log "finalizing setup with siteUrl=$SITE_URL admin=$WIKI_ADMIN_EMAIL"
    # --max-time 300: /finalize generates a 2048-bit RSA keypair, writes ~15
    # config rows, seeds the locale, groups, users, navigation and the module
    # tables, and only then answers.
    RESPONSE=$(curl -sS --max-time 300 -X POST "$URL/finalize" \
        -H 'Content-Type: application/json' \
        -d "$(cat <<JSON
{"adminEmail":"${WIKI_ADMIN_EMAIL}","adminPassword":"${WIKI_ADMIN_PASSWORD}","adminPasswordConfirm":"${WIKI_ADMIN_PASSWORD}","siteUrl":"${SITE_URL}","telemetry":false}
JSON
)" 2>&1)
    log "finalize answered: ${RESPONSE}"
fi

# The wizard answers the POST and *then* tears its own HTTP server down and
# reboots into master mode a second later, so the port is closed for a moment
# right after a successful finalize. Waiting for master mode here is what keeps
# the engine's port probe from landing in that window.
log "waiting for the configured wiki to answer"
for _ in $(seq 1 120); do
    configured && break
    sleep 2
done

if configured; then
    log "setup complete"
else
    # Not a failure to hold the deploy on: an unconfigured Wiki.js still serves
    # its wizard, and a deploy that ends on the wizard is worth more than one
    # that never returns. The marker is written either way so the healthcheck
    # can pass on whatever is actually being served.
    log "wiki is still in setup mode; leaving the wizard up"
fi
touch "$MARKER"
