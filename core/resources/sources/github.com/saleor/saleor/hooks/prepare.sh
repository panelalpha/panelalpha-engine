#!/bin/bash
# Account shell, after the clone and after overrides/ and files/ have been
# written, before the build.
#
# One job: put this account's secrets somewhere the next clone will not delete,
# and tell the owner where the administrator password is.
set -e
cd ~/project

say() { echo "[saleor] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one.
# Nothing below reads the checkout, but saying so turns a confusing result
# into one line in the deploy log.
if [ ! -f saleor/settings.py ] || [ ! -f saleor/celeryconf.py ]; then
    say "WARNING: this does not look like saleor/saleor"
fi

# ---------------------------------------------------------------------------
# 1. Secrets.
#
# engine#173: every deploy re-clones and ProjectTree::clearContents()
# (GitRepository.php:89) empties ~/project first, so a guard on a file in there
# never fires. Each of these has a different consequence if it is regenerated:
#
#   SECRET_KEY        signs password-reset and email-confirmation tokens
#                     (settings.py:258); a new one invalidates every one in
#                     flight.
#   RSA_PRIVATE_KEY   signs every access and refresh token Saleor issues and is
#                     published as the account's JWKS
#                     (saleor/core/jwt_manager.py:79, /.well-known/jwks.json);
#                     a new one logs out every client and every installed app.
#   POSTGRES_PASSWORD is already inside the pgdata volume. Regenerating it does
#                     not change the role, it just locks the application out of
#                     its own database with an authentication failure it cannot
#                     recover from.
#
# ~/.panelalpha/saleor/ survives the clone (`find ~/project -mindepth 1` never
# reaches it) and is the only place in the account that does. 0600 in a 0700
# directory; delivered to the containers as a second env_file entry, which
# compose appends to the first, plus a read-only mount for the PEM.
STORE_DIR="${HOME}/.panelalpha/saleor"
ENV_STORE="${STORE_DIR}/saleor.env"
RSA_PEM="${STORE_DIR}/jwt-rsa.pem"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_STORE}" ]; then
    ADMIN_EMAIL=admin@localhost
    # No '/', '+' or '=' -- this value is read back by a POSIX shell, written
    # into an env file with no quoting, and pasted into a GraphQL string.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated. Deleting
# this file does not reset the application: POSTGRES_PASSWORD is already stored
# in the pgdata volume and the administrator already exists in it.

# settings.py:258 reads this from the environment with no default. With DEBUG
# on it silently becomes a fresh random key in every process; with DEBUG off it
# is None and Django raises on first use.
SECRET_KEY=${SECRET_KEY}

# Read by postgres on its first boot to create the role, and by Saleor on every
# boot to connect as it. DATABASE_URL is assembled from it in the entrypoint
# rather than stored, so the password appears in exactly one file.
POSTGRES_PASSWORD=${PG_PASSWORD}

# The superuser created by panelalpha/saleor/init.sh before anything listens.
# Saleor's USERNAME_FIELD is the email (saleor/account/models.py:220).
SALEOR_ADMIN_EMAIL=${ADMIN_EMAIL}
SALEOR_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the secrets in ${ENV_STORE}"
fi

# The JWT signing key is a PEM, which an env_file cannot hold: compose reads
# env files line by line and a newline ends the value. It is kept as a file and
# read into RSA_PRIVATE_KEY by the entrypoint instead, which is the only form
# saleor/settings.py:275 accepts.
#
# 2048 bits, matching what jwt_manager.py's own debug-key generator uses.
if [ ! -f "${RSA_PEM}" ]; then
    (umask 077; openssl genrsa -out "${RSA_PEM}" 2048 2>/dev/null)
    say "JWT signing key generated at ${RSA_PEM}"
