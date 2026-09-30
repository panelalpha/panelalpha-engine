#!/bin/bash
# Runs on the account after the clone and before the build, as the account
# user, with ~/project as the working directory.
#
# One job the build must not be left to decide (the administrator login is the
# engine's, `credentials:` in panelalpha.yaml, in ~/.panelalpha/app-credentials.env):
# a .env of our own, so the engine does not copy .env.example into place.
# That file ships SS_DATABASE_SERVER="localhost", SS_DATABASE_USERNAME=
# "<user>" and SS_ENVIRONMENT_TYPE="dev"; the generated compose names .env
# in env_file:, and env_file is read when the container is created -- so
# those placeholders would be in the environment before the entrypoint had
# a chance to say otherwise, and `dev` prints the database password into
# the browser on any error.
#
# Nothing here globs docker-compose.*.yml: the engine has already written its
# own docker-compose.override.yml into this directory by the time this runs,
# and a glob would eat it.
#
# Every pipeline below is written so it cannot fail under `pipefail`. A first
# draft used `find ... | grep -v | head -n1` to guess the domain, and on an
# account where nothing matched the empty `grep` exited 1, took the command
# substitution with it, and failed the whole deploy with no output at all.
set -euo pipefail

# Our .env: no credentials in it, only the two settings that have to be true
# before the first request. The database reaches SilverStripe through the
# process environment instead -- see files/.panelalpha/env.sh.
cat > .env <<'EOF'
# Written by the PanelAlpha SilverStripe recipe, replacing the copy of
# .env.example the engine would otherwise make. Database credentials are NOT
# here: the account's own MySQL is provisioned by the engine and exported into
# the entrypoint's shell by .panelalpha/env.sh, so nothing secret is stored in
# this directory.
SS_ENVIRONMENT_TYPE=live
SS_DATABASE_CLASS=MySQLDatabase
EOF
chmod 644 .env
