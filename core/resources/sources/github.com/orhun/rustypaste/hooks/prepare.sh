#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. One job the compose file cannot do for
# itself: generate the upload auth token (and a delete token) ONCE into a place a
# redeploy will not wipe, and make sure the .env the compose references exists.
#
# WHY THIS MATTERS: rustypaste's upload endpoint (POST /) is world-writable
# UNLESS a token is set (config.rs: "If neither AUTH_TOKEN ... nor
# [server].auth_tokens are set, the server will not require any authentication").
# Without a token anyone on the internet could fill the account's disk. So a
# token is mandatory, it must be strong, and it must stay stable across
# redeploys (a regenerated token would silently lock the owner out of uploading).
set -e
cd ~/project

say() { echo "[rustypaste] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The token must live here or it would be regenerated on every
# rebuild.
STORE_DIR="${HOME}/.panelalpha/rustypaste"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# base64 stripped of +/=/newline so a POSIX env file reads it back verbatim and
# the value carries no character an HTTP header or a shell would mangle. 32 raw
# bytes -> ~43 chars of entropy for the write gate.
if [ ! -f "${APP_ENV}" ]; then
    AUTH_TOKEN="$(openssl rand -base64 32 | tr -d '\n=/+')"
    DELETE_TOKEN="$(openssl rand -base64 32 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once by PanelAlpha and never regenerated. Fed to the container as an
# env_file. config.rs reads AUTH_TOKEN / DELETE_TOKEN and merges them into the
# server token set. AUTH_TOKEN gates every upload (POST /); DELETE_TOKEN gates
# the DELETE endpoint (which stays 404 unless a delete token is set). Retrieval
# of a paste by its URL (GET) stays public by design.
AUTH_TOKEN=${AUTH_TOKEN}
DELETE_TOKEN=${DELETE_TOKEN}
EOF
        cat > "${NOTE}" <<EOF
Rustypaste on this account
==========================

Rustypaste is a minimal self-hosted pastebin / file-upload server (no database;
files live on disk). Retrieving a paste by its URL is PUBLIC -- that is the
point of a pastebin, the links are meant to be shared. UPLOADING is private:
every upload must carry the auth token below, so only the owner can store files
and no stranger can fill the account's disk.

UPLOAD (send the token in the Authorization header)
  URL:   <this account's URL>/
  Token: ${AUTH_TOKEN}

  Example:
    curl -H "Authorization: ${AUTH_TOKEN}" -F 'file=@notes.txt' https://<domain>/
  The response is the public URL of the stored paste; anyone with that URL can
  read it.

DELETE (optional; send this token to remove a paste)
  Token: ${DELETE_TOKEN}
    curl -H "Authorization: ${DELETE_TOKEN}" -X DELETE https://<domain>/<paste>

  Both tokens are generated once and reused on every redeploy; a rebuild does
  not reset them. To rotate a token, edit ${APP_ENV} and redeploy.

DATA
  Uploaded pastes live in /app/upload on a named Docker volume, which survives
  redeploy and storage reclaim. The tokens are kept in ${STORE_DIR} (0600). Do
  not delete this directory.
EOF
    )
    chmod 600 "${APP_ENV}" "${NOTE}"
    say "auth + delete tokens written to ${APP_ENV}; notes in ${NOTE}"
else
    say "reusing the tokens in ${APP_ENV}"
fi

# The compose server service lists ~/project/.env as an env_file; make sure it
# exists even when the platform has not written one yet, so `docker compose up`
# does not abort on a missing file. The account's env_vars are merged in.
touch .env
say "prepare complete"
