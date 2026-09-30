#!/bin/bash
# Hashes the admin password into ~/.panelalpha (survives redeploys; ~/project
# does not). The login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs. The
# users-seed service reads the hash.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/filegator"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
# Hashed on every deploy; the seed only uses it while users.json holds the default.
(umask 077; php -r '
    $p = getenv("FILEGATOR_ADMIN_PASSWORD");
    if ($p === false || strlen($p) < 12) { fwrite(STDERR, "FILEGATOR_ADMIN_PASSWORD missing or too short\n"); exit(1); }
    echo password_hash($p, PASSWORD_BCRYPT), "\n";' > "${STORE}/admin.hash")
chmod 600 "${STORE}/admin.hash"
