#!/bin/sh
# One-shot: seeds the owner account. FitTrackee's entrypoint applies migrations
# and starts gunicorn but creates no admin, and the first real user would
# normally self-register then be promoted. This creates the account directly.
#
# Runs after the API is healthy (/api/check-db passes => migrations applied), so
# the schema exists.
#
# Idempotency note: `ftcli users create` on an existing username PRINTS an error
# ("sorry, that username is already taken") but still EXITS 0, so the decision is
# made on the output, not the exit code. On a redeploy the account already
# exists -> the create is a genuine no-op (the seeded password is left
# untouched, so sessions survive) and only the role is re-asserted. Any real
# failure (e.g. a misconfigured env crashing create_app) is fatal, so the deploy
# never finishes green without an admin.
set -e

: "${FT_ADMIN_USER:?FT_ADMIN_USER is not set; prepare.sh did not run}"
: "${FT_ADMIN_EMAIL:?FT_ADMIN_EMAIL is not set}"
: "${FT_ADMIN_PASSWORD:?FT_ADMIN_PASSWORD is not set}"

out="$(ftcli users create "${FT_ADMIN_USER}" \
        --email "${FT_ADMIN_EMAIL}" \
        --password "${FT_ADMIN_PASSWORD}" \
        --role owner 2>&1)" || true
echo "${out}"

if printf '%s' "${out}" | grep -qiE "already taken|already exist|already registered|duplicate"; then
    echo "[panelalpha] account '${FT_ADMIN_USER}' already exists; password left unchanged, re-asserting role"
    ftcli users update "${FT_ADMIN_USER}" --set-role owner
elif printf '%s' "${out}" | grep -qiE "traceback|error|exception|not set|keyerror"; then
    echo "[panelalpha] seed-admin failed for a reason other than an existing account" >&2
    exit 1
else
    echo "[panelalpha] owner account '${FT_ADMIN_USER}' created"
fi
