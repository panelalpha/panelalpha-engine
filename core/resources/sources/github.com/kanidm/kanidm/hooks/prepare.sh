#!/bin/bash
# The directory the `recover` service writes the built-in admins' passwords to
# (admins.env), in ~/.panelalpha: it survives redeploys, ~/project does not,
# and it is private to the account.
set -e
STORE="${HOME}/.panelalpha/kanidm"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
