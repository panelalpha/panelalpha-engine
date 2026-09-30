#!/bin/sh
# One-shot `init`: migrates the DB (every note-mark command does) and creates the
# owner when absent. Exits non-zero on anything else, so `app` never starts.
set -u

say() { echo "[panelalpha] notemark init: $*"; }

if [ -z "${NOTEMARK_ADMIN_USER:-}" ] || [ -z "${NOTEMARK_ADMIN_PASSWORD:-}" ]; then
    say "no owner credentials in the environment; refusing to start"
    exit 1
fi

out="$(note-mark user add -u "${NOTEMARK_ADMIN_USER}" -p "${NOTEMARK_ADMIN_PASSWORD}" 2>&1)"
rc=$?
if [ "${rc}" -eq 0 ]; then
    say "created owner '${NOTEMARK_ADMIN_USER}'"
elif printf '%s' "${out}" | grep -q 'UNIQUE constraint failed'; then
    # Already seeded: leave it, and any password changed in the app, alone.
    say "owner '${NOTEMARK_ADMIN_USER}' already exists; leaving it alone"
else
    printf '%s\n' "${out}"
    say "could not create the owner (exit ${rc})"
    exit 1
fi
