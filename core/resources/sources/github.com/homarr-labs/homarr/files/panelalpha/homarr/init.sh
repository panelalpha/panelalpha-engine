#!/bin/sh
# Entrypoint of the one-shot `init` service. Runs as root, before `app` is
# allowed to start, and has to exit 0 or the deploy fails -- which is the
# point: `app` declares `init: service_completed_successfully`, so an init that
# cannot close Homarr's onboarding means no site rather than an open one.
#
# The image's own entrypoint is not used here (it would chown and then exec
# run.sh, i.e. start the server), so the two things it does that matter have to
# be done by hand: create the directories run.sh expects, and expand the
# `_FILE` variables.
set -e

say() { echo "[panelalpha] homarr init: $*"; }

# run.sh creates these on every boot; the migration step below needs db/ to
# exist before it can open the database ("Cannot open database because the
# directory does not exist"), and the bind mount starts out empty.
mkdir -p /appdata/db /appdata/redis /appdata/trusted-certificates

# The same loop the image's entrypoint.sh runs, for the same reason: keeps
# SECRET_ENCRYPTION_KEY out of the compose file and out of `docker inspect`.
for file_var in $(env | cut -d '=' -f 1 | grep '_FILE$'); do
    target_var=$(echo "$file_var" | sed 's/_FILE$//')
    file_path=$(printenv "$file_var")
    if [ -f "$file_path" ]; then
        export "$target_var=$(tr -d '\n\r' < "$file_path")"
    else
        echo "[panelalpha] homarr init: $file_path is missing; $target_var cannot be set" >&2
        exit 1
    fi
done

cd /app

# Upstream's own migration runner, the same invocation run.sh uses. It is not
# only DDL: it seeds the board called `dashboard` with seven widgets, the four
# default custom widget definitions and the search engines, and makes that
# board the home board of the `everyone` group. Running it here rather than
# leaving it to run.sh is what lets bootstrap.mjs find a schema to write into.
# It is idempotent (drizzle keeps __drizzle_migrations), and run.sh will run it
# again when `app` starts.
say "running database migrations"
DISABLE_REDIS_LOGS=true node ./db/migrations/sqlite/migrate.cjs ./db/migrations/sqlite

# Creates the account owner's administrator and marks onboarding finished.
say "closing the installer"
node /pa/bootstrap.mjs

say "done"
