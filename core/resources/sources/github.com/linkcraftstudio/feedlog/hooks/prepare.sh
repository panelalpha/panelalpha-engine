#!/bin/sh
# Account shell, after the clone and the overrides, before `docker compose up`.
#
# FeedLog signs its session cookies with BETTER_AUTH_SECRET, and the .env the
# engine makes from .env.example has it as the published
# `replace-me-with-a-32-char-random-string`. This generates one per account,
# once, into ~/.panelalpha/feedlog/auth.env, which the compose override hands
# the app as env_file (FEEDLOG_AUTH_SECRET) and start.sh exports as
# BETTER_AUTH_SECRET. Kept there and not in ~/project, which is emptied
# before every deploy: a secret regenerated on each deploy would sign
# everybody out every time.
set -e

STORE="${HOME}/.panelalpha/feedlog"
mkdir -p "$STORE"
chmod 700 "${HOME}/.panelalpha" "$STORE"

if [ ! -s "$STORE/auth.env" ]; then
    ( umask 077; printf 'FEEDLOG_AUTH_SECRET=%s\n' "$(openssl rand -hex 32)" > "$STORE/auth.env" )
    echo "[panelalpha] feedlog: generated BETTER_AUTH_SECRET into ~/.panelalpha/feedlog/auth.env" >&2
fi
