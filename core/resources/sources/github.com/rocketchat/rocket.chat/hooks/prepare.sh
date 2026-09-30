    #!/bin/bash
    set -e
    cd ~/project
    
    # ~/project is wiped on every deploy, but the mongo volume is not: the admin
    # password and the MongoDB major are chosen once and kept in ~/.panelalpha.
    # A regenerated password would be one the database never heard of, and a
    # different major could not open the existing data.
    STATE_DIR="${HOME}/.panelalpha/rocketchat"
    STATE="${STATE_DIR}/state.env"
    mkdir -p "${STATE_DIR}"
    chmod 700 "${STATE_DIR}"
    if [ -f "${STATE}" ]; then
        # shellcheck disable=SC1090
        . "${STATE}"
fi

# The tag comes from the checkout rather than from a constant. The root
# package.json's "version" is the second key in the file, so the first match is
# the one wanted: "8.9.0-develop" -> 8.
RC_MAJOR=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9]\{1,\}\)\..*/\1/p' package.json | head -1)
case "${RC_MAJOR}" in
    '' | *[!0-9]*) RC_MAJOR='' ;;
esac

# Docker Hub carries no major or minor tag for this repository - only exact
# x.y.z releases, their -rc.N and -fips variants, and :latest - so there is no
# release-<major> to pin to the way Mattermost has. The tag list comes back
# newest-first, and the closing quote in the pattern is what keeps 8.9.0-rc.0
# and 8.8.1-fips out of the answer. develop carries the version being worked
# towards, which usually has no release yet, so this lands on the newest
# published release of the same major instead.
ROCKETCHAT_TAG=latest
if [ -n "${RC_MAJOR}" ]; then
    FOUND=$(curl -fsS --max-time 20 \
        "https://hub.docker.com/v2/repositories/rocketchat/rocket.chat/tags?page_size=100&name=${RC_MAJOR}." 2>/dev/null \
        | tr ',' '\n' \
        | sed -n 's/.*"name": *"\('"${RC_MAJOR}"'\.[0-9]\{1,\}\.[0-9]\{1,\}\)".*/\1/p' \
        | head -1)
    if [ -n "${FOUND}" ]; then
        ROCKETCHAT_TAG="${FOUND}"
    fi
fi

# MongoDB's major is chosen from the host's kernel rather than pinned. 8.0 is
# what Rocket.Chat's own docker-compose-ci.yml tests against and the only major
# Rocket.Chat 9 will accept, but every 8.x image refuses to boot on Linux 6.19
# or newer:
#
#   MongoDB cannot start: Linux kernel versions 6.19 and newer has a known
#   incompatibility with this version of MongoDB.   (SERVER-121912)
#
# That is a fatal exit before mongod starts, so the replica-set healthcheck
# never passes and the whole deploy is rolled back. 7.0 carries no such guard
# and is still supported — apps/meteor/server/startup/serverRunning.ts exits
# only on <7.0.0 and prints a deprecation box on <8.0.0. Containers share the
# host's kernel, so uname here is the kernel mongod will run on.
# Chosen on the first deploy only: 7.0 cannot open data written by 8.0, so a
# later kernel upgrade must not silently switch the major (see README.md).
if [ -z "${MONGO_IMAGE:-}" ]; then
    KERNEL_MAJOR=$(uname -r | cut -d. -f1)
    KERNEL_MINOR=$(uname -r | cut -d. -f2)
    case "${KERNEL_MAJOR}${KERNEL_MINOR}" in
        '' | *[!0-9]*) MONGO_IMAGE=mongo:7.0 ;;
        *)
            if [ "${KERNEL_MAJOR}" -gt 6 ] || { [ "${KERNEL_MAJOR}" -eq 6 ] && [ "${KERNEL_MINOR}" -ge 19 ]; }; then
                MONGO_IMAGE=mongo:7.0
            else
                MONGO_IMAGE=mongo:8.0
            fi
            ;;
    esac
fi

# Rocket.Chat has no installer, and its setup wizard hands admin to whoever
# completes it. insertAdminUserFromEnv() in apps/meteor/server/startup/
# initialData.ts creates the account from ADMIN_* instead, on a boot where no
# user holds the admin role. RC_ADMIN_USERNAME / RC_ADMIN_EMAIL /
# RC_ADMIN_PASSWORD are the engine's; read after state.env, which held them on
# older accounts. The password carries a symbol (`symbol: true`) for Rocket.Chat's
# default policy: MinLength 14 and every character class required.
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a

if [ ! -f "${STATE}" ]; then
    umask 077
    cat > "${STATE}" <<EOF
MONGO_IMAGE=${MONGO_IMAGE}
EOF
    chmod 600 "${STATE}"
fi

# Compose interpolates the admin values in the override from here.
cat > .env <<EOF
ROCKETCHAT_IMAGE=rocketchat/rocket.chat:${ROCKETCHAT_TAG}
MONGO_IMAGE=${MONGO_IMAGE}
RC_ADMIN_USERNAME=${RC_ADMIN_USERNAME}
RC_ADMIN_EMAIL=${RC_ADMIN_EMAIL}
RC_ADMIN_PASSWORD=${RC_ADMIN_PASSWORD}
EOF
chmod 600 .env
