#!/bin/sh
# Runs as the app container's entrypoint, in front of the image's own
# /app/entrypoint.sh, which it execs at the end.
#
# Everything here is something the Dockerfile cannot state: Authelia has no
# defaults for its three secrets, no default session cookie domain, no sign-up
# page and no bootstrap admin, and the account's public address is not knowable
# until the container starts. Unlike the Vikunja recipe this can all be done in
# the app container itself -- authelia/base is an Alpine with a shell, and the
# `authelia` binary is the only thing that can produce a password hash Authelia
# will accept.
set -e

CONFIG=/config/configuration.yml
USERS=/config/users_database.yml
MARKER='# panelalpha-generated'

log() { echo "[panelalpha] $*"; }

# The account's public address. The dockerfile strategy writes it onto the app
# service as SITE_URL (one of seven aliases, ComposeHarden::urlEnvironment())
# and the bare hostname as SERVER_NAME, so unlike Vikunja nothing has to be
# read back out of the generated compose file.
host="${SERVER_NAME:-}"
if [ -z "${host}" ]; then
    host=$(echo "${SITE_URL:-}" | sed -e 's#^[a-zA-Z]*://##' -e 's#[:/].*$##')
fi
if [ -z "${host}" ]; then
    # Nothing has said what this instance is called. Authelia cannot start
    # without a cookie domain, so pick one that is at least syntactically
    # valid and say so: the portal will serve, and sign-in will set a cookie
    # nobody's browser keeps.
    host='authelia.invalid'
    log "WARNING: no SERVER_NAME or SITE_URL; session cookies will not work"
fi

# authelia_url has to be https -- utils.IsURISecure() is checked before
# anything else about it, and an http:// value is a fatal configuration error.
# The engine terminates TLS in front of the account, so the scheme here is
# https whatever SITE_URL happened to say.
url="https://${host}"

# Rewrite the configuration when the domain it was generated for is not the
# domain this container is answering on -- a project that was renamed, or moved
# to its own domain. A file without the marker was replaced by hand and is left
# exactly as it is.
generate=0
if [ ! -f "${CONFIG}" ]; then
    generate=1
elif head -n 1 "${CONFIG}" | grep -q "^${MARKER} "; then
    previous=$(head -n 1 "${CONFIG}" | sed "s/^${MARKER} //")
    [ "${previous}" = "${host}" ] || generate=1
fi

if [ "${generate}" = 1 ]; then
    mkdir -p /config

    # Secrets come from ~/project/.env through env_file:, written once by
    # hooks/prepare.sh. Inline in the configuration rather than through
    # AUTHELIA_* environment variables: the file is the documented place for
    # them, it is inside the same 0600 /config volume as the SQLite database
    # they protect, and it does not depend on how Authelia's env provider
    # folds underscores back into key paths.
    log "writing ${CONFIG} for ${host}"
    cat > "${CONFIG}" <<EOF
${MARKER} ${host}
#
# Regenerated whenever the account's domain changes. Edit freely -- removing
# the marker line above makes this file permanent and PanelAlpha will not
# touch it again.
---
theme: 'auto'

server:
  address: 'tcp://:9091'

log:
  level: 'info'
  format: 'text'

totp:
  issuer: '${host}'

identity_validation:
  reset_password:
    jwt_secret: '${PA_AUTHELIA_JWT_SECRET}'

authentication_backend:
  password_reset:
    disable: false
  file:
    path: '${USERS}'
    watch: true
    password:
      algorithm: 'argon2'

access_control:
  default_policy: 'two_factor'

session:
  secret: '${PA_AUTHELIA_SESSION_SECRET}'
  cookies:
    - name: 'authelia_session'
      domain: '${host}'
      authelia_url: '${url}'
      expiration: '1 hour'
      inactivity: '5 minutes'
      remember_me: '1 month'

regulation:
  max_retries: 3
  find_time: '2 minutes'
  ban_time: '5 minutes'

storage:
  encryption_key: '${PA_AUTHELIA_STORAGE_ENCRYPTION_KEY}'
  local:
    path: '/config/db.sqlite3'

# No SMTP server comes with the account, so password-reset and identity
# verification mails are written to a file instead of sent. disable_startup_check
# is what stops Authelia probing a mail server that is not there on every boot.
notifier:
  disable_startup_check: true
  filesystem:
    filename: '/config/notification.txt'
...
EOF
    chmod 600 "${CONFIG}"
fi

# The admin account. Authelia's file backend writes its own template when the
# path does not exist (checkDatabase -> userYAMLTemplate), and that template is
# the published `authelia` / `authelia` pair, disabled -- so a first boot that
# reaches it produces an instance with no account anyone can use. This writes a
# real one instead, hashed by the binary that is about to verify it.
#
# argon2id at Authelia's own defaults (m=64MiB, t=3, p=4); the cost is paid
# once, on the first boot only.
if [ ! -f "${USERS}" ] && [ -n "${PA_AUTHELIA_ADMIN_PASSWORD}" ]; then
    user="${PA_AUTHELIA_ADMIN_USERNAME:-admin}"
    log "creating the '${user}' account"
    digest=$(authelia crypto hash generate argon2 --password "${PA_AUTHELIA_ADMIN_PASSWORD}" \
        | sed -n 's/^Digest: //p')
    if [ -z "${digest}" ]; then
        log "ERROR: could not hash the admin password; Authelia will write its own template"
    else
        cat > "${USERS}" <<EOF
${MARKER}
---
users:
  ${user}:
    disabled: false
    displayname: 'Administrator'
    password: '${digest}'
    email: '${user}@${host}'
    groups:
      - admins
      - dev
...
EOF
        chmod 600 "${USERS}"
    fi
fi

exec /app/entrypoint.sh "$@"
