#!/bin/bash
# Writes the admin password's bcrypt hash where the container reads it. The
# login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
# Hashed on every deploy, so a password set in the project's env takes effect on redeploy.
mkdir -p panelalpha/tinyfm
php -r '
    $p = getenv("TFM_ADMIN_PASSWORD");
    if ($p === false || strlen($p) < 12) { fwrite(STDERR, "TFM_ADMIN_PASSWORD missing or too short\n"); exit(1); }
    echo password_hash($p, PASSWORD_BCRYPT), "\n";' > panelalpha/tinyfm/admin.hash
chmod 644 panelalpha/tinyfm/admin.hash
