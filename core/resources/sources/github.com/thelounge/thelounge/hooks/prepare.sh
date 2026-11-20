#!/bin/bash
# The Lounge keeps its users, settings and chat logs in THELOUNGE_HOME.
# ~/project is emptied on every deploy, so that is ~/.panelalpha/thelounge,
# made here so the bind mount is the account's and not root's.
set -e
STORE="${HOME}/.panelalpha/thelounge"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
