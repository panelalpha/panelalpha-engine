#!/bin/bash
set -e
cd ~/project

# ~/project is emptied and re-cloned on every deploy; ~/.panelalpha is the one
# directory that survives it and belongs to the account. Everything generated
# here that must outlive a deploy lives there.
DATA_HOME="${HOME}/.panelalpha/ghost"
DB_ENV="${DATA_HOME}/database.env"

say() { echo "[panelalpha] ghost: $*" >&2; }

mkdir -p "${DATA_HOME}"
chmod 700 "${DATA_HOME}"

# The image tag comes from the checkout, not from a constant here: a clone of a
# 6.x branch should get a 6.x Ghost. ghost/core/package.json is the server's own
# manifest and its "version" is the second key in the file, so the first match
# is the one wanted (6.65.0-rc.0 -> 6). Anything unreadable falls back to 6
# rather than to "latest", which would silently jump a major on a checkout that
# never asked for one.
GHOST_MAJOR=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9]\{1,\}\)\..*/\1/p' ghost/core/package.json | head -1)
case "${GHOST_MAJOR}" in
    ''|*[!0-9]*) GHOST_MAJOR=6 ;;
esac

# The MySQL credentials, generated once. The data volume outlives the checkout,
# so a password regenerated on a redeploy locks Ghost out of its own database.
# Earlier versions of this recipe kept them only in ~/project/.env, which a
# redeploy deletes; an account deployed that way still has them in its MySQL
# container's environment, and they are adopted from there rather than replaced.
if [ ! -f "${DB_ENV}" ]; then
    OLD_DB=$(docker ps -aq --filter label=com.docker.compose.service=mysql 2>/dev/null | head -1)
    OLD_ENV=""
    if [ -n "${OLD_DB}" ]; then
        OLD_ENV=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "${OLD_DB}" 2>/dev/null \
            | grep -E '^MYSQL_(ROOT_)?PASSWORD=.' || true)
    fi
    (
        umask 077
        if [ "$(printf '%s\n' "${OLD_ENV}" | grep -c .)" = 2 ]; then
            printf '%s\n' "${OLD_ENV}" > "${DB_ENV}"
            say "kept the MySQL credentials of the existing database"
        else
            cat > "${DB_ENV}" <<EOF
MYSQL_PASSWORD=$(openssl rand -hex 16)
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF
        fi
    )
fi

# The owner account is the engine's (`credentials:` in panelalpha.yaml):
# PA_OWNER_EMAIL / PA_OWNER_PASSWORD in ~/.panelalpha/app-credentials.env, read
# only by the one-shot `init` service and used only when the site has no owner
# yet; a site already set up keeps its own.
chmod 600 "${DB_ENV}" 2>/dev/null || true

# ~/project/.env is what compose interpolates the stack from. Only this
# recipe's own keys are replaced, so anything else in it is left alone.
touch .env
chmod 600 .env
sed -i '/^GHOST_IMAGE=/d; /^MYSQL_PASSWORD=/d; /^MYSQL_ROOT_PASSWORD=/d' .env
{
    echo "GHOST_IMAGE=ghost:${GHOST_MAJOR}-alpine"
    cat "${DB_ENV}"
} >> .env