fi
# Re-applied every deploy rather than trusted from the umask at creation, in
# case something else has been at them. Not with a bare `chmod`, though: under
# `set -e` a chmod that cannot run ends the deploy, and it cannot run whenever
# the file is not owned by this account -- which is exactly the situation where
# an operator has been in here with a root shell. The first version of this
# script did that and failed a deploy over a file that was already 0600.
#
# What matters is the mode, not who applied it: fix what can be fixed, and fail
# only if the file is still readable by anyone but its owner.
for secret in "${ENV_STORE}" "${RSA_PEM}"; do
    chmod 600 "${secret}" 2>/dev/null || true
    mode="$(stat -c '%a' "${secret}" 2>/dev/null || echo '')"
    case "${mode}" in
        600|400) ;;
        '') say "WARNING: cannot stat ${secret}" ;;
        *)
            say "${secret} is mode ${mode} and cannot be changed; it holds this account's database password and administrator password"
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# 2. The signing key, in a form an env file can carry.
#
# The PEM is multi-line and compose reads an env file a line at a time, so the
# key has to reach the containers some other way. The obvious route is a bind
# mount of this directory -- and that is the one thing this compose file must
# not do.
#
# ComposeFileInspector::serviceBindsProjectRoot() treats any service that has a
# `build:` **and** a bind whose source starts with `./` as a workstation
# live-reload mount, and one such service makes isLocalDevCompose() true for the
# whole file. ComposeUsableProbe then skips the file, detection falls through to
# the root Dockerfile, and the engine runs its own generated single-container
# compose instead of this one -- silently: the log says "Detected project type:
# Dockerfile" and never mentions the compose file it declined. Measured; it is
# what the first deploy of this recipe did, with init, api and worker collapsed
# into network aliases on one container.
#
# So the build services mount nothing. The scripts get in by being in the build
# context (the Dockerfile's `COPY . /app`, and .dockerignore excludes `.*`,
# media, static and node_modules but not panelalpha/), and the key gets in
# base64-encoded on one line, which files/panelalpha/saleor/env.sh decodes back.
# A base64 blob in a 0600 file is not weaker than the PEM it encodes; it is the
# same bytes with no newlines in them.
if ! grep -q '^RSA_PRIVATE_KEY_B64=' "${ENV_STORE}"; then
    (
        umask 077
        {
            echo
            echo "# saleor/settings.py:275 wants the PEM itself and"
            echo "# saleor/core/jwt_manager.py:84 raises without it once DEBUG is off. It"
            echo "# signs every access token and is published as this account's JWKS at"
            echo "# /.well-known/jwks.json, so a new one logs out every client."
            printf 'RSA_PRIVATE_KEY_B64=%s\n' "$(base64 -w 0 < "${RSA_PEM}")"
        } >> "${ENV_STORE}"
    )
    say "JWT signing key encoded into ${ENV_STORE}"
fi

# ---------------------------------------------------------------------------
# 3. The note.
#
# Written every deploy: it is derived from the store, contains nothing the
# store does not, and a first deploy that failed after writing the env file
# would otherwise leave the owner with no note at all.
ADMIN_EMAIL="$(sed -n 's/^SALEOR_ADMIN_EMAIL=//p' "${ENV_STORE}" | head -n 1)"
ADMIN_PASSWORD="$(sed -n 's/^SALEOR_ADMIN_PASSWORD=//p' "${ENV_STORE}" | head -n 1)"
(
    umask 077
    cat > "${NOTE}" <<EOF
Saleor administrator for this account
=====================================

  email:    ${ADMIN_EMAIL}
  password: ${ADMIN_PASSWORD}

Saleor is a headless API. There is no login page: you authenticate by sending
the tokenCreate mutation to /graphql/ and using the token it returns as a
bearer token on every later request.

  curl -s https://<your-domain>/graphql/ \\
    -H 'content-type: application/json' \\
    -d '{"query":"mutation{tokenCreate(email:\\"${ADMIN_EMAIL}\\",password:\\"...\\"){token errors{message}}}"}'

  curl -s https://<your-domain>/graphql/ \\
    -H 'content-type: application/json' \\
    -H "authorization: Bearer <token>" \\
    -d '{"query":"{me{email isStaff}}"}'

Opening /graphql/ in a browser gives you the same thing with a schema browser
around it. The Saleor Dashboard is a separate application
(github.com/saleor/saleor-dashboard) and is not part of this repository or this
deployment; point one at https://<your-domain>/graphql/ if you want a shop UI.

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password through the API, this file is out of date and the one in
the application wins -- nothing here overwrites it.
EOF
)
