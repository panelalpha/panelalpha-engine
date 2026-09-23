#!/bin/bash
set -e

# The compose file binds ~/.panelalpha/gobookmarks into the container as the
# app's data directory (the SQLite database, the session.key cookie secret and
# the local-git store). ~/.panelalpha survives a redeploy and a rollback where
# ~/project does not, so this is what makes a login and a saved bookmark
# durable. Create it before `compose up` so the bind source exists and is
# owned by the account (0700), rather than being auto-created root-owned.
mkdir -p ~/.panelalpha/gobookmarks
chmod 700 ~/.panelalpha ~/.panelalpha/gobookmarks
