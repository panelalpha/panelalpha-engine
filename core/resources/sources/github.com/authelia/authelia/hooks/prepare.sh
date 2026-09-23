#!/bin/bash
set -e
cd ~/project

# 1. A Dockerfile that can be built from a clone.
#
# The repository's root `Dockerfile` is the release one: it copies a binary
# CI built (`COPY authelia-${TARGETOS}-${TARGETARCH}/authelia`) and takes the
# base image tag from `ARG TAG`/`ARG SHA` it never sets. DockerfileFinder
# rejects it for exactly that reason (missingContextSource) and falls through
# to the next `dockerfile.*` in the root listing, which is
# `Dockerfile.coverage` -- upstream's CI image, whose backend is
# `go build -tags dev -cover -covermode=atomic` and whose frontend is
# `pnpm coverage` (VITE_COVERAGE=true). That builds and runs, but it ships an
# istanbul-instrumented bundle and a coverage-instrumented binary to whoever
# ends up using the portal.
#
# `Dockerfile.dev` is the same two builders without the coverage flags, and it
# is the file this recipe builds -- with `pnpm coverage` put back to
# `pnpm build` and the `dev` build tag dropped. That tag is not cosmetic:
# internal/utils/version_dev.go declares `Dev = true`, and server/template.go
# sends tmplCSPDevelopment instead of tmplCSPDefault when it is set -- a looser
# Content-Security-Policy on every page of a login portal -- while handlers.go
# builds the Duo client with duoapi.SetInsecure().
#
# Derived here with sed rather than shipped as files/Dockerfile so the pinned
# base-image digests stay whatever the checkout pins today. Falls back to the
# file verbatim if the substitutions do not apply to a future revision.
if [ -f Dockerfile.dev ]; then
    sed -e 's/pnpm coverage/pnpm build/' -e 's/-tags dev //' \
        Dockerfile.dev > Dockerfile.panelalpha
    # BuildKit looks for `<dockerfile>.dockerignore` before `.dockerignore`,
    # and this repository has no root `.dockerignore` at all -- so without this
    # copy the build context would be the whole tree, .git and web/node_modules
    # included, and `COPY --link / ./` would put it all in the builder stage.
    cp -f Dockerfile.dev.dockerignore Dockerfile.panelalpha.dockerignore 2>/dev/null || true
fi

# 2. Secrets and the admin credential.
#
# The repository ships no .env and the generated app service reads one through
# `env_file:`, so this file is both the credential store and the way in. The
# bootstrap entrypoint reads these out of its own environment and writes them
# into /config/configuration.yml on first boot.
#
# Written once: the /config volume outlives the checkout, and rolling any of
# these under a live instance is destructive rather than merely inconvenient --
# the storage key decrypts the TOTP secrets already in the SQLite file, and the
# session secret is what the browser cookies out there were signed with.
if [ ! -f .env ]; then
    rand() { openssl rand -hex "$1" 2>/dev/null || head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

    # session.secret signs the session cookies; storage.encryption_key encrypts
    # the TOTP secrets, WebAuthn credentials and OIDC keys at rest (Authelia
    # refuses anything under 20 characters); the jwt_secret signs password-reset
    # tokens. None of the three has a default -- the validator pushes a fatal
    # error for each one that is missing.
    cat > .env <<EOF
PA_AUTHELIA_SESSION_SECRET=$(rand 32)
PA_AUTHELIA_STORAGE_ENCRYPTION_KEY=$(rand 32)
PA_AUTHELIA_JWT_SECRET=$(rand 32)
PA_AUTHELIA_ADMIN_USERNAME=admin
PA_AUTHELIA_ADMIN_PASSWORD=$(rand 12)
EOF
    chmod 600 .env

    # The credential a person has to be handed. Authelia has no sign-up page,
    # no first-run wizard and no bootstrap admin: an instance whose
    # users_database.yml was never written is an instance nobody can log in to,
    # and the file backend's own template ships the disabled account
    # `authelia` / `authelia`, which is a published password.
    ADMIN_PASSWORD=$(sed -n 's/^PA_AUTHELIA_ADMIN_PASSWORD=//p' .env)
    cat > .panelalpha-admin-password <<EOF
username: admin
password: ${ADMIN_PASSWORD}
EOF
    chmod 600 .panelalpha-admin-password
fi

# 3. The one image the override adds, fetched before the build rather than
# during it. `docker compose up -d` pulls what it lacks through the account's
# nested daemon while it builds what it lacks too, and the host cache the
# engine seeds from is filled from the *generated* compose file, which knows
# nothing about services an override added. Best effort.
docker pull alpine:3 >/dev/null 2>&1 || true
