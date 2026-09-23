#!/bin/sh
# Entrypoint of the one-shot `init` service. Runs as the account's uid, working
# directory /readeck, before `app` is allowed to start, and has to exit 0 or the
# deploy fails -- which is the point: `app` declares
# `init: service_completed_successfully`, so an init that cannot close Readeck's
# onboarding means no site rather than an open one.
#
# Two jobs, in order:
#   1. Migrate the schema.
#   2. Create this account's administrator when the instance has none, which
#      closes the anonymous /onboarding form (engine#200).
set -e

say() { echo "[panelalpha] readeck init: $*"; }

CFG="config.toml"

# The migrations. `readeck serve` would run these too, but it starts listening
# first, so doing them here -- before `app` exists -- means the schema is ready
# before anything can reach it.
say "running database migrations"
/bin/readeck migrate -config "${CFG}"

USER_NAME="${READECK_ADMIN_USER:-admin}"

# Does the administrator already exist? `readeck user -n -json` is a dry run: it
# reports {"exists":...,"status":"create"|"update"} and changes nothing. `create`
# means the user is absent, which on a fresh instance means zero users and an
# open /onboarding form; `update` means it is already there and must be left
# alone, so a redeploy does not reset a password the owner changed in the app.
probe() {
    /bin/readeck user -config "${CFG}" -u "${USER_NAME}" -p env:READECK_ADMIN_PASSWORD -n -json 2>/dev/null \
        | grep -o '"status":"[a-z]*"' | head -1 | sed 's/.*:"//;s/"//'
}

status="$(probe || true)"
say "administrator '${USER_NAME}' status: ${status:-unknown}"

if [ "${status}" = "create" ]; then
    if [ -z "${READECK_ADMIN_PASSWORD:-}" ]; then
        say "no administrator password in the environment; refusing to leave /onboarding open"
        exit 1
    fi
    say "creating administrator '${USER_NAME}'"
    /bin/readeck user -config "${CFG}" \
        -u "${USER_NAME}" \
        -p env:READECK_ADMIN_PASSWORD \
        -email "${READECK_ADMIN_EMAIL:-admin@localhost}" \
        -group admin

    # Confirm. The only thing standing between /onboarding and the internet is
    # this user, so a create that silently did nothing must fail the deploy.
    status="$(probe || true)"
    if [ "${status}" != "update" ]; then
        say "the administrator was not created (status: ${status:-unknown}); /onboarding would be open, stopping"
        exit 1
    fi
    say "administrator created; /onboarding is closed"
elif [ "${status}" = "update" ]; then
    say "an administrator already exists; leaving it and its password alone"
else
    # Could not read the probe at all -- refuse to guess rather than risk
    # bringing the site up with an open installer.
    say "could not determine whether an administrator exists; stopping"
    exit 1
fi

say "done"
