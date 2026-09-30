#!/bin/bash
set -e
cd ~/project

# The one thing the compose file cannot supply for itself, and a security fix
# rather than a convenience: pretix's first migration creates admin@localhost
# with the password `admin` (pretixbase/0001_initial.py, `initial_user`). A
# pretix that has only been migrated is open to anyone who has read its
# repository, and PRETIX_REGISTRATION defaults to false so that account is also
# the only way in. files/panelalpha-admin-password.py replaces that password
# with the engine's on boot.
#
# PRETIX_ADMIN_EMAIL / PRETIX_ADMIN_PASSWORD are the engine's (`credentials:`
# in panelalpha.yaml), read by the app service from
# ~/.panelalpha/app-credentials.env (the override's env_file).
#
# Everything else pretix needs a secret for it generates itself. SECRET_KEY is
# written to /data/.secret on first boot (settings.py) and read back from there
# afterwards, which is the same volume the database lives on -- there is
# nothing for this hook to add.
#
# The generated service reads env_file: .env; the repository ships none.
touch .env
