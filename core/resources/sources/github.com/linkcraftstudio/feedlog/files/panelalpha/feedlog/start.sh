#!/bin/sh
# The app container's command: upstream's own `node .output/server/index.mjs`,
# with two environment variables the compose file cannot give it.
#
# - SYSTEM_ADMIN_EMAILS: an email listed there becomes the owner of the
#   workspace when it signs up. compose.yml takes it from .env, which the
#   engine makes from .env.example, where it is `admin@example.com`: whoever
#   signed up with that address first owned the install, and nobody deploying
#   it was told. Here it is FEEDLOG_ADMIN_EMAIL, the email the engine generated
#   (~/.panelalpha/app-credentials.env, env_file), which create-admin.mjs signs
#   up before the deploy finishes.
# - BETTER_AUTH_SECRET: signs the session cookies. .env.example has the
#   published `replace-me-with-a-32-char-random-string`; hooks/prepare.sh
#   generates one per account into ~/.panelalpha/feedlog/auth.env, which
#   reaches the container as FEEDLOG_AUTH_SECRET (env_file).
#
# compose.yml sets both under `environment:`, which env_file cannot beat, so
# they are exported here, just before the exec.
set -e

if [ -z "${FEEDLOG_ADMIN_EMAIL:-}" ]; then
    echo "[panelalpha] no FEEDLOG_ADMIN_EMAIL in ~/.panelalpha/app-credentials.env; not starting with the published admin email" >&2
    exit 1
fi
if [ -z "${FEEDLOG_AUTH_SECRET:-}" ]; then
    echo "[panelalpha] no FEEDLOG_AUTH_SECRET from ~/.panelalpha/feedlog/auth.env; not starting with the published BETTER_AUTH_SECRET" >&2
    exit 1
fi

export SYSTEM_ADMIN_EMAILS="$FEEDLOG_ADMIN_EMAIL"
export BETTER_AUTH_SECRET="$FEEDLOG_AUTH_SECRET"

exec node .output/server/index.mjs
