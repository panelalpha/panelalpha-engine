#!/bin/sh
# Runs as root before Drydock's own entrypoint (/usr/bin/entrypoint.sh).
#
# Drydock's only login is DD_AUTH_BASIC_<name>_USER / DD_AUTH_BASIC_<name>_HASH,
# an argon2id hash, from the environment; with none a fresh install refuses
# every visitor. Here they come from the login the engine generated: the hash
# is made the way upstream's docs make it (Node's crypto.argon2Sync, argon2id,
# m=65536 t=3 p=4, a 32-byte random salt, a 64-byte tag), on every start, so a
# new salt each time and the same password. A DD_AUTH_BASIC_ADMIN_USER the
# customer sets wins. The Dockerfile COPYs only its own directories, so this
# file is never in the image.
set -e

say() { echo "[panelalpha] drydock: $*" >&2; }

if [ -n "${DD_AUTH_BASIC_ADMIN_USER:-}" ]; then
    say "DD_AUTH_BASIC_ADMIN_USER is set; the generated login is not used"
elif [ ! -r /pa/app-credentials.env ]; then
    say "no /pa/app-credentials.env; no login set"
else
    # In a subshell, so only the user and the hash leave it.
    login="$(
        set -a
        . /pa/app-credentials.env
        set +a
        if [ -z "${DRYDOCK_ADMIN_USER:-}" ] || [ -z "${DRYDOCK_ADMIN_PASSWORD:-}" ]; then
            exit 1
        fi
        printf '%s\n' "${DRYDOCK_ADMIN_USER}"
        DRYDOCK_ADMIN_PASSWORD="${DRYDOCK_ADMIN_PASSWORD}" node -e '
            const c = require("node:crypto");
            const phc = (b) => b.toString("base64").replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
            const s = c.randomBytes(32);
            const h = c.argon2Sync("argon2id", { message: process.env.DRYDOCK_ADMIN_PASSWORD, nonce: s, memory: 65536, passes: 3, parallelism: 4, tagLength: 64 });
            console.log("$argon2id$v=19$m=65536,t=3,p=4$" + phc(s) + "$" + phc(h));
        '
    )" || login=""
    if [ -n "${login}" ]; then
        DD_AUTH_BASIC_ADMIN_USER="$(printf '%s\n' "${login}" | sed -n 1p)"
        DD_AUTH_BASIC_ADMIN_HASH="$(printf '%s\n' "${login}" | sed -n 2p)"
        export DD_AUTH_BASIC_ADMIN_USER DD_AUTH_BASIC_ADMIN_HASH
    else
        say "DRYDOCK_ADMIN_USER or DRYDOCK_ADMIN_PASSWORD is empty, or hashing failed; no login set"
    fi
fi

exec /usr/bin/entrypoint.sh "$@"
